param([int]$SourcePort = 55448, [int]$TargetPort = 55462)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '../../../..')).Path
$tag = 'm09-audit-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
$work = Join-Path $root ('.tmp/' + $tag)
$data = Join-Path $work 'pgdata'
$dump = Join-Path $work 'workshop-financial.dump'
$storage = Join-Path $work 'storage'
$out = Join-Path $PSScriptRoot ($tag + '-financial-check.txt')
$bin = Join-Path $root '.tools/postgresql/pgsql/bin'
$envFile = Join-Path $root '.env.testing'
$sourceDb = 'erp_elec_new_test'
$sourceRole = 'erp_app'

$sourceReady = & (Join-Path $bin 'pg_isready.exe') -h 127.0.0.1 -p $SourcePort 2>$null | Out-String
$targetReady = & (Join-Path $bin 'pg_isready.exe') -h 127.0.0.1 -p $TargetPort 2>$null | Out-String
if ($sourceReady -notmatch 'accepting connections') { throw "Source PostgreSQL port $SourcePort is not accepting connections." }
if ($targetReady -match 'accepting connections') { throw "Refusing occupied target PostgreSQL port $TargetPort." }
$sourceIdentity = & (Join-Path $bin 'psql.exe') -h 127.0.0.1 -p $SourcePort -U postgres -d $sourceDb -At -c "select current_database() || '|' || inet_server_port() || '|' || current_setting('server_version')"
if ($LASTEXITCODE -ne 0 -or $sourceIdentity.Trim() -ne "$sourceDb|$SourcePort|17.11") { throw "Source identity check failed for isolated test database: $sourceIdentity" }

New-Item -ItemType Directory -Force -Path $work, $storage, (Join-Path $storage 'app/private'),
    (Join-Path $storage 'framework/cache/data'), (Join-Path $storage 'framework/sessions'),
    (Join-Path $storage 'framework/views'), (Join-Path $storage 'framework/testing'),
    (Join-Path $storage 'framework/livewire-tmp'), (Join-Path $storage 'logs') | Out-Null

& (Join-Path $bin 'pg_dump.exe') -h 127.0.0.1 -p $SourcePort -U postgres -d $sourceDb -Fc -f $dump
if ($LASTEXITCODE -ne 0) { throw "Read-only source dump failed ($LASTEXITCODE)." }
& (Join-Path $bin 'initdb.exe') -D $data -U postgres --encoding=UTF8 --locale=C --auth-local=trust --auth-host=trust
if ($LASTEXITCODE -ne 0) { throw "Fresh clone initialization failed ($LASTEXITCODE)." }
& (Join-Path $bin 'pg_ctl.exe') -D $data -l (Join-Path $work 'postgres.log') -o "-h 127.0.0.1 -p $TargetPort" -w start
if ($LASTEXITCODE -ne 0) { throw "Fresh clone startup failed ($LASTEXITCODE)." }
& (Join-Path $bin 'psql.exe') -h 127.0.0.1 -p $TargetPort -U postgres -d postgres -v ON_ERROR_STOP=1 -c 'CREATE ROLE erp_app LOGIN SUPERUSER'
if ($LASTEXITCODE -ne 0) { throw "Clone-only erp_app role setup failed ($LASTEXITCODE)." }
& (Join-Path $bin 'createdb.exe') -h 127.0.0.1 -p $TargetPort -U postgres -O $sourceRole $sourceDb
if ($LASTEXITCODE -ne 0) { throw "Clone-only test database creation failed ($LASTEXITCODE)." }
& (Join-Path $bin 'pg_restore.exe') -h 127.0.0.1 -p $TargetPort -U postgres -d $sourceDb --no-owner --exit-on-error $dump
if ($LASTEXITCODE -ne 0) { throw "Clone restore failed ($LASTEXITCODE)." }

$env:APP_ENV = 'testing'; $env:APP_DEBUG = 'false'; $env:DB_CONNECTION = 'pgsql'; $env:DB_HOST = '127.0.0.1'
$env:DB_PORT = [string]$TargetPort; $env:DB_DATABASE = $sourceDb; $env:DB_USERNAME = $sourceRole; $env:DB_PASSWORD = ''; $env:DB_URL = ''
$env:LARAVEL_STORAGE_PATH = $storage; $env:SESSION_DRIVER = 'array'; $env:CACHE_STORE = 'array'; $env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'; $env:LOG_CHANNEL = 'stderr'; $env:APP_CONFIG_CACHE = Join-Path $work 'config.php'; $env:APP_ROUTES_CACHE = Join-Path $work 'routes.php'; $env:VIEW_COMPILED_PATH = Join-Path $storage 'framework/views'

$cloneIdentity = & (Join-Path $bin 'psql.exe') -h 127.0.0.1 -p $TargetPort -U postgres -d $sourceDb -At -c "select current_database() || '|' || inet_server_port() || '|' || current_setting('server_version')"
if ($LASTEXITCODE -ne 0 -or $cloneIdentity.Trim() -ne "$sourceDb|$TargetPort|17.11") { throw 'Refusing to migrate: clone identity does not match the target port and test DB.' }
& php -d opcache.enable_cli=0 artisan migrate --env=testing --force
if ($LASTEXITCODE -ne 0) { throw "Isolated clone migration failed ($LASTEXITCODE)." }
& php -d opcache.enable_cli=0 docs/release/evidence/final-20261030/m09-workshop-financial-check.php 2>&1 | Tee-Object -FilePath $out
if ($LASTEXITCODE -ne 0) { throw "M09 financial probe failed ($LASTEXITCODE); clone remains at $work for diagnosis." }
Write-Output "M09 financial probe passed on clone $sourceDb at 127.0.0.1:$TargetPort. Clone data: $work. Receipt: $out"
