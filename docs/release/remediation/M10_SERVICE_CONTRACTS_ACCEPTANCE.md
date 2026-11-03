# M10 ServiceContracts — local acceptance in progress

Release finding PR-SCOPE-001 remains **NOT TESTED for complete release acceptance**. M10 now exists; this is no longer an absent-source claim. No production contract was activated. All action acceptance uses explicitly synthetic PostgreSQL fixtures and genuine owner Actions.

Implemented: M01 employee authority; private revision-bound signature evidence; independent review; signed activation; amendment/renewal/status history; equipment/site/visit/labor/part coverage; reservation and actual M08/M09 usage; tender award to unsigned draft; preventive schedule to actual M08 draft; M17 official documents; exclusive installments or measured BOQ claims; authoritative M12 invoice/collection/credit and M11 deferred/earned revenue/reversal; scoped contract report; local demo drafts and factory.

Executed evidence under `evidence/gate2/`:

| Check | Receipt | Result |
|---|---|---|
|M01 prerequisite|m01-employee-20261101T105351Z-d60e8b.json|13 assertions PASS|
|M10 core/factory/demo|m10-core-20261101T184423Z-272cdc.json|65 assertions PASS; exact quarter dates and rollback verified|
|M08/M09 actual work|m10-integrations-20261101T111021Z-74b3cb.json|30 assertions PASS|
|Real posted stock and part use|m10-parts-20261101T150610Z-23f53d.json|12 assertions PASS; excluded/excess usage atomic denials|
|Tender handoff|m10-tender-20261101T150002Z-f9f086.json|15 assertions PASS; unsigned draft only|
|Private/M17 documents|m10-documents-20261101T112009Z-d4fc10.json|9 assertions PASS before latest full-content PDF extension|
|Preventive signed schedule|m10-preventive-schedule-20261101T151246Z-d9c612cbbcd9.json|18 assertions PASS|
|Billing, real collection, earned/reversed revenue, correction, report and signed amendment denials|m10-billing-20261101T184633Z-2cc14f.json|39 assertions PASS; full M17 content and real nonzero dashboard values included|
|Two-process last visit|m10-last-visit-20261101T172935Z-98d97e.json|one reservation accepted, one422; disposable DB dropped; primary counts unchanged|

Commands: `php -d opcache.enable_cli=0 tests/Support/check-m10-service-contracts-{core,integrations,parts,tender,documents,billing}.php`, `check-m10-preventive-schedule.php`, `check-m10-last-visit-concurrency.php`, after sourcing the private Gate1 environment. Checks verify exact database identity/PGDATA/ownership before mutations. Earlier failed attempts are retained and are not PASS evidence.

Browser receipt: private `.tmp/master-remediation-20261101/ui-private/artifacts/2026-11-01T17-38-12-897Z-56432/receipt.json`, 37 assertions across M01/M15/M10 and five viewports. It verifies a persisted M10 draft/submission, tax basis and preventive inputs, read-only behavior and denied draft billing. It does not verify a signed/active billing browser journey.

Latest browser receipt `evidence/gate7/ui-dashboard-20261101T183912Z.json` has54 PASS across M01/M15/M10 and the real dashboard at all five viewports. Active financial browser acceptance remains separate work.

The draft factory persists realistic contract/revision/coverage/installment relationships and the idempotent demo preserves existing rows. Actual work usage, billing/measurement/claim/recognition, signature, dispatch and posting evidence is created through owner actions in the acceptance graph; direct factories must not fabricate approvals or source history merely to populate those tables. Additional draft financial factories remain an explicitly tracked delivery limit, not implied by the header factory.

Still required: active financial UI and final amendment/history chain acceptance; comprehensive financial/concurrent acceptance on the final fingerprinted candidate. The mandatory full tender→signed→M08/M09→billing chain has bounded action receipts, not one final release journey. Migrations201–220 were applied only to owned isolated databases. Development is not a migration/acceptance target.
