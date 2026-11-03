param([switch] $Provision, [switch] $Resume)
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$master = Join-Path $root '.tmp/master-remediation-20261101'
$cluster = Join-Path $master 'ui-pgdata'
$privateFiles = Join-Path $master 'ui-private'
$markerPath = Join-Path $privateFiles '.codex-ui-owned.txt'
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
$port = 49686
$database = 'erp_elec_new_test'
$bin = Join-Path $root '.tools/postgresql/pgsql/bin'
$initdb = Join-Path $bin 'initdb.exe'
$pgCtl = Join-Path $bin 'pg_ctl.exe'
$psql = Join-Path $bin 'psql.exe'
$createdb = Join-Path $bin 'createdb.exe'
$logs = Join-Path $privateFiles 'logs'

function Invoke-PrivateTool([string] $File, [string[]] $Arguments, [string] $Name) {
    $run = [guid]::NewGuid().ToString('N')
    $out = Join-Path $logs "$Name-$run.stdout.log"
    $err = Join-Path $logs "$Name-$run.stderr.log"
    $proc = Start-Process -FilePath $File -ArgumentList $Arguments -WindowStyle Hidden -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
    $null = $proc.Handle
    $proc.WaitForExit()
    $proc.Refresh()
    $exitCode = $proc.ExitCode
    if ($null -eq $exitCode) { throw "$Name exited, but its exact process exit code could not be read; inspect private logs under $logs." }
    if ($exitCode -ne 0) { throw "$Name failed (exit $exitCode); inspect private logs under $logs." }
}

$resolvedRoot = (Resolve-Path -LiteralPath $root).Path.TrimEnd('\')
$resolvedMaster = (Resolve-Path -LiteralPath $master).Path.TrimEnd('\')
if ($Provision -and $Resume) { throw 'Choose either -Provision for a fresh cluster or -Resume for an owned live cluster.' }
if (-not $resolvedMaster.StartsWith($resolvedRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Refusing a PostgreSQL target outside this checkout.'
}
if (-not (Test-Path -LiteralPath $bin -PathType Container)) { throw "PostgreSQL 17 tools are missing from $bin." }
foreach ($tool in @($initdb, $pgCtl, $psql, $createdb)) {
    if (-not (Test-Path -LiteralPath $tool -PathType Leaf)) { throw "Required PostgreSQL tool is missing: $tool" }
}
if ($Resume) {
    if (-not (Test-Path -LiteralPath $cluster -PathType Container) -or -not (Test-Path -LiteralPath $privateFiles -PathType Container) -or
        -not (Test-Path -LiteralPath $markerPath -PathType Leaf) -or (Get-Content -LiteralPath $markerPath -Raw).Trim() -cne $marker -or
        -not (Test-Path -LiteralPath (Join-Path $cluster 'PG_VERSION') -PathType Leaf)) {
        throw 'Resume requires the existing PGDATA, PG_VERSION, private directory, and exact ownership marker; no changes made.'
    }
    $resolvedCluster = (Resolve-Path -LiteralPath $cluster).Path.TrimEnd('\')
    $resolvedPrivateFiles = (Resolve-Path -LiteralPath $privateFiles).Path.TrimEnd('\')
    if (-not $resolvedCluster.Equals((Join-Path $resolvedMaster 'ui-pgdata'), [StringComparison]::OrdinalIgnoreCase) -or
        -not $resolvedPrivateFiles.Equals((Join-Path $resolvedMaster 'ui-private'), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Resolved owned PostgreSQL paths do not match the exact private workspace targets.'
    }
    if ((Get-Content -LiteralPath (Join-Path $cluster 'PG_VERSION') -Raw).Trim() -ne '17') { throw 'Resume requires PostgreSQL 17 PGDATA.' }
    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue | Where-Object LocalAddress -eq '127.0.0.1')
    if ($listeners.Count -ne 1) { throw "Resume requires exactly one loopback listener on port $port; no process was started or stopped." }
    if (-not (Test-Path -LiteralPath $logs -PathType Container)) { throw 'Resume requires the existing private logs directory; no files changed.' }
    $identity = & $psql -X -h 127.0.0.1 -p $port -U postgres -d postgres -Atc "select current_user || '|' || current_setting('port') || '|' || current_setting('data_directory') || '|' || current_setting('server_version_num')"
    $identityParts = "$identity".Split('|')
    $reportedDirectory = if ($identityParts.Count -eq 4) { [IO.Path]::GetFullPath($identityParts[2].Replace('/', '\')).TrimEnd('\') } else { '' }
    if ($LASTEXITCODE -ne 0 -or $identityParts.Count -ne 4 -or $identityParts[0] -ne 'postgres' -or [int]$identityParts[1] -ne $port -or
        $reportedDirectory -ne $resolvedCluster -or [int]$identityParts[3] -lt 170000 -or [int]$identityParts[3] -ge 180000) {
        throw 'Live PostgreSQL identity did not match the exact owned PGDATA, role, port, and version; no database was changed.'
    }
    $existing = & $psql -X -h 127.0.0.1 -p $port -U postgres -d postgres -Atc "select 1 from pg_database where datname = '$database'"
    if ($LASTEXITCODE -ne 0 -or $existing) { throw 'Target database already exists or database preflight failed; no database was changed.' }
    Invoke-PrivateTool $createdb @('-h', '127.0.0.1', '-p', "$port", '-U', 'postgres', $database) 'createdb'
    $dbIdentity = & $psql -X -h 127.0.0.1 -p $port -U postgres -d $database -Atc "select current_database() || '|' || current_user || '|' || current_setting('port') || '|' || current_setting('data_directory')"
    $dbParts = "$dbIdentity".Split('|')
    $dbDirectory = if ($dbParts.Count -eq 4) { [IO.Path]::GetFullPath($dbParts[3].Replace('/', '\')).TrimEnd('\') } else { '' }
    if ($LASTEXITCODE -ne 0 -or $dbParts.Count -ne 4 -or $dbParts[0] -ne $database -or $dbParts[1] -ne 'postgres' -or
        [int]$dbParts[2] -ne $port -or $dbDirectory -ne $resolvedCluster) {
        throw 'Created database identity did not match the owned cluster.'
    }
    Write-Output "READY: resumed owned PostgreSQL 17, loopback port $port, database $database, role postgres. Existing cluster and data were preserved. Private logs are under $logs."
    exit 0
}
if (Test-Path -LiteralPath $cluster) {
    if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf) -or (Get-Content -LiteralPath $markerPath -Raw).Trim() -cne $marker) {
        throw 'The target path already exists without the exact ownership marker; no changes made.'
    }
    throw 'The marked target already exists. Use -Resume only after inspecting the live identity and confirming the target database is absent.'
}
if (Test-Path -LiteralPath $privateFiles) { throw 'The private UI evidence path already exists; refusing to change its ACL or contents.' }
$listener = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue
if ($listener) { throw "Port $port is already occupied (PID $($listener[0].OwningProcess)); no process was stopped." }
if (-not $Provision) {
    Write-Output "PREFLIGHT OK: new private PostgreSQL 17 cluster at $cluster, loopback port $port, database $database. No files or services changed; rerun with -Provision after review."
    exit 0
}

New-Item -ItemType Directory -Path $cluster | Out-Null
New-Item -ItemType Directory -Path $privateFiles | Out-Null
New-Item -ItemType Directory -Path $logs | Out-Null
$resolvedCluster = (Resolve-Path -LiteralPath $cluster).Path.TrimEnd('\')
if (-not $resolvedCluster.StartsWith($resolvedMaster + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Resolved PostgreSQL cluster escaped its private workspace parent.' }
foreach ($privatePath in @($cluster, $privateFiles, $logs)) {
    $acl = Get-Acl -LiteralPath $privatePath
    $acl.SetAccessRuleProtection($true, $false)
    $owner = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    $acl.SetOwner([Security.Principal.NTAccount]::new($owner))
    foreach ($account in @($owner, 'SYSTEM', 'BUILTIN\Administrators')) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new($account, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.SetAccessRule($rule)
    }
    Set-Acl -LiteralPath $privatePath -AclObject $acl
}
Set-Content -LiteralPath $markerPath -Value $marker -NoNewline -Encoding ascii
$started = $false
try {
    Invoke-PrivateTool $initdb @('-D', $cluster, '-U', 'postgres', '-A', 'trust', '-E', 'UTF8', '--no-locale') 'initdb'
    if (-not (Test-Path -LiteralPath (Join-Path $cluster 'PG_VERSION'))) { throw 'initdb did not produce PG_VERSION.' }
    Add-Content -LiteralPath (Join-Path $cluster 'postgresql.conf') -Value "`nlisten_addresses = '127.0.0.1'`nport = $port`n" -Encoding ascii
    Set-Content -LiteralPath (Join-Path $cluster 'pg_hba.conf') -Value @('local all all trust', 'host all all 127.0.0.1/32 trust') -Encoding ascii
    $started = $true
    Invoke-PrivateTool $pgCtl @('-D', $cluster, '-l', (Join-Path $logs 'server.log'), '-w', 'start') 'start'
    $identity = & $psql -X -h 127.0.0.1 -p $port -U postgres -d postgres -Atc "select current_user || '|' || current_setting('port') || '|' || current_setting('data_directory')"
    $identityParts = "$identity".Split('|')
    $reportedDirectory = if ($identityParts.Count -eq 3) { [IO.Path]::GetFullPath($identityParts[2].Replace('/', '\')).TrimEnd('\') } else { '' }
    if ($LASTEXITCODE -ne 0 -or $identityParts.Count -ne 3 -or $identityParts[0] -ne 'postgres' -or [int]$identityParts[1] -ne $port -or $reportedDirectory -ne $resolvedCluster) {
        throw 'Live server identity did not match the owned cluster, role, and port.'
    }
    $existing = & $psql -X -h 127.0.0.1 -p $port -U postgres -d postgres -Atc "select 1 from pg_database where datname = '$database'"
    if ($LASTEXITCODE -ne 0 -or $existing) { throw 'Target database already exists or database preflight failed; no database was changed.' }
    Invoke-PrivateTool $createdb @('-h', '127.0.0.1', '-p', "$port", '-U', 'postgres', $database) 'createdb'
    $dbIdentity = & $psql -X -h 127.0.0.1 -p $port -U postgres -d $database -Atc "select current_database() || '|' || current_user || '|' || current_setting('port') || '|' || current_setting('data_directory')"
    $dbParts = "$dbIdentity".Split('|')
    $dbDirectory = if ($dbParts.Count -eq 4) { [IO.Path]::GetFullPath($dbParts[3].Replace('/', '\')).TrimEnd('\') } else { '' }
    if ($LASTEXITCODE -ne 0 -or $dbParts.Count -ne 4 -or $dbParts[0] -ne $database -or $dbParts[1] -ne 'postgres' -or [int]$dbParts[2] -ne $port -or $dbDirectory -ne $resolvedCluster) {
        throw 'Created database identity did not match the owned cluster.'
    }
    Write-Output "READY: PostgreSQL 17, loopback port $port, database $database, role postgres, marker verified. Private logs are under $logs; no password was created."
} catch {
    if ($started) {
        try { Invoke-PrivateTool $pgCtl @('-D', $cluster, '-m', 'fast', '-w', 'stop') 'stop-after-provision-error' }
        catch { Write-Warning "Owned cluster did not stop cleanly; inspect it manually. $($_.Exception.Message)" }
    }
    throw
}
