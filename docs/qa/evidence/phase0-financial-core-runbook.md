# Phase 0 financial core acceptance runbook

Run date: 2026-10-28. All database actions below used the owned restore cluster at `127.0.0.1:55433`, database `erp_elec_new_test`; the shared QA port 5443 and browser port 55434 were not used.

## Restore and migrate

The source archive `.tmp/phase0-recovery-20261027/phase0-recovery.dump` was restored into `.tmp/phase0-recovery-20261027/pgdata-restore`. The isolated cluster was started with:

```powershell
.tools/postgresql/pgsql/bin/pg_ctl.exe -D .tmp/phase0-recovery-20261027/pgdata-restore -l .tmp/phase0-recovery-20261027/postgres-restore.log -o "-p 55433 -h 127.0.0.1" start
$env:DB_CONNECTION='pgsql'; $env:DB_DATABASE='erp_elec_new_test'; $env:DB_URL=''
$env:DB_HOST='127.0.0.1'; $env:DB_PORT='55433'
php artisan --env=testing migrate --force
```

The migration command applied `2026_10_27_170000_preserve_fifo_unit_cost_lineage`. `.env.testing` defaults to 5443, so every probe explicitly set the process port to 55433 and checked both the active connection and database before acting.

## Run action-level financial checks

```powershell
$env:DB_CONNECTION='pgsql'; $env:DB_DATABASE='erp_elec_new_test'; $env:DB_URL=''
$env:DB_HOST='127.0.0.1'; $env:DB_PORT='55433'
php tests/Support/check-phase0-financial-core.php
```

The script authored synthetic users/grants and a non-control account, then used `AccountingActions::journal` and `advance` for create, submit, independent approval, and post. It retrieved the persisted official PDF, matched its stored SHA-256, and confirmed direct update/delete were rejected. It also confirmed denials for missing permission, unbalanced total, protected inventory control account, and a date outside open periods.

The same script called `OpeningWorkbook::template` and `read` with two valid balanced GL rows, then compared opening batch/line counts and activation version before and after. Preview persisted no batch, lines, or activation change. An initial bounded probe reduced the fixture range to two populated rows. After the workbook reader was fixed to avoid creating absent cells in its disk cache, the probe was rerun against the unmodified generated template with its original `A1:L501` range and filter; it returned two rows in 1.633 seconds with unchanged database state. The current evidence records this full-template run.

Results: `phase0-financial-core.json`, PASS. The run created one posted manual journal per successful run plus synthetic denied drafts; this acceptance database is intentionally no longer a clean baseline.

## Run real concurrent posting checks

```powershell
$env:DB_CONNECTION='pgsql'; $env:DB_DATABASE='erp_elec_new_test'; $env:DB_URL=''
$env:DB_HOST='127.0.0.1'; $env:DB_PORT='55433'
$env:PHASE0_STORAGE_ROOT=(Resolve-Path '.tmp/phase0-recovery-20261027/restore-storage').Path
php tests/Support/check-phase0-concurrency.php
```

Two independently saved/submitted one-unit receipts against a two-unit approved PO were posted by separate PHP workers released from the same barrier. Both exited successfully. The checker matched each receipt to exactly one Purchasing stock movement, FIFO layer, receipt posting and balanced GRNI journal at `1.00`; aggregate posted quantity and stock delta were both `2.00000000`. The same-receipt retry now verifies uniqueness of movements, FIFO layers, receipt postings, and GRNI journal in addition to posted status. Existing receipt quantity-cap and official-number contention races also passed.

Results: `phase0-concurrency.json`, PASS. Run order and generated synthetic identifiers are recorded there.

## Recovery evidence and scope

The restored source snapshot had migration 167 and the original recovery evidence verified its business counts, 19 official PDFs and private-file hashes after migrations 168 through 169. The restored cluster later advanced through migration 171; isolated acceptance probes then intentionally added synthetic records. Current counts appear in `phase0-recovery.json`, and the original 19-document manifest remains in `phase0-recovery-source.json`. This is a local isolated restore and posting test, not a remote disaster-recovery exercise.

## Scoped helper validation

These commands cover only Luna-authored Phase 0 helpers; they exclude application code and helpers owned by other agents.

```powershell
php vendor/bin/pint --test tests/Support/check-phase0-direct-sales.php tests/Support/check-phase0-financial-core.php tests/Support/check-phase0-recovery-seed.php tests/Support/check-phase0-recovery.php tests/Support/check-phase0-concurrency.php tests/Support/phase0-concurrency-worker.php
php -l tests/Support/check-phase0-direct-sales.php
php -l tests/Support/check-phase0-financial-core.php
php -l tests/Support/check-phase0-recovery-seed.php
php -l tests/Support/check-phase0-recovery.php
php -l tests/Support/check-phase0-concurrency.php
php -l tests/Support/phase0-concurrency-worker.php
```

Result on 2026-10-28: scoped Pint passed and all six helpers had no PHP syntax errors. No test suite or database action was part of this formatting pass.
