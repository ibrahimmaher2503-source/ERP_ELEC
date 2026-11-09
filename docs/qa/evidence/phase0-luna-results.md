# Phase 0 QA — Luna

Date: 2026-10-27

## Completed

- Added a no-database regression test for the module identity registry. Projects retains runtime ID and permission prefix `M07`, with its nine existing permission keys; DeliveryReturns records `M07` as historical only and owns no runtime permissions.
- Repaired the Round 2 master E2E fixture to require the exact PostgreSQL `erp_elec_new_test` database, create an active synthetic unit through its factory only when needed, and assign Administrator to the per-run synthetic operator through `Organization.AssignUserRole`. The isolated bootstrap actor receives only the security-admin grant required to invoke that audited action. The operator no longer gets a guessed direct permission list.
- Added E2E static asset preflight: validate manifest paths and files, then verify each asset is served with HTTP 200 and CSS/JavaScript MIME after the isolated server starts. Empty JavaScript entry files remain valid.

## Checks and results

- `php artisan test --filter=ModuleIdentityRegistryTest`: **PASS**, 2 tests, 5 assertions.
- `php -l tests/E2E/support/prepare-round2-master-fixtures.php`: **PASS**.
- `php -l tests/Unit/ModuleIdentityRegistryTest.php`: **PASS**.
- `node --check tests/E2E/round2-masters.cjs`: **PASS**.
- Before the full suite, verified `.env` uses PostgreSQL database `erp_elec_new` and `.env.testing` uses PostgreSQL database `erp_elec_new_test`. Saved a custom-format dump of the latter outside the repository.
- First `php artisan test`: **FAIL**, 3 passed and 36 failed. Feature tests hit PostgreSQL duplicate function `inventory_guard_transit_lot()` while RefreshDatabase repeatedly rebuilt the schema at migration 160. A custom-format backup of the exact test database was restored after this run; no development database was touched.
- After migrations 160/161 were made safe to reinstall with `CREATE OR REPLACE FUNCTION`, `php artisan test`: **PASS**, 39 tests, 99 assertions, 10.03s.
- First `php tests/Support/check-phase0-valued-inventory.php`: **FAIL at deferred PostgreSQL constraints**, exposing a mismatch between `inventory_transfer_receipt_check()` and the `:lot:<id>` suffix in valued transfer movement keys. All check data rolled back. Migration 163 added exact source-matched reconciliation for those keys.
- After applying migration 163 incrementally to `erp_elec_new_test`, the same check **PASSed all 13 checkpoints**. It verifies 15 mappings; actual opening and internal issue; priced positive adjustment; same/cross-branch valued transfers with partial/final receipts, retries, FIFO value and balanced journals; positive/negative count variance; transactional purchase receipt effects and retry; and immediate deferred-constraint validation. Its outer test transaction rolls back. Detailed synthetic evidence is in [phase0-valued-paths.json](phase0-valued-paths.json).
- Browser E2E can now proceed; it is still pending.

## Notes

PostgreSQL schema refresh leaves standalone functions behind, so migrations 160/161 now use replace-safe function creation. Migration 163 reconciles transfer receipt ledger keys with their actual valued-lot movement keys, and the focused runtime acceptance now passes.

## 2026-10-28 correction acceptance continuation

- Extended `tests/Support/check-phase0-valued-inventory.php` with rollback-only actual-action checks for partial and full internal issue correction, original FIFO `received_on`/unit-cost lineage, positive-adjustment reversal against its original FIFO layer, and unauthorized, over-cap, altered-idempotency-payload, and later-dependent-movement denials. The probe retains its `SET CONSTRAINTS ALL IMMEDIATE` checkpoint.
- `php -l tests/Support/check-phase0-valued-inventory.php`: **PASS**.
- Verified before execution: `.env` targets `erp_elec_new`; `.env.testing` targets PostgreSQL `erp_elec_new_test` on port 5443; no environment override to development was made.
- Historical status on the first 2026-10-28 attempt: the probe was not executed because `127.0.0.1:5443` refused connections. The cluster was later restarted by the lead; the results below supersede that attempt.

## 2026-10-28 resumed current-schema acceptance

All database checks below verified `.env.testing` and ran against PostgreSQL `erp_elec_new_test` on `127.0.0.1:5443`, server version 17.11. Migrations 168–170 were applied incrementally to that test database; all migrations through 170 report `Ran`. No development database reset or role change was performed.

- `php tests/Support/check-phase0-valued-inventory.php`: **PASS**, every recorded checkpoint, full action graph and transactional rollback. Internal issue corrections executed partial then full reversal; the exact same-payload replay remained idempotent; two balanced reversal journals summed to the original `15.00`; original FIFO date and unit cost were retained. Both internal correction and positive-adjustment reversal savepoints executed `SET CONSTRAINTS ALL IMMEDIATE` before rollback. Unauthorized, over-cap, altered-payload and later-dependent-movement denials passed. Quotation readiness rejected missing legal identity and missing referenced private logo without creating an issue, official number/document, or changing draft state. A quote dated one day before its converted order retained its own tax date; the SO snapshot stored the order date and the same accepted net/tax/total. Existing real quote→written acceptance→conversion→independent review/approval→backorder reservation→delivery→FIFO/COGS→backorder refill also passed. See [current valued-action trace](phase0-valued-paths.json).
- `php artisan test --env=testing`: **PASS**, 40 tests / 103 assertions, 34.35 seconds. Includes the new `WorkbookCodecSparseTemplateTest` against the original untrimmed 500-row template.
- `php artisan test --env=testing --filter=WorkbookCodecSparseTemplateTest`: **PASS**, 1 test / 4 assertions. The sparse read stayed within its 10-second read bound; the whole test case took 10.53 seconds.
- `php -d output_buffering=Off -d implicit_flush=1 .tools/check-data-operations.php`: **PASS** after the WorkbookCodec cell-existence fix. The complete streamed run reached `Disposable data-operations database transaction rolled back.` and exit 0, including original sparse-template, archive/header, formula/security limits, import/export and Livewire scenarios. The previous apparent hang was the empty formatted-cell expansion; corrected run completed in roughly 30 seconds.
- `php .tools/check-administrator-defaults.php`: **PASS**, all Administrator default/current-future permission, complete read-only matrix, synchronization, non-membership, scope, inactive/unverified and invariant scenarios; transaction rolled back.
- `php .tools/check-role-permission-propagation.php`: **PASS**, all 14 roles, membership and default-deny, grant/revoke propagation, branch and Administrator scope scenarios; transaction rolled back.
- `php .tools/check-m03-customers.php`: **PASS** per complete marker. Active lookup, inactive lookup, inactive denial under `requireActive`, search exclusion and restoration through owner action passed. The optional cross-connection NOWAIT probe was not used: its rollback-only synthetic customer is invisible to the independent connection, so it cannot prove a lock on that fixture.
- `php .tools/check-m04-sales-orders.php`: **PASS** per complete marker, including current Sales Returns 404 behavior and updated independent-review/deferred commitment expectations.
- `php .tools/check-m05-m06-roundtrip.php`: **PASS** per complete lifecycle marker using real configured/validated financial setup, independently approved activation, source posting, FIFO and Inventory API reconciliation.
- `php .tools/check-sales-order-factories.php`: **PASS**, repeat-safe draft-only factory/seeder and current owner API fixtures.
- `php -d output_buffering=Off -d implicit_flush=1 .tools/check-m06-inventory.php`: **FAIL / stale acceptance setup**. The script passed 23 focused warehouse, import, Livewire and opening self-approval checks, then its next synthetic opening post was rejected with `ValidationException: الترحيل المالي مغلق أو تاريخ المعاملة يسبق تاريخ التشغيل العام.` The script does not configure/validate/activate M11 before expecting a successful opening post. No bypass or fabricated activation was added; the complete financial setup and real opening lifecycle are covered by the passing current-schema valued-inventory and M05/M06 round-trip checks. The script stopped before its later inventory assertions, so those are not claimed from this run.

The local QA aggregate remains **incomplete**: the listed checks are independently evidenced, while the legacy M06 script remains stale at its missing financial activation prerequisite. Pint and browser acceptance are separately tracked and are not claimed by these results.

## Lead integration continuation — final recorded 2026-10-28 results

The preceding paragraph is a historical checkpoint. Luna subsequently authored the canonical `tests/Support/check-m06-inventory.php` using real independent financial activation and legitimate valued opening prerequisites. The lead repaired the damaged per-lot transfer receipt/quarantine key and source checks, then ran the authored check: **PASS, 113 explicit assertions**, immediate deferred constraints and outer rollback (`phase0-m06-current.txt`). Forging an accepted movement as damaged stock is denied without effects.

The final Laravel run with CLI OPcache disabled passes **40 tests / 103 assertions, 21.91s** (`phase0-laravel-final.txt`). The final existing standalone aggregate completes **20/21 PASS, one FAIL, zero incomplete, 786 explicit PASS assertions** (`safe-check-run-20261028T032733Z.json`). Its remaining direct-order readiness assertion expects the superseded unavailable approval contract; the separately authored direct-order compliant/exception action probe passes at migration 171 (`phase0-direct-sales.json`). The failure is retained, not hidden or counted as passed.

Scoped repaired-source/Support-helper Pint, final Blade compilation and build pass. Full-repository Pint retains 26 existing formatting violations, including applied migrations that remain untouched. Extended Inventory browser acceptance remains incomplete at run `98AE0564FA1E`; earlier bounded Sales/Purchasing browser evidence is separate. The mandated Luna authors hit their usage limit before the remaining harness/assertion and final browser readback updates. No new check code was authored by the lead to bypass that instruction. See `docs/release/PHASE0_STABILIZATION_REPORT.md` for the exact current decision and remaining work.
