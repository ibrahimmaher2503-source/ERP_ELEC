# Defect register — Quality & Stabilization

Severity describes observed impact, not a production exploit claim. All reproductions use isolated PostgreSQL `erp_elec_new_test` or disposable browser fixtures. No working database reset. A fix is not marked verified until its relevant regression completes.

| ID | Severity | Reproduction / root cause | Smallest fix | Verification |
| --- | --- | --- | --- | --- |
| QA-DB-001 | HIGH (test environment/recovery) | Full PHPUnit baseline:35 failures from duplicate `prevent_erp_audit_mutation()` after RefreshDatabase; PG functions survive dropped tables. Same risk for quotation approval function | Migrations010/113 use CREATE OR REPLACE with identical immutable bodies | Subsequent PHPUnit37 tests/94 assertions PASS; audit mutation negative checks retained |
| QA-AUTH-001 | MEDIUM | SO exclusion in PermissionAccess/Sync contradicted owner-resolved ALL Administrator grants | Restore full registered-key capability/sync; branch/account/business rules unchanged | SO regression asserts complete matrix, no scope denied, granted branch allowed |
| QA-RBAC-002 | MEDIUM | SaveRolePermissions wrongly classified four SO readonly keys as writes for ManagerReadOnly | Add only view/history/invoice_view/return_view to fixed readonly allowlist | Focused run advanced past assignment; remaining independent RBAC assertions undergoing stabilization |
| QA-DB-003 | MEDIUM | Direct UPDATE/DELETE of pending SO review snapshot succeeded; application hash detects but DB lacked quotation-equivalent history guard | Migration122 rejects identity/snapshot changes/deletion and changes to final decisions; requires attributed pending→withdrawn/rejected | SO154 assertions PASS including pending/final DB mutation rejection |
| QA-TEST-001 | MEDIUM (assurance) | Several scripts printed Laravel exceptions then exited0; initial runner misclassified partial scripts as PASS | Luna runner requires declared final marker and rejects exception diagnostics, retains exit/output evidence | Corrected receipt174358Z explicitly13 PASS/7 FAIL; earlier receipt provisional, not acceptance |
| QA-TEST-002 | LOW (fixture) | Existing positive password-change test used weak `new-password` despite approved mixed-case/numeric12-char rule | Luna positive fixture compliant + explicit weak-password rejection | PHPUnit37 PASS/94 assertions |

## Under investigation

- QA-PRODUCT-004: inactive Product creation/import appears to omit ordinary status permission. Shared import contract requires status privilege for status effects; dedicated rollback repro authored, execution pending. Not yet declared exploitable.
- QA-FILE-005: failed deletion of expired preview source may transition to a status cleanup never retries; dedicated simulated-storage-failure repro authored, execution pending.
- Historical tests assuming Administrator receives no Product/Quotation privileges conflict with latest explicit owner decision. Update those scenario identities to nonadministrator roles and retain negative authorization coverage; do not weaken security to satisfy obsolete assertions.
- NUL input validation across Party subrecords is a low robustness candidate (PostgreSQL rejects rather than field error); not a SQL injection claim. Not verified yet.

## Dependency gaps, not defects

Actual Inventory reservation/issue/release/return, actual invoicing and official quotation issue/numbering/PDF/acceptance/conversion lack owner producers. Readiness denial can PASS while operational success remains BLOCKED. No Critical vulnerability has been demonstrated; this does not imply exhaustive security certification.
