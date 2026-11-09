$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path -LiteralPath 'C:\projects\ERP_ELEC_NEW').Path
$pgBin = Join-Path $workspace '.tools\postgresql\pgsql\bin'
$sourcePort = 55481
$restorePort = 55486
$database = 'erp_elec_new_test'
$sourceData = Join-Path $workspace '.tmp\m09-audit-20261031-145402\pgdata'
$sourceStorage = Join-Path $workspace '.tmp\m09-audit-20261031-145402\storage'
$fixturePath = Join-Path $workspace '.tmp\tender-multirace-D3E561853C\fixture.json'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $workspace ".tmp\tender-backup-restore-$stamp"
$restoreData = Join-Path $backupRoot 'pgdata'
$privateRoot = Join-Path $backupRoot 'restored-storage\app\private'
$dumpPath = Join-Path $backupRoot 'erp_elec_new_test.dump'
$sourceSchema = Join-Path $backupRoot 'source-schema.sql'
$restoreSchema = Join-Path $backupRoot 'restored-schema.sql'
$sourceManifest = Join-Path $backupRoot 'source-manifest.json'
$restoreManifest = Join-Path $backupRoot 'restored-manifest.json'
$receiptPath = Join-Path $workspace "docs\release\evidence\final-20261030\tender-clone-backup-restore-$stamp.json"
$logPath = Join-Path $workspace "docs\release\evidence\final-20261030\tender-clone-backup-restore-$stamp.txt"
$env:PGPASSWORD = ''

function Invoke-Checked([string] $Exe, [string[]] $Arguments, [string] $Label) {
    $output = & $Exe @Arguments 2>&1
    $code = $LASTEXITCODE
    if ($output) { $output | ForEach-Object { Write-Output $_ } }
    if ($code -ne 0) { throw "$Label failed with exit code $code" }
}

if (-not (Test-Path -LiteralPath $sourceData -PathType Container)) { throw 'Source PGDATA path is missing.' }
if (-not (Test-Path -LiteralPath $sourceStorage -PathType Container)) { throw 'Source clone storage path is missing.' }
if (-not (Test-Path -LiteralPath $fixturePath -PathType Leaf)) { throw 'Committed real-action Tender fixture manifest is missing.' }
$sourceDataReal = (Resolve-Path -LiteralPath $sourceData).Path
if (-not $sourceDataReal.StartsWith($workspace + '\.tmp\m09-audit-20261031-145402\pgdata', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Source PGDATA resolved outside the expected disposable-clone path.'
}
$ready = Join-Path $pgBin 'pg_isready.exe'
Invoke-Checked $ready @('-h', '127.0.0.1', '-p', "$sourcePort") 'Source cluster readiness check'
$sourceIdentity = & (Join-Path $pgBin 'psql.exe') -h 127.0.0.1 -p $sourcePort -U erp_app -d $database -Atc 'select current_database()||''|''||inet_server_addr()||''|''||inet_server_port(); show data_directory;' 2>&1
if ($LASTEXITCODE -ne 0 -or $sourceIdentity[0] -notin @("$database|127.0.0.1|$sourcePort", "$database|127.0.0.1/32|$sourcePort") -or $sourceIdentity[1].Trim() -ne $sourceDataReal.Replace('\','/')) {
    throw "Source cluster identity did not match the verified 55481 data directory: $($sourceIdentity -join ' ')"
}
$destinationCheck = & $ready -h 127.0.0.1 -p $restorePort 2>&1
if ($LASTEXITCODE -eq 0) { throw "Destination port $restorePort is already accepting connections." }
if (Test-Path -LiteralPath $backupRoot) { throw 'Unique backup directory unexpectedly already exists.' }
New-Item -ItemType Directory -Path $backupRoot | Out-Null
New-Item -ItemType Directory -Path $privateRoot -Force | Out-Null

$fixture = Get-Content -LiteralPath $fixturePath -Raw -Encoding utf8 | ConvertFrom-Json
$tenderPrivate = [string] $fixture.private_disk
$tenderPrivateReal = (Resolve-Path -LiteralPath $tenderPrivate).Path
if (-not $tenderPrivateReal.StartsWith($workspace + '\storage\framework\testing\disks\tender-actions-check-', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Tender private fixture root resolved outside the workspace testing-disk directory.'
}
$basePrivate = Join-Path $sourceStorage 'app\private'
foreach ($child in Get-ChildItem -LiteralPath $basePrivate -Force) {
    Copy-Item -LiteralPath $child.FullName -Destination $privateRoot -Recurse -Force
}
foreach ($child in Get-ChildItem -LiteralPath $tenderPrivateReal -Force) {
    $targetPath = Join-Path $privateRoot $child.Name
    if (Test-Path -LiteralPath $targetPath) {
        $sourceHash = (Get-FileHash -LiteralPath $child.FullName -Algorithm SHA256).Hash
        $targetHash = if (Test-Path -LiteralPath $targetPath -PathType Leaf) { (Get-FileHash -LiteralPath $targetPath -Algorithm SHA256).Hash } else { $null }
        if ($null -ne $targetHash -and $targetHash -ne $sourceHash) { throw "Tender private file collision at $($child.Name)." }
    }
    Copy-Item -LiteralPath $child.FullName -Destination $privateRoot -Recurse -Force
}

$php = (Get-Command php).Source
$env:RESTORE_AUDIT_HOST = '127.0.0.1'
$env:RESTORE_AUDIT_PORT = "$sourcePort"
$env:RESTORE_AUDIT_DATABASE = $database
$env:RESTORE_AUDIT_USERNAME = 'erp_app'
$env:RESTORE_AUDIT_PASSWORD = ''
$env:RESTORE_AUDIT_PRIVATE_ROOT = $privateRoot
$env:RESTORE_AUDIT_OUTPUT = $sourceManifest
Invoke-Checked $php @((Join-Path $workspace 'docs\release\evidence\final-20261030\verify-database-private-restore.php')) 'Source table/file/PDF manifest'

Invoke-Checked (Join-Path $pgBin 'pg_dump.exe') @('-h', '127.0.0.1', '-p', "$sourcePort", '-U', 'erp_app', '-Fc', '-f', $dumpPath, $database) 'Schema-complete source database dump'
Invoke-Checked (Join-Path $pgBin 'pg_dump.exe') @('-h', '127.0.0.1', '-p', "$sourcePort", '-U', 'erp_app', '-s', '--no-owner', '--no-acl', '-f', $sourceSchema, $database) 'Source schema-only dump'

$initdb = Join-Path $pgBin 'initdb.exe'
Invoke-Checked $initdb @('-D', $restoreData, '-U', 'postgres', '--auth-local=trust', '--auth-host=trust', '--encoding=UTF8', '--no-locale') 'UTF8 isolated restore cluster initialization'
$startOutput = Join-Path $backupRoot 'pg-ctl-start.stdout.txt'
$startError = Join-Path $backupRoot 'pg-ctl-start.stderr.txt'
$start = Start-Process -FilePath (Join-Path $pgBin 'pg_ctl.exe') -ArgumentList @('-D', $restoreData, '-l', (Join-Path $backupRoot 'postgres.log'), '-o', "`"-h 127.0.0.1 -p $restorePort`"", '-w', 'start') -WindowStyle Hidden -PassThru -RedirectStandardOutput $startOutput -RedirectStandardError $startError
$restoreStarted = $true
$startDeadline = (Get-Date).AddSeconds(30)
while ((Get-Date) -lt $startDeadline) {
    & $ready -h 127.0.0.1 -p $restorePort *> $null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Milliseconds 150
}
& $ready -h 127.0.0.1 -p $restorePort *> $null
if ($LASTEXITCODE -ne 0) { throw "Isolated restore server did not accept connections within 30 seconds. See $startOutput and $startError." }
try {
    Invoke-Checked $ready @('-h', '127.0.0.1', '-p', "$restorePort") 'Restore cluster readiness check'
    Invoke-Checked (Join-Path $pgBin 'psql.exe') @('-h', '127.0.0.1', '-p', "$restorePort", '-U', 'postgres', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-c', 'CREATE ROLE erp_app LOGIN SUPERUSER') 'Restore application role setup'
    Invoke-Checked (Join-Path $pgBin 'createdb.exe') @('-h', '127.0.0.1', '-p', "$restorePort", '-U', 'postgres', $database) 'Restore database creation'
    Invoke-Checked (Join-Path $pgBin 'pg_restore.exe') @('-h', '127.0.0.1', '-p', "$restorePort", '-U', 'postgres', '-d', $database, '--no-owner', '--exit-on-error', '--jobs=2', $dumpPath) 'Full database restore'
    Invoke-Checked (Join-Path $pgBin 'pg_dump.exe') @('-h', '127.0.0.1', '-p', "$restorePort", '-U', 'erp_app', '-s', '--no-owner', '--no-acl', '-f', $restoreSchema, $database) 'Restored schema-only dump'

    $env:RESTORE_AUDIT_PORT = "$restorePort"
    $env:RESTORE_AUDIT_PRIVATE_ROOT = $privateRoot
    $env:RESTORE_AUDIT_OUTPUT = $restoreManifest
    Invoke-Checked $php @((Join-Path $workspace 'docs\release\evidence\final-20261030\verify-database-private-restore.php')) 'Restored table/file/PDF manifest'
    $source = Get-Content -LiteralPath $sourceManifest -Raw -Encoding utf8 | ConvertFrom-Json
    $restored = Get-Content -LiteralPath $restoreManifest -Raw -Encoding utf8 | ConvertFrom-Json
    $countsEqual = (ConvertTo-Json -InputObject $source.table_counts -Compress -Depth 5) -ceq (ConvertTo-Json -InputObject $restored.table_counts -Compress -Depth 5)
    $privateEqual = (ConvertTo-Json -InputObject $source.private_files -Compress -Depth 8) -ceq (ConvertTo-Json -InputObject $restored.private_files -Compress -Depth 8)
    $officialEqual = (ConvertTo-Json -InputObject $source.official_documents -Compress -Depth 8) -ceq (ConvertTo-Json -InputObject $restored.official_documents -Compress -Depth 8)
    $schemaSourceHash = (Get-FileHash -LiteralPath $sourceSchema -Algorithm SHA256).Hash
    $schemaRestoredHash = (Get-FileHash -LiteralPath $restoreSchema -Algorithm SHA256).Hash
    $schemaDumpTextEqual = $schemaSourceHash -eq $schemaRestoredHash
    $schemaInventoryEqual = (ConvertTo-Json -InputObject $source.schema_inventory -Compress -Depth 20) -ceq (ConvertTo-Json -InputObject $restored.schema_inventory -Compress -Depth 20)
    $sourceTenderDocs = @($source.private_files | Where-Object table -eq 'project_tender_documents')
    $restoredTenderDocs = @($restored.private_files | Where-Object table -eq 'project_tender_documents')
    $checks = [ordered]@{
        source_database = $source.database
        restore_database = $restored.database
        source_pgdata = $sourceDataReal
        restore_pgdata = (Resolve-Path -LiteralPath $restoreData).Path
        restore_port = $restorePort
        source_dump = $dumpPath
        source_table_count = $source.table_count
        restore_table_count = $restored.table_count
        all_table_counts_equal = $countsEqual
        schema_dump_text_identical = $schemaDumpTextEqual
        schema_catalog_inventory_equal = $schemaInventoryEqual
        source_schema_sha256 = $schemaSourceHash
        restore_schema_sha256 = $schemaRestoredHash
        source_private_file_count = $source.private_file_count
        restored_private_file_count = $restored.private_file_count
        private_files_and_metadata_hashes_equal = $privateEqual
        source_tender_evidence_count = $sourceTenderDocs.Count
        restored_tender_evidence_count = $restoredTenderDocs.Count
        source_official_pdf_count = $source.official_document_count
        restored_official_pdf_count = $restored.official_document_count
        source_official_pdfs_integrity_valid = $source.official_document_integrity
        restored_official_pdfs_integrity_valid = $restored.official_document_integrity
        official_pdf_ids_numbers_hashes_equal = $officialEqual
        source_private_missing_count = @($source.missing_private_files).Count
        restored_private_missing_count = @($restored.missing_private_files).Count
        receipt_time_local = Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz'
        restoration_scope = 'disposable local PostgreSQL clone only; not a production recovery-time or recovery-point claim'
    }
    $checks | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $receiptPath -Encoding utf8
    $checks | ConvertTo-Json -Depth 8 | Tee-Object -FilePath $logPath
    $allChecksPass = (
        $countsEqual -and $schemaInventoryEqual -and $privateEqual -and $officialEqual -and
        $source.official_document_integrity -and $restored.official_document_integrity -and
        $source.missing_private_files.Count -eq 0 -and $restored.missing_private_files.Count -eq 0
    )
    if (-not $allChecksPass) {
        throw 'Backup/restore comparison failed; see the retained source and restored manifests.'
    }
} finally {
    if ($restoreStarted) {
        $reportedIdentity = & (Join-Path $pgBin 'psql.exe') -h 127.0.0.1 -p $restorePort -U postgres -d postgres -Atc 'show data_directory; show port;' 2>$null
        if ($LASTEXITCODE -eq 0 -and $reportedIdentity[0].Replace('/','\').TrimEnd('\') -eq (Resolve-Path -LiteralPath $restoreData).Path.TrimEnd('\') -and $reportedIdentity[1].Trim() -eq "$restorePort") {
            $stopOutput = Join-Path $backupRoot 'pg-ctl-stop.stdout.txt'
            $stopError = Join-Path $backupRoot 'pg-ctl-stop.stderr.txt'
            $stop = Start-Process -FilePath (Join-Path $pgBin 'pg_ctl.exe') -ArgumentList @('-D', $restoreData, '-m', 'fast', '-w', 'stop') -WindowStyle Hidden -PassThru -RedirectStandardOutput $stopOutput -RedirectStandardError $stopError
            $stopDeadline = (Get-Date).AddSeconds(30)
            while ((Get-Date) -lt $stopDeadline) {
                & $ready -h 127.0.0.1 -p $restorePort *> $null
                if ($LASTEXITCODE -ne 0) { break }
                Start-Sleep -Milliseconds 150
            }
            & $ready -h 127.0.0.1 -p $restorePort *> $null
            if ($LASTEXITCODE -eq 0) { throw 'Verified restore cluster still accepts connections after the guarded stop request.' }
        } else {
            Write-Warning 'Restore cluster identity changed; left it running for manual inspection.'
        }
    }
}

$sourceAfter = & $ready -h 127.0.0.1 -p $sourcePort 2>&1
if ($LASTEXITCODE -ne 0) { throw 'Original isolated source clone 55481 did not remain available after restore verification.' }
$restoreAfter = & $ready -h 127.0.0.1 -p $restorePort 2>&1
if ($LASTEXITCODE -eq 0) { throw 'Verified temporary restore clone 55486 remained running after its guarded stop.' }
Write-Output "Evidence=$receiptPath"
Write-Output "Log=$logPath"
