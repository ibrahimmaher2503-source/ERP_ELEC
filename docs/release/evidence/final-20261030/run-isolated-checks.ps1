param(
    [int]$SourcePort = 55448,
    [int]$TargetPort = 55460,
    [string]$SourceLogin = 'postgres',
    [switch]$UseExistingClone,
    [switch]$LaravelOnly
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '../../../..')).Path
$tag = 'final-audit-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
$auditRoot = Join-Path $root ('.tmp/' + $tag)
$evidence = Join-Path $root 'docs/release/evidence/final-20261030'
$pgBin = Join-Path $root '.tools/postgresql/pgsql/bin'
$pgData = Join-Path $auditRoot 'pgdata'
$dump = Join-Path $auditRoot 'source.dump'
$storage = Join-Path $auditRoot 'storage'
$views = Join-Path $auditRoot 'compiled-views'
$cache = Join-Path $auditRoot 'cache'
$envPath = Join-Path $root '.env.testing'

function Get-DotEnvValue([string]$Name) {
    $line = Get-Content -LiteralPath $envPath | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') } | Select-Object -Last 1
    if (-not $line) { return '' }
    return $line.Substring($Name.Length + 1).Trim().Trim('"').Trim("'")
}

if (-not (Test-Path (Join-Path $pgBin 'initdb.exe'))) { throw 'Bundled PostgreSQL initdb.exe is missing.' }
$sourceDb = Get-DotEnvValue 'DB_DATABASE'
$sourceUser = Get-DotEnvValue 'DB_USERNAME'
$sourcePassword = Get-DotEnvValue 'DB_PASSWORD'
if ($sourceDb -ne 'erp_elec_new_test' -or $sourceUser -eq '') { throw 'Refusing clone: .env.testing must name erp_elec_new_test and a database user.' }
$sourceReady = & (Join-Path $pgBin 'pg_isready.exe') -h 127.0.0.1 -p $SourcePort 2>$null | Out-String
$targetReady = & (Join-Path $pgBin 'pg_isready.exe') -h 127.0.0.1 -p $TargetPort 2>$null | Out-String
if ($UseExistingClone) {
    if ($targetReady -notmatch 'accepting connections') { throw "Requested existing clone has no ready PostgreSQL server on $TargetPort." }
} elseif ($targetReady -match 'accepting connections') {
    throw "Target port $TargetPort is already occupied."
}
New-Item -ItemType Directory -Force -Path $auditRoot, $evidence | Out-Null

$env:PGPASSWORD = $sourcePassword
$env:PGCLIENTENCODING = 'UTF8'
$serverStarted = $false
try {
    if ($UseExistingClone) {
        $probe = & (Join-Path $pgBin 'psql.exe') -h 127.0.0.1 -p $TargetPort -U $sourceUser -d $sourceDb -At -c "select current_database() || '|' || inet_server_port()" 2>$null
        if ($LASTEXITCODE -ne 0 -or $probe.Trim() -ne ($sourceDb + '|' + [string]$TargetPort)) { throw 'Refusing existing target: it is not the expected PostgreSQL test database at the requested port.' }
    } else {
        if ($sourceReady -notmatch 'accepting connections') { throw "Source PostgreSQL at 127.0.0.1:$SourcePort is not accepting connections." }
        $sourceIdentity = & (Join-Path $pgBin 'psql.exe') -h 127.0.0.1 -p $SourcePort -U $SourceLogin -d $sourceDb -At -c "select current_database() || '|' || inet_server_port() || '|' || current_setting('server_version')"
        if ($LASTEXITCODE -ne 0 -or $sourceIdentity.Trim() -ne "$sourceDb|$SourcePort|17.11" -or $sourceDb -ne 'erp_elec_new_test') { throw 'Refusing clone: source is not the expected isolated PostgreSQL 17.11 testing database.' }
        & (Join-Path $pgBin 'pg_dump.exe') -h 127.0.0.1 -p $SourcePort -U $SourceLogin -d $sourceDb -Fc -f $dump
        if ($LASTEXITCODE -ne 0) { throw "pg_dump failed with exit code $LASTEXITCODE; confirm source is the isolated testing database." }
        & (Join-Path $pgBin 'initdb.exe') -D $pgData -U $sourceUser --encoding=UTF8 --locale=C --auth-local=trust --auth-host=trust
        if ($LASTEXITCODE -ne 0) { throw "initdb failed with exit code $LASTEXITCODE." }
        $conf = Join-Path $pgData 'postgresql.conf'
        Add-Content -LiteralPath $conf -Value "`nlisten_addresses = '127.0.0.1'`nport = $TargetPort`nmax_connections = 80`n"
        & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData -l (Join-Path $auditRoot 'postgres.log') -w start
        if ($LASTEXITCODE -ne 0) { throw "pg_ctl start failed with exit code $LASTEXITCODE." }
        $serverStarted = $true
        & (Join-Path $pgBin 'createdb.exe') -h 127.0.0.1 -p $TargetPort -U $sourceUser $sourceDb
        if ($LASTEXITCODE -ne 0) { throw "createdb failed with exit code $LASTEXITCODE." }
        & (Join-Path $pgBin 'pg_restore.exe') -h 127.0.0.1 -p $TargetPort -U $sourceUser -d $sourceDb --no-owner --exit-on-error $dump
        if ($LASTEXITCODE -ne 0) { throw "pg_restore failed with exit code $LASTEXITCODE." }
    }

    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = [string]$TargetPort
    $env:DB_DATABASE = $sourceDb
    $env:DB_USERNAME = $sourceUser
    $env:DB_PASSWORD = $sourcePassword
    $env:DB_URL = ''
    New-Item -ItemType Directory -Force -Path $storage, $views, $cache,
        (Join-Path $storage 'app/private'), (Join-Path $storage 'framework/cache/data'),
        (Join-Path $storage 'framework/sessions'), (Join-Path $storage 'framework/views'),
        (Join-Path $storage 'framework/testing'), (Join-Path $storage 'framework/livewire-tmp'),
        (Join-Path $storage 'logs') | Out-Null
    $env:LARAVEL_STORAGE_PATH = $storage
    $env:VIEW_COMPILED_PATH = $views
    $env:APP_CONFIG_CACHE = Join-Path $cache 'config.php'
    $env:APP_ROUTES_CACHE = Join-Path $cache 'routes.php'
    $env:SESSION_DRIVER = 'file'
    $env:CACHE_STORE = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:LOG_CHANNEL = 'stderr'
    & php -d opcache.enable_cli=0 artisan migrate --env=testing --force 2>&1 | Tee-Object -FilePath (Join-Path $evidence ($tag + '-migrate.txt'))
    if ($LASTEXITCODE -ne 0) { throw "Isolated clone migration failed with exit code $LASTEXITCODE." }
    $started = Get-Date
    & php -d opcache.enable_cli=0 artisan test --env=testing 2>&1 | Tee-Object -FilePath (Join-Path $evidence ($tag + '-laravel-suite.txt'))
    $exit = $LASTEXITCODE
    $result = [pscustomobject]@{ check='laravel-suite'; exit_code=$exit; elapsed_seconds=[math]::Round(((Get-Date)-$started).TotalSeconds,2); port=$TargetPort; database=$sourceDb }
    $result | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $evidence ($tag + '-laravel-suite.json')) -Encoding utf8
    $beforeSafe = @(Get-ChildItem -LiteralPath (Join-Path $root 'docs/qa/evidence') -Filter 'safe-check-run-*.json' -File -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName)
    $aggregateExit = 0
    if (-not $LaravelOnly) {
        $started = Get-Date
        & php -d opcache.enable_cli=0 .tools/run-safe-qa-checks.php 2>&1 | Tee-Object -FilePath (Join-Path $evidence ($tag + '-safe-aggregate.txt'))
        $aggregateExit = $LASTEXITCODE
        $aggregateResult = [pscustomobject]@{ check='safe-aggregate'; exit_code=$aggregateExit; elapsed_seconds=[math]::Round(((Get-Date)-$started).TotalSeconds,2); port=$TargetPort; database=$sourceDb }
        $aggregateResult | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $evidence ($tag + '-safe-aggregate.json')) -Encoding utf8
        $qaEvidence = Join-Path $root 'docs/qa/evidence'
        $newSafeRuns = Get-ChildItem -LiteralPath $qaEvidence -Filter 'safe-check-run-*.json' -File -ErrorAction SilentlyContinue | Where-Object { $_.FullName -notin $beforeSafe }
        foreach ($receipt in $newSafeRuns) { Copy-Item -LiteralPath $receipt.FullName -Destination (Join-Path $evidence ($tag + '-' + $receipt.Name)) -Force }
    }
    Write-Output "Suite exit code $exit. Unique clone retained at $auditRoot; receipts under $evidence."
    Write-Output "Aggregate exit code $aggregateExit."
    if ($exit -ne 0 -or $aggregateExit -ne 0) { exit 1 }
} finally {
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    if ($serverStarted) { & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData -m fast -w stop | Out-Null }
}
