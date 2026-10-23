# M05/M06 functional recovery — final review

Review date: 2026-10-22. Work is local; no remote publication or production activation. Neither module is fully complete. Historical implementation receipts are not acceptance evidence.

## Verified completion

The fixed denominator is the acceptance groups in [FUNCTIONAL_COVERAGE_MATRIX](M05_M06_FUNCTIONAL_COVERAGE_MATRIX.md). Each group has equal weight; dependency and policy blocks remain in the denominator. These percentages describe verified bounded requirements, not production readiness, line coverage or successful external integrations.

| Module | Verified groups | Verified percentage | Remaining classification |
| --- | --- | --- | --- |
| M05 Purchasing | 20 / 32 | 62.50% | 4 incomplete, 6 blocked by dependency, 2 blocked by owner decision |
| M06 Inventory | 26 / 34 | 76.47% | 3 incomplete, 1 missing, 2 blocked by dependency, 2 blocked by owner decision |

Inventory reservation groups verify the Inventory adapter using explicitly eligible isolated source fixtures. Real Sales approval/reservation/delivery remains separately blocked. Purchasing receipt draft and waiting-state tests do not verify posted receiving.

## Implemented and repaired

Eighteen defects are documented in [DEFECT_REGISTER](M05_M06_DEFECT_REGISTER.md), with expected/actual behavior, root cause, fix, severity and regression evidence. Principal changes:

- Counts retain the original stock snapshot/cutoff through repeated edits. Linked variance posting rejects intervening movements/reservations, including older linked adjustments.
- Ledger retry checks the complete immutable source identity. Nonzero movements, valid persisted lifecycle transitions, independent approval metadata and immutable document identity receive PostgreSQL protection. Empty documents cannot post.
- Completed transfer dispatch can be retried after receiving without duplicate ledger legs. Transfer list/export requires compatible authority at both endpoints; mixed operation grants cannot be combined across branches.
- RFQ, offer, order and receipt components retain the current locked ID/version after saving. Six document components use authoritative creation/predecessor metadata. Identical PO creation replay is canonicalized; changed retries reject.
- Direct internal PO approval now follows actual independent-reviewer capability and scope rules. Award checks remain required when an award is present. Approval revalidates active supplier/product/base unit; inactive supplier becomes a validation error without approval or HTTP500.
- Duplicate receipt submission preserves the same integration-pending request without another audit/stock effect. It does not fabricate receipt posting.
- Added authorized exact availability and read-only ledger/reservation reconciliation APIs and overview controls. Restricted balance-summary facts to balance/dashboard readers.
- Added real barcode identification for quantity count lines; duplicate scans do not add stock or counted quantity. Added permission-filtered genuine RFQ response links.
- Corrected Inventory and Purchasing XLSX filters and download scope. Inventory inherits shell direction. Preserved the existing Flux shell and visual identity.
- Repaired Purchasing migration function recreation after migrate:fresh. Original database protections remain enabled.

Actual persisted journeys verify warehouse, requisition, RFQ, supplier offer, PO, receipt draft, issue, transfer, count and adjustment repeated saves. They also verify independent approvals where supported, exact signed adjustments, full transfer dispatch/transit/receive, partial/zero/full reservations, settlement/release, factory persistence and idempotent synthetic demo seeding without fabricated authority or posting history. Amendment application is not among these verified workflows.

## Executed evidence

All business execution used the separate PostgreSQL database `erp_elec_new_test`; no SQLite substitution. New check/browser code was Luna-authored and reviewed by the lead. Action fixtures roll back; concurrency uses isolated schemas with production triggers and independent database connections. Browser temporary actor was disabled/unverified and sessions revoked; the subsequent full test schema reset removed tagged browser drafts.

| Execution | Final result | Evidence |
| --- | --- | --- |
| Roundtrip/Livewire/business integrity harness | PASS — 61 printed assertions | [roundtrip](evidence/m05-m06-roundtrip.log) |
| Inventory lifecycle, scopes, factories, import and reports | PASS — 69 printed assertions | [inventory](evidence/m05-m06-inventory.log) |
| Purchasing source/security/files/revisions/factories/demo edges | PASS — 39 printed assertions; CAMPAIGN_COMPLETE=YES | [summary](evidence/m05-recovery-edges.log), [runtime](evidence/m05-recovery-edges-runtime.log) |
| Concurrent reservation contention | PASS — one two-worker race | [reservation concurrency](evidence/m05-m06-reservation-concurrency.log) |
| Concurrent document posting | PASS — two races: same-document replay and competing insufficient-stock issues | [issue concurrency](evidence/m05-m06-issue-concurrency.log) |
| Full existing PHPUnit suite | PASS — 37 tests, 94 assertions | [log](evidence/m05-m06-phpunit.log), [JUnit](evidence/m05-m06-phpunit.xml) |
| Edge/Playwright browser | PASS — four layout journeys plus filtered Purchasing XLSX check; 8 screenshots | [result](evidence/m05-m06-browser/result.json) |
| Pint, Vite production build, Blade compilation | PASS | Terminal execution; browser result also records actual CSS/JS/font HTTP200 |

The focused harness total is **169 printed assertions**, separate from the 37 PHPUnit tests. Concurrency adds three race scenarios; do not count the scripts' summary lines as individual PHPUnit tests. Browser PO/count each persisted eight updates to version9. Desktop/mobile RTL and synthetic DOM-LTR smoke passed with Arabic locale. This is not English localization or exhaustive keyboard/browser acceptance. Build-time font resolution warnings did not prevent runtime font delivery.

No final executed check is failing. Earlier failures were retained: [initial migration rebuild failure](evidence/m05-m06-phpunit-before-migration-fix.log), and [edge harness missing-company fixture failure](evidence/m05-recovery-edges-runtime-before-fixture-fix.log). Both were corrected and rerun successfully; no defect expectation was relaxed to match incorrect behavior.

## Critical integration results and remaining work

| Journey | Actual result |
| --- | --- |
| M05 approved PO → receipt draft → submit/retry | PASS through real draft and stable waiting request; no stock changes or posted reference |
| M05 receipt → M06 stock + Accounting GRNI → receiving progress | BLOCKED — mandatory atomic stock/GRNI and valuation/period producers missing; receipt schema currently permits only draft/pending with NULL posting reference |
| Real M04 approval → reservation → delivery issue/confirmation/invoice | BLOCKED — eligible commercial/delivery producers missing. Inventory allocation/fulfillment adapter passes isolated harness and contention tests |
| Supplier return → real stock issue + supplier credit/AP | BLOCKED — posted original receipt and DeliveryReturns/Accounting/AP producers missing |
| Internal full exact warehouse transfer | PASS — source decreases at dispatch, destination increases at receive, transit clears, duplicate retries have no extra legs |

Other dependencies: official PO numbering/tax/issue, applied immutable amendment revisions, supplier-invoice matching and evidence-backed invoicing progress. Remaining implementation includes serial occupancy/bin/condition pools, complete operational reports/PDF producer, external receipt workflow and full UI acceptance. Source-only XLSX improvements are not complete receiving/performance reports. Warehouse responsible-manager assignment has no approved relation/eligibility contract.

Exact owner decisions remain limited to standalone RFQ authority/quantity bounds; partial or multiple-supplier award allocation/approval; partial/damaged transfer receipt reconciliation; and source-linked reversal authority/financial effects. Detailed serial normalization/condition-transition and optional warehouse-manager rules are recorded separately. See [BUSINESS_LOGIC_GAPS](M05_M06_BUSINESS_LOGIC_GAPS.md) for precise questions; no whole module is classified as policy-blocked.

Next highest-priority engineering action: implement the approved Accounting period/journal and Inventory costing/GRNI producers, then the synchronous M05 receipt coordinator with durable idempotency, immutable posting reference and partial/final receiving reconciliation. This must precede claiming purchase receiving, supplier returns or receipt-dependent performance complete. It is a separate dependent-owner implementation, not a permission to bypass the financial contract.

## Supporting records

- [Coverage matrix and visible controls](M05_M06_FUNCTIONAL_COVERAGE_MATRIX.md)
- [Gaps and exact dependencies](M05_M06_BUSINESS_LOGIC_GAPS.md)
- [Integration contracts and authority](M05_M06_INTEGRATION_CONTRACTS.md)
- [Scenario catalog](M05_M06_SCENARIO_CATALOG.md)
- [Defect register](M05_M06_DEFECT_REGISTER.md)

Current source and contracts take precedence over older receipts. Concurrently arriving Purchasing import/proposal/factory changes were preserved, reviewed where relevant and not presented as fully accepted workflows. No credentials were copied into this report. No development database reset or synthetic privilege assignment was performed for acceptance evidence.
