param(
    [int]$Phase0Port = 55478,
    [int]$Phase1Port = 55479,
    [int]$Phase2Port = 55480
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '../../../..')).Path
$rootPhp = $root.Replace('\', '/')
$tag = 'clean-phase-support-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
$evidence = Join-Path $root 'docs/release/evidence/final-20261030'
$qaPhase2 = Join-Path $root 'docs/qa/evidence/phase2-projects-browser'
$pgBin = Join-Path $root '.tools/postgresql/pgsql/bin'
$envFile = Join-Path $root '.env.testing'
$envCacheRoot = Join-Path $root ('.tmp/' + $tag + '/cache')
$envStorageRoot = Join-Path $root ('.tmp/' + $tag + '/storage')
$envViews = Join-Path $root ('.tmp/' + $tag + '/views')
$envDatabase = 'erp_elec_new_test'
$pgUser = 'erp_app'
$appDbPassword = ''
$ports = @($Phase0Port, $Phase1Port, $Phase2Port)
if (($ports | Select-Object -Unique).Count -ne 3) { throw 'Each clean phase check requires its own unique PostgreSQL port.' }

function Get-DotEnvValue([string]$Name) {
    $line = Get-Content -LiteralPath $envFile | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') } | Select-Object -Last 1
    if (-not $line) { return '' }
    return $line.Substring($Name.Length + 1).Trim().Trim('"').Trim("'")
}

function Invoke-LoggedProcess([string]$Name, [string]$File, [string[]]$Arguments, [string]$StdoutPath) {
    $stderrPath = $StdoutPath + '.stderr'
    $process = Start-Process -FilePath $File -ArgumentList $Arguments -PassThru -Wait -NoNewWindow -RedirectStandardOutput $StdoutPath -RedirectStandardError $stderrPath
    $lines = @((Get-Content -LiteralPath $StdoutPath -ErrorAction SilentlyContinue) + (Get-Content -LiteralPath $stderrPath -ErrorAction SilentlyContinue))
    $exitCode = $process.ExitCode
    if ($lines -match 'RuntimeException|ERROR:|all_pass.{0,8}false|In [^ ]+\.php line') { $exitCode = 1 }
    [pscustomobject]@{ name=$Name; exit_code=$exitCode; stdout=$StdoutPath; stderr=$stderrPath; lines=$lines }
}

function Start-CleanDatabase([int]$Port, [string]$Name) {
    $auditRoot = Join-Path $root ('.tmp/' + $tag + '-' + $Name)
    $pgData = Join-Path $auditRoot 'pgdata'
    $localStorage = Join-Path $auditRoot 'storage'
    $localCache = Join-Path $auditRoot 'cache'
    $localViews = Join-Path $auditRoot 'views'
    $null = New-Item -ItemType Directory -Force -Path $auditRoot, $localStorage, $localCache, $localViews
    $readyFile = Join-Path $pgBin 'pg_isready.exe'
    $ready = & $readyFile -h 127.0.0.1 -p $Port 2>$null | Out-String
    if ($ready -match 'accepting connections') { throw "Port $Port is already occupied; refusing to use it." }
    & (Join-Path $pgBin 'initdb.exe') -D $pgData -U $pgUser --encoding=UTF8 --locale=C --auth-local=trust --auth-host=trust *> $null
    if ($LASTEXITCODE -ne 0) { throw "initdb failed for $Name ($LASTEXITCODE)." }
    Add-Content -LiteralPath (Join-Path $pgData 'postgresql.conf') -Value "`nlisten_addresses = '127.0.0.1'`nport = $Port`nmax_connections = 80`n"
    & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData -l (Join-Path $auditRoot 'postgres.log') -w start *> $null
    if ($LASTEXITCODE -ne 0) { throw "pg_ctl start failed for $Name ($LASTEXITCODE)." }
    $dbCreated = $false
    try {
        & (Join-Path $pgBin 'createdb.exe') -h 127.0.0.1 -p $Port -U $pgUser $envDatabase *> $null
        if ($LASTEXITCODE -ne 0) { throw "createdb failed for $Name ($LASTEXITCODE)." }
        $env:APP_ENV='testing'; $env:DB_CONNECTION='pgsql'; $env:DB_HOST='127.0.0.1'
        $env:DB_PORT=[string]$Port; $env:DB_DATABASE=$envDatabase; $env:DB_USERNAME=$pgUser
        $env:DB_PASSWORD=$appDbPassword; $env:DB_URL=''; $env:LARAVEL_STORAGE_PATH=$localStorage
        $env:VIEW_COMPILED_PATH=$localViews; $env:APP_CONFIG_CACHE=Join-Path $localCache 'config.php'; $env:APP_ROUTES_CACHE=Join-Path $localCache 'routes.php'
        $env:SESSION_DRIVER='array'; $env:CACHE_STORE='array'; $env:QUEUE_CONNECTION='sync'; $env:LOG_CHANNEL='stderr'
        $migrationLog = Join-Path $evidence ($tag + '-' + $Name + '-migrate.txt')
        $migration = Invoke-LoggedProcess ($Name + '-migrate') (Get-Command php).Source @('-d','opcache.enable_cli=0','artisan','migrate','--env=testing','--force') $migrationLog
        if ($migration.exit_code -ne 0) { throw "Migration failed for $Name; see $migrationLog" }
        $identity = & (Join-Path $pgBin 'psql.exe') -h 127.0.0.1 -p $Port -U $pgUser -d $envDatabase -At -c "select current_database() || '|' || inet_server_port()"
        if ($LASTEXITCODE -ne 0 -or $identity.Trim() -ne "$envDatabase|$Port") { throw "Clean database guard failed for $Name." }
        $dbCreated = $true
        return [pscustomobject]@{ root=$auditRoot; data=$pgData; port=$Port; name=$Name; started=$true }
    } catch {
        & (Join-Path $pgBin 'pg_ctl.exe') -D $pgData -m fast -w stop *> $null
        throw
    }
}

function Stop-CleanDatabase($Database) {
    if (!$Database -or !$Database.started) { return }
    $pidFile = Join-Path $Database.data 'postmaster.pid'
    if (!(Test-Path $pidFile)) { return }
    $pidLines = Get-Content $pidFile
    $expectedPort = [int]$pidLines[3]
    $serverPid = [int]$pidLines[0]
    if ($expectedPort -ne [int]$Database.port) { throw "Refusing stop: port mismatch for $($Database.name)." }
    $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$serverPid" -ErrorAction SilentlyContinue
    if (!$proc -or $proc.CommandLine -notlike "*$($Database.name)*pgdata*") { throw "Refusing stop: exact process/data directory not verified for $($Database.name)." }
    & (Join-Path $pgBin 'pg_ctl.exe') -D $Database.data status *> $null
    if ($LASTEXITCODE -eq 0) {
        & (Join-Path $pgBin 'pg_ctl.exe') -D $Database.data -m fast -w stop
        if ($LASTEXITCODE -ne 0) { throw "Failed to stop owned clean database $($Database.name)." }
    }
}

New-Item -ItemType Directory -Force -Path $evidence, $qaPhase2 | Out-Null
$appDbPassword = Get-DotEnvValue 'DB_PASSWORD'
$oldPassword = $env:PGPASSWORD
$env:PGPASSWORD = ''
$results = @()
$db = $null
try {
    $db = Start-CleanDatabase $Phase0Port 'phase0'
    $env:DB_PORT=[string]$Phase0Port
    $phase0Seed = Get-Content (Join-Path $root 'tests/Support/check-phase0-recovery-seed.php') -Raw
    $seedEvidencePhp = (Join-Path $evidence ($tag + '-phase0-recovery-seed.json')).Replace('\','/')
    $phase0Seed = $phase0Seed.Replace("dirname(__DIR__, 2).'/docs/qa/evidence/phase0-recovery-seed.json'", "'$seedEvidencePhp'")
    $phase0Seed = $phase0Seed.Replace('dirname(__DIR__, 2)', "'$rootPhp'").Replace('55432', [string]$Phase0Port)
    $seedCopy = Join-Path $root ('.tmp/' + $tag + '-phase0/phase0-recovery-seed.php')
    New-Item -ItemType Directory -Force -Path (Split-Path $seedCopy) | Out-Null
    Set-Content -LiteralPath $seedCopy -Value $phase0Seed -Encoding utf8
    $seedLog = Join-Path $evidence ($tag + '-phase0-recovery-seed.txt')
    $results += Invoke-LoggedProcess 'phase0-recovery-seed' (Get-Command php).Source @('-d','opcache.enable_cli=0',$seedCopy) $seedLog
    $phase0Core = Get-Content (Join-Path $root 'tests/Support/check-phase0-financial-core.php') -Raw
    $coreStoragePhp = (Join-Path $db.root 'phase0-storage').Replace('\','/')
    $coreStagePhp = (Join-Path $db.root 'phase0-core-stage').Replace('\','/')
    $coreEvidencePhp = (Join-Path $evidence ($tag + '-phase0-financial-core.json')).Replace('\','/')
    $phase0Core = $phase0Core.Replace("`$root.'/.tmp/phase0-recovery-20261027/restore-storage'", "'$coreStoragePhp'")
        .Replace("`$root.'/.tmp/phase0-core-stage'", "'$coreStagePhp'")
        .Replace("`$root.'/docs/qa/evidence/phase0-financial-core.json'", "'$coreEvidencePhp'")
    $phase0Core = $phase0Core.Replace('dirname(__DIR__, 2)', "'$rootPhp'").Replace('55433', [string]$Phase0Port)
    $phase0Copy = Join-Path $root ('.tmp/' + $tag + '-phase0/phase0-financial-core.php')
    Set-Content -LiteralPath $phase0Copy -Value $phase0Core -Encoding utf8
    $results += Invoke-LoggedProcess 'phase0-financial-core' (Get-Command php).Source @('-d','opcache.enable_cli=0',$phase0Copy) (Join-Path $evidence ($tag + '-phase0-financial-core.txt'))
} finally {
    Stop-CleanDatabase $db
}

try {
    $db = Start-CleanDatabase $Phase1Port 'phase1'
    $phase1 = Get-Content (Join-Path $root 'tests/Support/check-phase1-financial-journeys.php') -Raw
    $phase1StoragePhp = (Join-Path $db.root 'storage').Replace('\','/')
    $phase1EvidencePhp = (Join-Path $evidence ($tag + '-phase1-financial-journeys.json')).Replace('\','/')
    $phase1 = $phase1.Replace("`$root.'/.tmp/phase1-supplier-invoice-storage'", "'$phase1StoragePhp'")
        .Replace("`$root.'/docs/qa/evidence/phase1-financial-journeys.json'", "'$phase1EvidencePhp'")
        .Replace('dirname(__DIR__, 2)', "'$rootPhp'")
    $phase1Copy = Join-Path $root ('.tmp/' + $tag + '-phase1/phase1-financial-journeys.php')
    New-Item -ItemType Directory -Force -Path (Split-Path $phase1Copy) | Out-Null
    Set-Content -LiteralPath $phase1Copy -Value $phase1 -Encoding utf8
    $env:PHASE1_COMMIT_RACE_FIXTURES='0'
    $results += Invoke-LoggedProcess 'phase1-financial-journeys' (Get-Command php).Source @('-d','opcache.enable_cli=0',$phase1Copy) (Join-Path $evidence ($tag + '-phase1-financial-journeys.txt'))
} finally {
    Stop-CleanDatabase $db
}

try {
    $db = Start-CleanDatabase $Phase2Port 'phase2'
    $env:PHASE2_COMMIT_FIXTURES='0'; $env:PHASE2_STORAGE_ROOT=Join-Path $db.root 'storage'
    $env:PHASE2_EVIDENCE_PATH=Join-Path $qaPhase2 ($tag + '-phase2-projects-financial.json')
    $phase2 = Get-Content (Join-Path $root 'tests/Support/check-phase2-projects-financial.php') -Raw
    $phase2 = $phase2.Replace('dirname(__DIR__, 2)', "'$rootPhp'")
    $phase2Copy = Join-Path $root ('.tmp/' + $tag + '-phase2/phase2-projects-financial.php')
    New-Item -ItemType Directory -Force -Path (Split-Path $phase2Copy) | Out-Null
    Set-Content -LiteralPath $phase2Copy -Value $phase2 -Encoding utf8
    $results += Invoke-LoggedProcess 'phase2-projects-financial' (Get-Command php).Source @('-d','opcache.enable_cli=0',$phase2Copy) (Join-Path $evidence ($tag + '-phase2-projects-financial.txt'))
    if (Test-Path $env:PHASE2_EVIDENCE_PATH) {
        Copy-Item -LiteralPath $env:PHASE2_EVIDENCE_PATH -Destination (Join-Path $evidence ($tag + '-phase2-projects-financial.json'))
    }
} finally {
    Stop-CleanDatabase $db
}

$summaryPath = Join-Path $evidence ($tag + '-summary.json')
$results | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $summaryPath -Encoding utf8
Write-Output "Clean-schema support results: $summaryPath"
$results | ForEach-Object { Write-Output ("--- " + $_.name + " exit=" + $_.exit_code); $_.lines | Write-Output }
$results | Format-Table name,exit_code,stdout -AutoSize | Out-String | Write-Output
if ($oldPassword -eq $null) { Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue } else { $env:PGPASSWORD=$oldPassword }
if ($results | Where-Object exit_code -ne 0) { exit 1 }
