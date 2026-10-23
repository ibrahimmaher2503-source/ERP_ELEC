# Quality & Stabilization campaign — VERIFIED PARTIAL

Date:2026-10-21. Local only; `.ai` unchanged. No operational database reset or fixture insertion by this campaign. No production activation or exhaustive security certification.

## Actual results

- Scope inspected: M01 Organization, M02 Products, M03 Customers/Suppliers, M04 Quotations/Sales Orders, shared System bulk/XLSX/audit. See [inventory](IMPLEMENTATION_INVENTORY.md).
-60 documented grouped business scenarios: [catalog](BUSINESS_SCENARIOS.md). These are scenario groups, not60 individually instrumented tests. Categories/edges are described within groups; exhaustive feature-by-feature coverage is not established.
- Latest guarded module run:21 executable check scripts,19 PASS,2 FAIL,0 incomplete;805 explicitly printed successful assertions. Assertions are not independent tests. Canonical receipt: [safe-check-run-20261021T181442Z.json](evidence/safe-check-run-20261021T181442Z.json).
- PHPUnit final result:36 PASS/1 FAIL/91 assertions; see [JUnit](evidence/phpunit-final.xml) and [console](evidence/phpunit-final.log). The failure is a PostgreSQL deadlock during test schema reset, so the earlier37 PASS/94 baseline is not the latest result. Exclusive test-database execution was not successfully maintained for this final attempt; lock-owner investigation/rerun remains pending. No code coverage instrumentation measured.
- Real Playwright attempts:2 preliminary checks completed before the form journey failed. The harness starts PHP with `public/index.php` as router, so static CSS/JS receive HTML; giant unstyled SVGs intercept clicks. This is a test harness defect, not a validated production sidebar defect. Remaining journey steps and mobile/keyboard visual parity NOT RUN. [Browser evidence](E2E_TEST_EVIDENCE.md).

## Remaining failed checks

1. `check-m01.php`: singleton fixture assumes an empty company table and passes null expected version when a baseline exists. Real stale-version rejection is correct. Harness correction pending; M01 cannot receive blanket PASS from this run.
2. `check-m03-customers.php`: final seeder security assertion assumes no pre-existing grants outside its actor rather than comparing before/after. Expected repeat-safe preservation requires snapshot comparison. Harness correction pending; Customer coverage remains partial.

Both failures remain recorded, not deleted or reclassified as PASS. Luna workers reached the account usage limit before final harness corrections; standing owner rule reserves all new/modified check code to Luna. Existing executed results are retained without claiming pending code was tested.

## Fixes and verification

See [defect register](DEFECT_REGISTER.md): audit-trigger migration repeatability, complete Administrator grants under explicit owner resolution, readonly SO capability assignment, immutable SO review history, inactive Product creation/import authorization, retrying failed expired-source deletion, Sales permission grouping, stronger test DB guard, truthful check completion classification, and obsolete fixture expectations. No Critical exploit demonstrated. One HIGH migration/test-recovery defect fixed; this is not a statement of zero undiscovered security issues.

## Module result

| Module | Final status / limit |
| --- | --- |
| M01 | VERIFIED PARTIAL: auth/roles/scopes/history guards tested; company aggregate script remains failed due singleton fixture |
| M02 | VERIFIED PARTIAL: catalog/conversions/media/import/bulk focused checks pass; broad browser journey not run |
| M03-A | VERIFIED PARTIAL: customer checks reach30 assertions, final preservation assertion fails; not blanket acceptance |
| M03-B | VERIFIED PARTIAL: supplier/shared Party focused checks pass; browser acceptance not run |
| M04 Quotations | VERIFIED PARTIAL for implemented draft/exception/internal-doc/XLSX checks; official issue/revision/PDF/acceptance/conversion BLOCKED |
| M04 Sales Orders | VERIFIED PARTIAL for draft/review/policy/cancellation/export; operational approval/reservation/amendments/delivery/invoicing/returns/closure BLOCKED |
| Shared System | VERIFIED PARTIAL: guarded bulk/XLSX/audit/cleanup regressions pass; no load/full multiprocess concurrency acceptance |

Dependency-blocked operational scenarios are listed separately in the scenario catalog. Their fail-closed tests do not make successful stock/invoice/conversion scenarios pass. No pass-rate or100% coverage claim.

## Reproduce (serially; never overlap E2E and database reset)

```powershell
php artisan test --log-junit=docs/qa/evidence/phpunit-final.xml
php .tools/run-safe-qa-checks.php
php .tools/check-qa-regressions.php
php -d phar.cache_list= -d opcache.enable_cli=0 vendor/bin/pint --test
npm run build
node tests/E2E/sales-orders.cjs
```

The E2E command currently reproduces the recorded harness failure; it does not certify the UI. Fix its static-asset routing using Laravel's existing development-server router, add asset content-type/computed-style preflight, then rerun on port8001/test PostgreSQL. Next priority: finish the two fixture corrections, rerun21 checks, extend actual browser workflows to M01–M03 and Quotations, then multiprocess lock/idempotency races for available operations. Do not invent missing producers to turn blocked rows green.
