# M15 Attendance — local acceptance

PR-SCOPE-002 is **NOT TESTED for complete release acceptance**. The module now implements factual attendance using M01 employees, without payroll calculations or labor-cost postings.

Implemented: scoped shifts and immutable scheduled terms; Cairo day/overnight and DST-aware UTC intervals; append-only punches; idempotent CSV imports; missing punches/absence/late/early/break facts; independently approved corrections, leave and bounded overtime; historical inactive employee identity; digest-bound locked monthly timesheets; private authorized CSV exports; factories and explicit idempotent planned demo records without invented punch/approval history.

`php -d opcache.enable_cli=0 tests/Support/check-m15-attendance.php` passed **47 assertions**, including import rollback, DST/overnight, month locks, correction history and exact fixture rollback. Unique expanded receipt is under `evidence/gate3/m15-attendance-acceptance-*.json`; the original41-assertion receipt remains historical. The final stdout filename records its execution time. No development database was used.

The five-viewport browser run passed37 aggregate M01/M15/M10 assertions. Private receipt `.tmp/master-remediation-20261101/ui-private/artifacts/2026-11-01T17-38-12-897Z-56432/receipt.json` includes actual employee creation, shift/schedule/punch persistence and disabled/revoked/read-only states. The browser run does not exercise every monthly approval/locking/export flow. Full final concurrency and operator acceptance remain open.

No salary formula, statutory rate, payroll approval, or payment was invented. Attendance exports facts; approved independent M14 deferral remains in force.
