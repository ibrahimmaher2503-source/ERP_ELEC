# ERP optimization acceptance receipt

Date: 2026-10-31 (Africa/Cairo)  
Acceptance scope: local test databases and browser journeys only. No application source was changed for this acceptance. Sanitized results are in [ERP_OPTIMIZATION_QA.json](ERP_OPTIMIZATION_QA.json).

## Frozen source and baseline

The frozen application SHA-256 supplied for this run is `fddffec637f3654b8a72e996bffe640a54f977dd7b715973c07c9a3d565fe493`. The existing local preflight receipt records matching start and end fingerprints at `.tmp/page-speed-audit/after/preflight-20261031190120/preflight.json`. Acceptance edits were limited to tests and this receipt.

Existing results retained without rerunning the suites: full Laravel **55 tests / 275 assertions**; `OptimizationRegressionTest` **14 / 168**; safe QA aggregate **21/21 checks, 875 PASS assertions**. The aggregate includes `check-m01-rbac.php` actor/snapshot rendering evidence. The new tender classification check is reported separately as **1 test / 9 assertions**; it is not included in the earlier 55-test total.

## Browser and database acceptance

- User Details lazy tabs passed at 1440×900 on marker-owned PostgreSQL `erp_elec_new_test@55461`. The initial page left access and history unmounted; selecting access loaded its controls; selecting history loaded the history section and truthful empty state. No business records were written. The canonical history fixture had no actor-bearing rows, so the browser could not display a joined actor; actor rendering and snapshot normalization are covered by the existing M01 RBAC check.
- On isolated `erp_elec_new_test@55460`, the existing critical run reported **2/3 passed**: M05 financial/inventory and M09 workshop passed. M08 initially failed because its outsider probe requested `/reports/undefined` before a completion existed, so the numeric route constraint correctly returned 404 before authorization. The test now probes the outsider after closure using the persisted completion ID. The targeted M08 rerun passed **1/1**, with no runner gaps. Thus M05, M09, and corrected M08 each have a passing browser result, though there is no single clean combined 3/3 run after that test-only correction.
- The combined run also reported a harness-wide source fingerprint warning. During that run, only these unrelated E2E page-speed files changed: `tests/E2E/support/explain-captured-queries.php` (22:03:02), `tests/E2E/native-performance-profile.cjs` (22:03:13), and `tests/E2E/support/page-speed-after.cjs` (22:03:36), Cairo time. The critical spec and fixture were unchanged during that run. The corrected, targeted M08 run completed after the E2E files were frozen.
- M05 verified persisted supplier/customer invoice validation, supplier approval and posting, AP/AR balances, balanced journals, exact FIFO receipt/sale allocation and zero remaining stock, and private PDF content/hash. The same journey saved a UI purchase-order draft and had an independent reviewer reject it.
- The M06 two-worker PostgreSQL concurrency check passed on 55460 in its private schema/transaction: workers reserved 8 and 2 from 10, available stock stayed at zero, and the check asserted public table row counts were unchanged.
- The new tender regression check passed on 55460 (1/9). It verifies scoped tender read, technical-document visibility with financial-document exclusion, financial-tab and financial-file denial without financial scope, and technical-file access. Tender award through handoff remains untested.

## Live operation timings

These are single-run samples, not averages. Each interval starts immediately before the user action and ends after the corresponding UI or downloaded-file assertion. Raw UTC start/end marks and assertion labels are in [ERP_OPTIMIZATION_QA.json](ERP_OPTIMIZATION_QA.json).

| Operation | Elapsed | Completion evidence |
|---|---:|---|
| Save draft | 616.37 ms | Saved PO route and visible draft status |
| Denial | 453.39 ms | Independent reviewer rejection and visible rejected status |
| Validation | 397.62 ms | Validated invoice exposed the next matching action |
| Approval | 313.42 ms | Approved invoice exposed the posting action |
| Export | 476.18 ms | UI PDF link downloaded the matching private PDF |

## Limits

The browser history dataset had no joined actor row to display. Tender read/classification and download gates passed, but award and handoff were not exercised. Operation timings are five one-off measurements on the local isolated test environment; they are not production latency claims.
