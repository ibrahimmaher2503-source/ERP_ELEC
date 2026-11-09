param(
    [int]$SourcePort = 55448,
    [int]$TargetPort = 55476,
    [string]$SourceLogin = 'postgres',
    [string[]]$OnlyChecks = @()
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '../../../..')).Path
$tag = 'phase-support-audit-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
$auditRoot = Join-Path $root ('.tmp/' + $tag)
$evidenceRoot = Join-Path $root 'docs/release/evidence/final-20261030'
$pgBin = Join-Path $root '.tools/postgresql/pgsql/bin'
$pgData = Join-Path $auditRoot 'pgdata'
$dump = Join-Path $auditRoot 'source.dump'
$scriptRoot = Join-Path $auditRoot 'tests/Support'
$envPath = Join-Path $root '.env.testing'
New-Item -ItemType Directory -Force -Path $auditRoot, $scriptRoot, $evidenceRoot | Out-Null

function Get-DotEnvValue([string]$Name) {
    $line = Get-Content -LiteralPath $envPath | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') } | Select-Object -Last 1
    if (-not $line) { return '' }
    return $line.Substring($Name.Length + 1).Trim().Trim('"').Trim("'")
}

$sourceDb = Get-DotEnvValue 'DB_DATABASE'
$sourceUser = Get-DotEnvValue 'DB_USERNAME'
$sourcePassword = Get-DotEnvValue 'DB_PASSWORD'
if ($sourceDb -ne 'erp_elec_new_test' -or [string]::IsNullOrWhiteSpace($sourceUser)) { throw 'Refusing clone: .env.testing must name erp_elec_new_test and a database user.' }
$pgReady = Join-Path $pgBin 'pg_isready.exe'
$psql = Join-Path $pgBin 'psql.exe'
$pgDump = Join-Path $pgBin 'pg_dump.exe'
$initdb = Join-Path $pgBin 'initdb.exe'
$pgCtl = Join-Path $pgBin 'pg_ctl.exe'
$createdb = Join-Path $pgBin 'createdb.exe'
$pgRestore = Join-Path $pgBin 'pg_restore.exe'
$sourceReady = & $pgReady -h 127.0.0.1 -p $SourcePort 2>$null | Out-String
$targetReady = & $pgReady -h 127.0.0.1 -p $TargetPort 2>$null | Out-String
if ($sourceReady -notmatch 'accepting connections') { throw "Source PostgreSQL $SourcePort is not accepting connections." }
if ($targetReady -match 'accepting connections') { throw "Target port $TargetPort is already occupied." }

$env:PGPASSWORD = $sourcePassword
$env:PGCLIENTENCODING = 'UTF8'
$serverStarted = $false
try {
    $identity = & $psql -h 127.0.0.1 -p $SourcePort -U $SourceLogin -d $sourceDb -At -c "select current_database() || '|' || inet_server_port() || '|' || current_setting('server_version')"
    if ($LASTEXITCODE -ne 0 -or $identity.Trim() -ne "$sourceDb|$SourcePort|17.11") { throw 'Refusing source: expected the isolated PostgreSQL 17.11 test database.' }
    & $pgDump -h 127.0.0.1 -p $SourcePort -U $SourceLogin -d $sourceDb -Fc -f $dump
    if ($LASTEXITCODE -ne 0) { throw "pg_dump failed ($LASTEXITCODE)." }
    & $initdb -D $pgData -U $sourceUser --encoding=UTF8 --locale=C --auth-local=trust --auth-host=trust
    if ($LASTEXITCODE -ne 0) { throw "initdb failed ($LASTEXITCODE)." }
    Add-Content -LiteralPath (Join-Path $pgData 'postgresql.conf') -Value "`nlisten_addresses = '127.0.0.1'`nport = $TargetPort`nmax_connections = 80`n"
    & $pgCtl -D $pgData -l (Join-Path $auditRoot 'postgres.log') -w start
    if ($LASTEXITCODE -ne 0) { throw "pg_ctl start failed ($LASTEXITCODE)." }
    $serverStarted = $true
    & (Join-Path $pgBin 'createuser.exe') -h 127.0.0.1 -p $TargetPort -U $sourceUser -s postgres
    if ($LASTEXITCODE -ne 0) { throw "Could not create the postgres compatibility role in the isolated clone ($LASTEXITCODE)." }
    & $createdb -h 127.0.0.1 -p $TargetPort -U $sourceUser $sourceDb
    if ($LASTEXITCODE -ne 0) { throw "createdb failed ($LASTEXITCODE)." }
    & $pgRestore -h 127.0.0.1 -p $TargetPort -U $sourceUser -d $sourceDb --no-owner --exit-on-error $dump
    if ($LASTEXITCODE -ne 0) { throw "pg_restore failed ($LASTEXITCODE)." }

    $env:APP_ENV = 'testing'; $env:DB_CONNECTION = 'pgsql'; $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = [string]$TargetPort; $env:DB_DATABASE = $sourceDb; $env:DB_USERNAME = $sourceUser
    $env:DB_PASSWORD = ''; $env:DB_URL = ''; $env:SESSION_DRIVER = 'array'; $env:CACHE_STORE = 'array'
    $env:QUEUE_CONNECTION = 'sync'; $env:LOG_CHANNEL = 'stderr'; $env:PHASE3_FINANCIAL_PORT = [string]$TargetPort
    $env:PHASE3_FINANCIAL_COMMIT_FIXTURES = '0'; $env:PHASE1_COMMIT_RACE_FIXTURES = '0'
    $env:PHASE2_COMMIT_FIXTURES = '0'; $env:PHASE2_STORAGE_ROOT = Join-Path $auditRoot 'phase2-storage'
    $phase2EvidenceDir = Join-Path $root 'docs/qa/evidence/phase2-projects-browser'
    New-Item -ItemType Directory -Force -Path $phase2EvidenceDir | Out-Null
    $env:PHASE2_EVIDENCE_PATH = Join-Path $phase2EvidenceDir ($tag + '-phase2-projects-financial.json')

    $rootPhp = $root.Replace('\', '/')
    $cases = @(
        @{ Name='phase0-financial-core'; Source='tests/Support/check-phase0-financial-core.php'; Evidence='phase0-financial-core.json'; RewritePort=$true },
        @{ Name='phase1-financial-journeys'; Source='tests/Support/check-phase1-financial-journeys.php'; Evidence='phase1-financial-journeys.json' },
        @{ Name='phase2-projects-financial'; Source='tests/Support/check-phase2-projects-financial.php'; Evidence=$null },
        @{ Name='phase3-maintenance-financial'; Source='tests/Support/check-phase3-maintenance-financial.php'; Evidence='phase3-maintenance-financial.json'; RewritePort=$true }
    )
    if ($OnlyChecks.Count -gt 0) { $cases = @($cases | Where-Object { $_.Name -in $OnlyChecks }) }
    $summary = @()
    foreach ($case in $cases) {
        $sourcePath = Join-Path $root $case.Source
        $content = Get-Content -LiteralPath $sourcePath -Raw
        $content = $content.Replace('dirname(__DIR__, 2)', "'$rootPhp'")
        if ($case.RewritePort) {
            $content = $content.Replace('55433', [string]$TargetPort).Replace('55441', [string]$TargetPort).Replace('55439', [string]$TargetPort)
            $content = $content.Replace("['$TargetPort', '$TargetPort']", "['$TargetPort']")
        }
        if ($case.Name -eq 'phase1-financial-journeys') {
            $content = $content.Replace("$rootPhp.'/.tmp/phase1-supplier-invoice-storage'", "'$($auditRoot.Replace('\','/'))/phase1-storage'")
        }
        if ($case.Evidence) {
            $dest = (Join-Path $evidenceRoot ($tag + '-' + $case.Evidence)).Replace('\','/')
            $content = $content.Replace("`$root.'/docs/qa/evidence/$($case.Evidence)'", "'$dest'")
        }
        $copy = Join-Path $scriptRoot ($case.Name + '.php')
        Set-Content -LiteralPath $copy -Value $content -Encoding utf8
        $log = Join-Path $evidenceRoot ($tag + '-' + $case.Name + '.txt')
        $started = Get-Date
        $phpPath = (Get-Command php).Source
        $process = Start-Process -FilePath $phpPath -ArgumentList @('-d', 'opcache.enable_cli=0', $copy) -PassThru -Wait -NoNewWindow -RedirectStandardOutput $log -RedirectStandardError ($log + '.stderr')
        $exit = $process.ExitCode
        $combined = @((Get-Content -LiteralPath $log -ErrorAction SilentlyContinue) + (Get-Content -LiteralPath ($log + '.stderr') -ErrorAction SilentlyContinue))
        $combined | Write-Output
        if ($combined -match 'RuntimeException|ERROR:|all_pass.{0,8}false|In [^ ]+\.php line') { $exit = 1 }
        $summary += [pscustomobject]@{ name=$case.Name; exit_code=$exit; seconds=[math]::Round(((Get-Date)-$started).TotalSeconds,2); log=$log }
        if ($exit -ne 0) { Write-Output "Check failed: $($case.Name). Continuing to capture independent checks." }
    }
    $summary | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidenceRoot ($tag + '-summary.json')) -Encoding utf8
    Write-Output "Support clone: $auditRoot"
    Write-Output "Receipts: $evidenceRoot"
    $summary | Format-Table -AutoSize | Out-String | Write-Output
} finally {
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    if ($serverStarted) { & $pgCtl -D $pgData -m fast -w stop | Out-Null }
}
