# Exact remaining release work

ERP classification: **NOT READY**. These tasks concern complete company use; M09/Tender completion remains a narrower delivery.

## P0

1. **FIN-P0-01, complete-company period close.** Fix `Accounting/Application/FinancialReports.php::closePeriod` so missing/partial reporting scope cannot skip company branches. Authorize the company close operation independently from record visibility, reconcile all company branches, coordinate the lock with posting, record immutable closure evidence, and add real negative/concurrent tests. Reproduction: `evidence/final-20261030/financial-release-risks.json`, period closed with zero report branches; all audit mutations rolled back.
2. **FIN-P0-02, historical source cutoffs.** Add authoritative dated projections to M12 AP/AR/GRNI-clearing, M05 GRNI and M06 FIFO valuation contracts; use the same cutoff as GL/Treasury. Verify historical reporting after later receipts, issues, invoices, returns, credits and settlements. Reproduction: historical GL 0.00 versus current FIFO 70.00 at a date with no earlier journals.
3. **Missing mandatory M10.** Deliver ServiceContracts as its own owner: approval/acceptance/activation, coverage/site/equipment entitlement, scheduling/execution/usage, billing/renewal/expiry and actual profitability. Then connect M08/M09/Tender through real contracts. Until then keep contract-covered repairs and M10 award conversion disabled.
4. **Missing mandatory M15.** Deliver Attendance: shifts/schedules, overnight check-in/out, leave/absence, independently approved overtime/corrections, monthly timesheets and scoped export. Do not relabel Reporting as Attendance.
5. **Verified target deployment and recovery.** Establish the real host, HTTPS/session/proxy policy, production secrets/roles, private storage, monitored scheduler/workers/logging and offsite backups; execute a clean install and measured final-schema restore. Complete `FINAL_DEPLOYMENT_CHECKLIST.md` with target evidence.
6. **Company activation inputs.** Obtain actual legal/tax identity, Go-Live date, validated tax rules, accounting mapping/periods, banks, customers/suppliers, workshops, warehouses, counted stock/FIFO and approved opening AP/AR/GL balances. Reconcile before activation; never activate synthetic financial data or invent rates/names/balances.

## P1

- Finish current M09/Tender authenticated browser/race/rollback/regression acceptance and final reports. Source files, old PASS messages and screenshots alone are insufficient.
- Review full Sales/Purchasing business scope: physical returns and purchasing report routes are gated by V2; statutory/credit corrections do not implement physical return logistics.
- Complete current browser acceptance for the complete Sales quotation→official document→order→reserve/deliver→invoice→collection chain and Field request→closure chain. Record direct-URL denials and private PDF/export access for ordinary roles.
- Resolve any concrete all-route UI defects recorded in `FINAL_UI_REVIEW.md`, including failure/empty/loading/keyboard states and mobile document/table usability; do not replace the approved Arabic RTL shell.
- Establish the real bank-statement matching requirement. Existing Treasury bank/cash-to-GL reconciliation is not a bank statement line matcher; broader Treasury PRD workflows need explicit implementation evidence.
- Reconcile fixed-owner specification scope for absent Payroll and Reporting. Existing module reports remain real but do not implement separate missing owners.
- Replace generic/unavailable dashboard/global-search promises only with actual producers. Do not fill surfaces with fake financial or stock data.
- Update `.ai/HANDOFF.md` and final release documentation to the actual schema/capability decisions and evidence; review/pin a release including all dirty/untracked work, with no accidental overwrite of concurrent work.
- Complete full target document/tax applicability review. A System PDF prefix, locally hashed PDF or a configured test tax rule does not prove legal tax submission or external portal delivery.

## Shortest path

Finish and independently accept M09 and Tenders first; then correct the two reproduced financial defects. Deliver M10 and M15 in their bounded owners, connect only supported contracts, and close the documented release scope. Rehearse a clean deployment, restore, opening balances and operator workflows with a synthetic company before supplying approved real-company activation inputs. Re-run the final readiness audit against that exact artifact and target environment.

No general owner approval is pending for the already authorized M09/Tender implementation. Real workshop names, company tax settings, actual balances and external tender submission evidence remain business inputs; they were not invented.
