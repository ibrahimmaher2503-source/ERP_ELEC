# Company configuration and operator acceptance

PR-CFG-001 and PR-TRN-001 remain **BLOCKED BY EXTERNAL DEPENDENCY**. Local synthetic test setup does not approve a real company, legal identity, tax treatment, opening balances, Go-Live date, operating permissions or training completion. Development/production activation has not occurred.

Actual company checklist requiring owner/operator evidence:

1. Legal identity/address, branch and warehouse list, employee authority and user-role/scope matrix.
2. Validated product/unit/serial catalog, party identifiers, agreed contractual tax/price terms and account mappings.
3. Reconciled opening GL, stock/serials, AP/AR, cash/bank and approved company-wide date; independent opening review through the real M11 process.
4. Named operators and role-based real journeys covering sales/purchasing/returns, stock/serial custody, cash/advances/corrections, contracts/field/workshop and attendance exceptions/month close.
5. Recorded expected/actual source IDs and financial/stock reconciliation, defects and retests; signed operator/owner decision with date.
6. Rehearsed cutover freeze/import reconciliation, rollback/recovery ownership, incident escalation and post-cutover monitoring.

Use the real import validators and owning Actions; preserve rejected rows and source references. Do not fill missing values with demo data, create approvals on the owner's behalf, or claim training from screenshots. Existing `ops/vps/README.md` supplies a local operational procedure to adapt once a target and named operators exist. Actual signed UAT and measured recovery/cutover evidence remain absent.
