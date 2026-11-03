# PostgreSQL and data integrity audit

Audit: 2026-11-01. Engine: **PostgreSQL 17.11**. Database release status: **BLOCKED by financial correctness and production recovery/upgrade acceptance**, even where schema creation succeeds. No SQLite substitution, operational reset, migration or data mutation was performed.

## Runtime/dependency baseline (G0)

| Component | Actual local version / evidence |
|---|---|
| PHP |8.4.21 CLI; bcmath, pdo_pgsql, intl, mbstring, GD, ZIP, OpenSSL and XML family present. CLI presence does not validate a production FPM/web image. |
| Laravel / Fortify |13.38.0 /1.41.0, installed vendor metadata and locked Composer inventory. |
| Livewire / Flux free |4.4.7 /2.20.1; no Filament application stack was substituted. |
| Pulse / Debugbar |Pulse1.8.1 and development-only Debugbar dependency; intentionally isolated profiling configuration, not production monitoring proof. |
| PHPUnit / Pint |12.5.40 /1.32.1; no installed Larastan, Rector or Pest audit tool was established. No new tooling installed. |
| Node / npm |24.15.0 /11.12.1. CI declares Node22; target build compatibility/reproducibility remains separate. |
| Tailwind / Vite / Laravel Vite plugin |4.3.3 /8.3.4 /3.2.0; `npm-installed.json`. |
| Playwright / passkeys client |1.62.1 /0.2.0; current browser uses existing Edge because the bundled Chromium runtime is not installed. |
| PostgreSQL / Redis |Local PostgreSQL 17.11 verified; optional Redis is a configured alternative, no production Redis/queue service accepted. |

Raw inventories: `evidence/production-readiness-20261101/composer-installed.json`, `npm-installed.json`, `dev-readonly-snapshot.json` and initial source/environment/CI receipts. Installed version is not a claim of latest/safe/supported target deployment; fresh dependency advisories are in the security report.

## Current operational baseline (read-only)

Fresh receipt: `evidence/production-readiness-20261101/dev-readonly-snapshot.json`, captured using a transaction explicitly set READ ONLY and rolled back. Actual endpoint: `erp_elec_new@127.0.0.1:5443`. No credentials are in the receipt.

- Last applied migration: `2026_10_31_198000_make_tender_guard_refresh_safe`.
- Go-Live date NULL; financial activation false; tax validation false.
- Inventory movements, posted journals, supplier/customer invoices and Treasury movements: **zero** in the selected operational tables.
- PostgreSQL catalog: **563 foreign keys, 325 CHECK constraints, zero unvalidated constraints**. These catalog counts do not prove every required logical invariant exists.
- Selected money columns are NUMERIC(20,2); quantities NUMERIC(24,8); FIFO remaining raw value/unit cost NUMERIC(36,16). No `real`/`double precision` was returned for the selected financial/stock columns. This is a selected-column audit, not a claim about every field in every table.

NULL activation and zero postings are legitimate inactive states. They are not proof of reconciled real company openings or authorization to activate.

## Clean creation and runtime checks

New marker-owned private PostgreSQL data directory `.tmp/production-readiness-20261101/pgdata`, loopback **49683**, exact database **erp_elec_new_test**. Identity includes DB name, address, port and server data_directory; Laravel storage, logs, views and all config/route/service/package caches are isolated. Trust authentication/superuser bootstrap is confined to this disposable local fixture; it is not a production role design.

| Check | Status | Evidence / exact limit |
|---|---|---|
| Clean full migration chain | VERIFIED PASS | `clean-migration.txt` / `clean-migration.json`, exit0, migrations through 198 on a new database. |
| Original Laravel suite | VERIFIED FAIL as invocation | `full-phpunit.txt` / `.json`, exit2: **41 passed, 16 failed, 111 assertions**, 10.50s. All 16 failures are explicit `OptimizationRegressionTest` target/marker guard refusals: the original test accepts55460/55462, not the audit's49683. No green full-suite claim or application defect is inferred from these refusals. |
| Existing broad safe campaigns | VERIFIED PASS | `broad-21-summary.json` and21 individual `check-*.php.txt` logs, all exit0. Covers M01/RBAC/defaults, products/parties/data operations/factories, quotation/order source and regression slices; does not cover the entire17-owner business scope. |
| Config / route / Blade compile | VERIFIED PASS locally | `runtime-cache-summary.json`: config:cache, route:cache, view:cache and route:list JSON pass,229 registered routes. Generated files stayed private. |
| Asset build | VERIFIED PASS locally | `vite-build.txt`: isolated output,22 modules. Two font URLs remain runtime-resolved build warnings; fresh browser sample observed no broken assets/images. |
| Source formatting | VERIFIED FAIL | `pint-source.txt`,27 paths including two generated caches. No formatting fix; see PR-STYLE-001. |
| Financial action/negative integrity | See business receipt | `FinancialReports` current close/cutoff source and `JournalPosting` optional-control protection are independently audited. General constraints cannot establish the missing as-of semantics or complete-company authorization. |
| Previous-schema populated upgrade | NOT TESTED unless specifically documented in recovery receipt | Clean migration is not a previous-production-version upgrade. No previous approved deployable artifact or production schema version was supplied. Do not infer this from a latest-schema restore. |

## Integrity mechanisms inspected

`database/migrations` is the real schema; the imported data-dictionary headers are historical and contain superseded SQLite wording. Source inspection found named FK/unique/CHECK/index guards, immutable posted/source evidence triggers and deferred quantity/value consistency constraints. Examples include `...165000_create_sales_order_commitments.php`, `...172000_create_financial_operations.php`, `...176000_create_customer_advances.php`, `...196000_create_projects_tender_capability.php` and the forward198 trigger refresh guard. Existing business Actions use transactions, row/advisory locks, optimistic versions and source/idempotency uniqueness. FIFO/financial logic uses BCMath/decimal strings.

These are useful safeguards, not an audit pass for every invariant:

| Integrity axis | Status / qualification |
|---|---|
| Double entry and source uniqueness | Current positive action evidence is in the business report; a balanced journal can still violate a subledger control (PR-FIN-003). |
| Historical source integrity | VERIFIED FAIL in current design: undated source projections compared with dated GL (PR-FIN-002). |
| Period authorization / locking | Complete-company reconciliation scope is unsafe (PR-FIN-001). Source posting locks an open period in `JournalPosting::finish`; exhaustive close-vs-post interleavings and deadlock/timeout acceptance are not established by source inspection. |
| Idempotency / version conflicts | Broad and owner-specific tests cover bounded cases. All sources, changed-payload replays and two-worker races must be accepted per released journey; read-only HTTP load is not write contention evidence. |
| FK validation / orphan prevention | Catalog confirms declared FK constraints validated. Full semantic orphan scan for every polymorphic owner/source/file/approval link was not executed. Zero unvalidated constraints is not a semantic-orphan certificate. |
| Index/query plans | Key register/aging/source indexes exist. Full large-data query-plan/index selectivity, bloat, vacuum and target slow-query analysis NOT TESTED. |
| Transaction isolation / waits | Target settings, long-running transaction baselines and production deadlock behavior NOT TESTED. Local defaults and read-only load do not substitute. |
| Sensitive data | Laravel password hashing, private files and encrypted sessions are configured; actual target DB transport encryption, key custody, backup encryption, least-privilege roles and access monitoring remain NOT TESTED. |
| Migration rollback | Financial/immutable migrations frequently throw on `down()`; unsafe automatic schema rollback is not a recovery strategy. Compatible application rollback or coordinated backup/restore/forward repair requires rehearsal. |

## Preservation and recovery

`preservation-before.json` records selected environment/source/public-asset hashes without values; final preservation receipt records comparison. Business evidence is entirely synthetic and isolated. Do not treat the private cluster's role/trust setup as a template to deploy.

`preservation-after.json` confirms both environment files and the two audited accounting source files unchanged, but the public manifest/tree **changed**. Source CSS/public assets have later timestamps and an unattributed concurrent mutation; no audit participant changed them or reverted that other work. `CANDIDATE_DRIFT.md` records the boundary. Whole-workspace immutability and final-current UI acceptance are therefore not asserted. The audit's Vite build emitted only to its private output directory.

The [backup/restore report](PRODUCTION_READINESS_BACKUP_RESTORE.md) identifies exactly which actual database/file/PDF recovery was performed and which target/RPO/RTO/upgrade checks remain open. PostgreSQL's [backup documentation](https://www.postgresql.org/docs/17/backup.html) distinguishes logical dumps, filesystem backup and continuous archiving; a logical dump by itself neither verifies application recovery nor proves point-in-time recovery.

Blocking findings and acceptance criteria: PR-FIN-001/002/003, PR-DR-001, PR-CFG-001, PR-CI-001 and PR-REL-001 in [the register](PRODUCTION_READINESS_BLOCKERS.md).
