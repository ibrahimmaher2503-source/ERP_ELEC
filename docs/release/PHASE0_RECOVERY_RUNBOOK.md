# Phase 0 local backup and restore runbook

This runbook documents the recovery acceptance recorded in [phase0-recovery.json](../qa/evidence/phase0-recovery.json). It uses two disposable PostgreSQL clusters and two private storage roots. It does not reset, connect to, or write the shared development/test databases. The acceptance source data is synthetic and created through application business actions; do not use the fixture against a real company database.

## Requirements

- Run from the repository root in PowerShell.
- Use the bundled PostgreSQL tools under `.tools/postgresql/pgsql/bin`.
- Ensure ports `55432` and `55433` are free. Use new ports and update `tests/Support/check-phase0-recovery.php` if they are occupied.
- Keep both cluster and storage directories inside a new disposable workspace directory. Do not reuse any existing database directory.
- The helpers require the database name `erp_elec_new_test` and reject other names. Set `DB_USERNAME=postgres` and an empty `DB_PASSWORD` for the isolated trust-auth clusters; never print or save credentials.

## Repeatable local installation

Use the repository's locked PHP and JavaScript dependencies. Install the PHP extensions required by `composer.lock`, including PostgreSQL PDO, BCMath, GD and the spreadsheet/PDF dependencies. PostgreSQL is required; SQLite is not an acceptance substitute.

1. Create separate PostgreSQL application and testing databases with appropriately scoped local roles. Configure `.env` and `.env.testing` privately, with different database names. The current acceptance scripts pin `erp_elec_new_test`; do not reuse the development database name there.
2. Copy `.env.example` to `.env` only for a new installation. Preserve an existing environment and its `APP_KEY`; changing an existing key can invalidate protected data and sessions. Never copy secrets into the runbook or terminal transcript.
3. Run `composer install --no-interaction --prefer-dist` (or the documented local `php .tools/composer.phar install --no-interaction --prefer-dist` fallback where present), `php artisan key:generate` for a new environment only, `php artisan migrate`, `npm ci`, and `npm run build`. Install Playwright's pinned Chromium browser locally with `npx playwright install chromium`, or set the explicitly approved `PLAYWRIGHT_EXECUTABLE_PATH` to a local browser executable. Current recorded browser acceptance used the existing Edge installation; it did not verify a fresh Playwright browser install. Browser execution uses only isolated fixture clusters configured by the documented guards. These commands do not supply company Go-Live values or activate financial posting.
4. For explicitly authorized local setup, `php artisan erp:create-local-user <email>` prompts privately for a password and creates no business grants. The local-only `php artisan erp:bootstrap-security <email>` records only audited security-configuration authority. Use Organization to assign the Administrator role explicitly and configure actual branch/warehouse scopes. A display name never supplies role membership. Public registration stays disabled. Do not use test fixtures or an unrestricted seeder to authorize a production operator.
5. Configure the real company and owner-approved business inputs through their Actions/UI. Optional synthetic master demonstrations require an explicit local/testing `EgyptMarketDemoSeeder` invocation; they do not replace actual activation inputs or create stock/financial postings.
6. Serve the app with `php artisan serve --host=127.0.0.1 --port=8000` after building assets, or use Vite development serving as configured. Browser harnesses use `tests/E2E/support/laravel-static-router.php` so existing CSS/JavaScript files are served as assets. Native `LARAVEL_STORAGE_PATH` isolates Laravel's storage root for disposable browser instances; an unrelated custom fixture variable alone does not change web storage.
7. Confirm migrations, authentication, current Administrator synchronization and authorized private-file access before using a restored environment. Tests and recovery helpers run against their guarded isolated databases, never the application database. Follow the remaining acceptance decision in `PHASE0_STABILIZATION_REPORT.md`; installation success is not production financial authorization.

## Build the synthetic source and dump it

Choose a new `$work` path for each run. Initialize and start the source cluster, then apply the checked-in migrations only to that cluster:

```powershell
$bin = (Resolve-Path .tools/postgresql/pgsql/bin).Path
$work = (Resolve-Path .).Path + '\.tmp\phase0-recovery-run'
New-Item -ItemType Directory -Force -Path $work | Out-Null
& "$bin\initdb.exe" -D "$work\pgdata" -U postgres --auth-local=trust --auth-host=trust --encoding=UTF8 --no-locale
& "$bin\pg_ctl.exe" -D "$work\pgdata" -o '-p 55432 -h 127.0.0.1 -c listen_addresses=127.0.0.1' -l "$work\postgres.log" start
$env:DB_CONNECTION = 'pgsql'; $env:DB_URL = ''
$env:DB_HOST = '127.0.0.1'; $env:DB_PORT = '55432'; $env:DB_DATABASE = 'erp_elec_new_test'
$env:DB_USERNAME = 'postgres'; $env:DB_PASSWORD = ''
php artisan migrate --env=testing --force
```

Run `tests/Support/check-phase0-recovery-seed.php` to execute and commit the synthetic business-action graph. It verifies the exact local PostgreSQL connection before writing. This fixture uses the source port `55432`; keep it private and disposable. Its PDF documents are created by the actual issuance actions and persisted in PostgreSQL.

```powershell
php -d opcache.enable_cli=0 tests/Support/check-phase0-recovery-seed.php
$env:PHASE0_STORAGE_ROOT = "$work\source-storage"
php -d opcache.enable_cli=0 tests/Support/check-phase0-recovery.php prepare
```

The `prepare` step stores a synthetic company logo and a private fixture file under the isolated local disk and records source counts, relationship counts, PDF hashes and file hashes in `docs/qa/evidence/phase0-recovery-source.json`. Preserve that manifest with the dump. Copy the complete `source-storage` directory as the file backup; PostgreSQL alone does not contain local private files.

Create a custom-format dump. Keep the dump private because it contains the synthetic database rows and persisted PDF bytes:

```powershell
& "$bin\pg_dump.exe" -h 127.0.0.1 -p 55432 -U postgres -Fc -f "$work\phase0-recovery.dump" erp_elec_new_test
Get-FileHash -LiteralPath "$work\phase0-recovery.dump" -Algorithm SHA256
```

## Restore into a second cluster and verify

Create a fresh restore cluster on port `55433`. Create an empty target database with the required name, restore the archive, then migrate it to the current checked-in schema. Do not run `migrate:fresh` or point these commands at a shared database.

```powershell
& "$bin\initdb.exe" -D "$work\pgdata-restore" -U postgres --auth-local=trust --auth-host=trust --encoding=UTF8 --no-locale
& "$bin\pg_ctl.exe" -D "$work\pgdata-restore" -o '-p 55433 -h 127.0.0.1 -c listen_addresses=127.0.0.1' -l "$work\postgres-restore.log" start
& "$bin\createdb.exe" -h 127.0.0.1 -p 55433 -U postgres erp_elec_new_test
& "$bin\pg_restore.exe" -h 127.0.0.1 -p 55433 -U postgres --no-owner -d erp_elec_new_test "$work\phase0-recovery.dump"
Copy-Item -LiteralPath "$work\source-storage" -Destination "$work\restore-storage" -Recurse
$env:DB_CONNECTION = 'pgsql'; $env:DB_URL = ''
$env:DB_HOST = '127.0.0.1'; $env:DB_PORT = '55433'; $env:DB_DATABASE = 'erp_elec_new_test'
$env:DB_USERNAME = 'postgres'; $env:DB_PASSWORD = ''
php artisan migrate --env=testing --force
$env:PHASE0_STORAGE_ROOT = "$work\restore-storage"
php -d opcache.enable_cli=0 tests/Support/check-phase0-recovery.php verify
```

The verifier checks source and restored business-row counts (allowing newly applied migrations), joins issued PO/quotation/sales/delivery records back to their owners, checks private-file SHA-256 values, decodes all persisted official PDFs and checks their bytes against source and row hashes, and invokes the actual company-logo and PO-PDF controllers with authorized and denied restored actors. A successful recovery requires controller results `200, 200, 403, 403` and `all_verified: true`. On the recorded run, the source archive was at migration 167 and the restored cluster migrated through 171. The verifier rejects non-local hosts, any port outside 55432/55433, a nonmatching resolved PostgreSQL port/database, or an inherited database URL.

## Two-process contention checks

After recovery verification, run the authored concurrency harness on the isolated restored cluster. It creates its purchase orders, receipt drafts and issued PDFs through the owning application actions. Set the restored storage root because PO issuance requires the restored private company logo.

```powershell
$env:DB_CONNECTION = 'pgsql'; $env:DB_URL = ''
$env:DB_HOST = '127.0.0.1'; $env:DB_PORT = '55433'; $env:DB_DATABASE = 'erp_elec_new_test'
$env:DB_USERNAME = 'postgres'; $env:DB_PASSWORD = ''
$env:PHASE0_STORAGE_ROOT = "$work\restore-storage"
php -d opcache.enable_cli=0 tests/Support/check-phase0-concurrency.php
```

The harness synchronizes independent PHP processes at a barrier. It checks identical receipt posting and uniqueness of the resulting movement, FIFO layer, receipt posting and GRNI journal; two concurrent receipt creation actions whose combined quantities exceed the remaining PO quantity; two valid submitted one-unit receipts posting simultaneously against a two-unit approved PO with exact `2.00000000` posted quantity/stock delta and per-receipt movement/FIFO/GRNI linkage; and simultaneous official numbering for different approved PO sources. The accepted receipt from the quantity-cap case is posted through the real posting action. The parent and workers each require host `127.0.0.1`, port `55433`, database `erp_elec_new_test`, no database URL override, and a matching PostgreSQL server address/port before actions. Results are saved to [phase0-concurrency.json](../qa/evidence/phase0-concurrency.json). A race check that fails exits with an error; inspect the detailed worker outcomes before accepting the result.

## Retention and limits

Keep the dump, storage backup and source manifest together, access-controlled and encrypted according to the operator's actual policy. Delete only the explicitly named disposable cluster and work directories after review. The checked-in evidence is a local single-run acceptance; it does not establish remote backup reliability, production recovery time, or production data retention. Re-run with a fresh isolated directory before relying on a changed schema or recovery process.
