# Production readiness audit evidence — 2026-11-01

This audit used private PostgreSQL 17.11 clusters only: source `erp_elec_new_test` at `127.0.0.1:49683` and restore clone at `127.0.0.1:49684`. Both owned PGDATA directories are marked under `.tmp/production-readiness-20261101/`. The development database snapshot was read-only. No application, configuration, schema, operational business data, deployment, or production activation changes were made. Audit helper copies and generated files are under ignored `.tmp/production-readiness-20261101/`.

## Results

- Clean migration: PASS, exit 0.
- Original full PHPUnit: FAIL, 41 passed, 16 failed, 111 assertions. Failures were `OptimizationRegressionTest` fixed-port guards rejecting the audit port.
- Existing broad allowlist: PASS, 21/21 command exits 0.
- Config, route and view cache smoke: PASS; 229 routes listed.
- M05/M06 roundtrip and inventory checks: PASS.
- Phase 2 Projects: PASS, 36 assertions.
- Phase 3 Maintenance: PASS, 26 assertions.
- Tender Actions: PASS, 58 passing assertions and explicit rollback terminal marker.
- Workshop Repairs Intake: INCOMPLETE; 25 lifecycle assertions passed. The existing harness's company-owned equipment path is stale against the current genuine inventory-source contract; that path and M09 financial integration were NOT TESTED.
- Financial negative checks: FAIL; FIN-P0-01, FIN-P0-02, FIN-P0-03 reproduced. All isolated action records rolled back and before/after counts matched.
- Phase 0 recovery seed: PASS; all 14 reported steps passed.
- Real `pg_dump`/`pg_restore`: PASS. Source and clone both had 92 migrations; table counts and relationships matched; 2 private files and 19 official PDFs matched persisted SHA-256; restored authorized controllers returned 200 and denied controllers 403.
- Previous-schema upgrade: NOT TESTED because no previous-schema artifact was available.

The first restore attempt hit the disposable Windows-default WIN1252 encoding. That failed attempt is preserved; only the owned empty restore database was recreated with UTF8 before the successful restore.

## Preservation evidence

`.env`, `.env.testing`, and audited accounting source hashes matched the initial snapshot. `public/build/manifest.json` and the public build tree differ from the initial snapshot; this audit did not edit or build those assets. The unexplained variance is recorded for parent review. See `preservation-before.json` and `preservation-after.json`.

Machine-readable result index: `audit-summary.json`. Detailed logs, source hashes, and fixture/restore evidence are alongside it. No secret values were emitted.
