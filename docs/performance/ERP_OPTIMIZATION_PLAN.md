# ERP system-wide optimization plan

2026-10-31. Status: **COMPLETE LOCALLY — measured local candidate; not a production-readiness claim**.

## Baseline and acceptance contract

Preserve `before-optimization-20261031/` (seven original artifacts plus SHA-256 manifest) and original reports. Actual discovery: 210 GET routes, **194 named / 16 unnamed**, 146 eligible page routes / **166 expanded cases**, 162 fully measured. The owner's shorthand “210 named” is corrected without changing the route-case comparison set. Original warm UI medians/P95: desktop **696.5 / 1424.8 ms**, mobile **801.2 / 1872.1 ms**. Baseline source fingerprint: `4ec6a13e9c6e1e8b09e1258a64ce999f1329a0c6c5c5210f5d34d356cd97cb02`.

Every original case remains in comparison, including gated/error cases. Required page repaired from HTTP 500 has no invented BEFORE timing. Additional eligible operational pages are reported separately. Monitoring, PDF/download and transport routes keep explicit classifications; important authorized files/exports get separate operation checks.

## Isolation and evidence

- Reuse the existing canonical synthetic business graph on marker-owned PostgreSQL **55461 / erp_elec_new_test** for the after benchmark. No reset/reseed or financial fixture mutation. Any added authentication/session metadata is disclosed as such.
- Functional writes, posting, approvals, rollback and concurrency checks use the separate owned **55460** testing cluster and real owner Actions; never development port 5443 or operational data.
- Browser benchmark: Edge/Playwright, existing production Vite assets, local PHP server, no throttling, 1440×900 / 390×844, one cold plus three warm navigations, same primary-content/stability criteria. Preserve runtime/source fingerprints. Do not claim equivalence for an additional production-like experiment; report it separately.
- Disable Debugbar/Pulse for browser timings. Capture SQL separately using native Debugbar. BEFORE SQL is absent where the old audit did not collect it; never infer counts. Keep parameters, profiles and auth states private.
- All new automated test/check/benchmark code is Luna-authored; lead owns business/security integration. Preserve unrelated worktree changes and applied migrations. No deployment or production activation.

## Execution sequence

| Phase | Scope / decision | Status |
|---|---|---|
| A | Invalid project icon/tone repaired; all three 404 routes confirmed V2-gated and absent from navigation; safe legacy media response names preserve stored data and authorization | Focused regression passed |
| B/C | Request-local bulk RBAC reads across modules; current-request invalidation on writes/transaction boundaries; lazy hidden access children/history skips effective matrix; repeated account-directory checks removed | 14 focused tests / 168 assertions, Laravel 55/275, aggregate 21/21 with 875 explicit assertions; desktop lazy-tab browser gate passed |
| D | Inspect native SQL for every implemented module; actual isolated EXPLAIN for expensive read queries; batch/eliminate reads before adding justified forward-only indexes | 164/164 eligible route SQL profiles captured with URI ownership checks; 11 selected `EXPLAIN (ANALYZE, BUFFERS)` plans; M08 had no replayable domain SELECT in the selected candidates; no speculative index migration |
| E | Review all actual Livewire page families, hidden children, render queries, bounded selectors, reactive requests and hydration; preserve approvals and all controls | Source review complete; 36/36 SPA transitions and lazy User Details browser gate passed; one pagination probe remains failed/unavailable and is reported |
| F | Inspect Vite/CSS/JS/fonts/images/cache behavior and layout; repair broken assets; separate any changed build-mode experiment | Build/source review complete; 40 desktop config-cache off/on experiment samples are separate from primary results |
| G | Explicit review of every module/case; retain fast cases; no requirement to change already-bounded code without evidence | 166 original cases plus one additional eligible route measured or explicitly classified; module matrix generated |
| H/I | Search/filter/pagination/modal/tab/validation/draft/approval-denial/export interactions; document any reference/read caching keys/scope/invalidation; no stale financial/stock caches | Search/filter/form-init probes recorded; lifecycle actions measured once on isolated 55460; pagination selector probe failed after timeout; no unsafe business cache added |
| J | Source freeze, full after campaign, investigate material regressions, final QA/build/Blade/scoped Pint, generate all comparisons and final report | Complete: 1336 browser records, 1304 measured, 0 HTTP 500/JS/transport failures, 161 improved, 1 repaired, 1 not measurable, 3 gated; source unchanged |

Initial stretch UI budgets are desktop median/P95 <400/900 ms and mobile <500/1100 ms. These are aspirations, not permission to remove checks or required content. Report measured misses and environmental limits.

## Implemented read reuse and limits

`PermissionAccess` stores owner authorization read projections on the **current Request attributes**, keyed by connection name, database name, PDO identity, read type and actor/warehouse ID. No shared cache/Redis/static decision store was added. Lifetime is one HTTP request; all non-SELECT/SHOW/EXPLAIN statements invalidate the snapshot, as do transaction begin, commit and rollback, including nested rollback. Actor records are returned as clones. Production CLI/worker execution bypasses the memo because it has no HTTP request boundary. Active/verified owner guards, unknown-key default denial, explicit Administrator membership, direct-grant semantics, branch/warehouse scoping and inactive-branch restrictions remain tested.

This is not cross-request permission caching: external changes are observed on the next request. No mutable stock availability, outstanding AR/AP, GL amounts, fiscal-period status or document approval state is memoized. Branch/warehouse authorization projection mutations invalidate within the request; no TTL or broad business-cache invalidation model is necessary.

Other bounded changes: project progress uses one owner aggregate for all displayed projects with unchanged BCMath rounding/cancelled-phase rules; report callers reuse that batch. Workshop open-job counters use one authorized grouped query. Maintenance render reads assignments, visits, templates and status counters only on screens that display them, and avoids an irrelevant assignment subquery for actors without technician-limited branches. An unused gated-return sidebar permission read was removed. No feature gate was enabled.

Frontend review: the app JavaScript entry is empty, passkey code is separate (~12 KB), fonts are local with `font-display: swap`, product catalog images already use native lazy loading, and no global chart framework/third-party CDN bundle exists to remove. The existing Vite production build remains the benchmark asset mode; a config-cached experiment must be reported separately. The local PHP server's asset delivery is not evidence of production web-server compression/cache behavior.

## Coverage inventory

| Module label | Original cases | Required review |
|---|---:|---|
| M01 Organization | 13 | Users/roles/permissions/branches/profile/security, hidden tabs and RBAC revocation |
| M02 Products | 9 | Catalog/forms/prices/media/bulk/import; legitimate image/PDF response authorization |
| M03 Parties | 13 | Both party types, catalogs/forms/contact/document/financial tabs and scopes |
| M04 Sales | 19 | Quotation/order lifecycle screens, selectors, approval/delivery; gated returns retained |
| M05 Purchasing | 19 | Requisitions/RFQs/offers/orders/receipts; gated reports/returns retained |
| M06 Inventory | 25 | Warehouse/scope lookup, balances/reservations/documents/import, exact stock invariants |
| M07 Projects | 11 | Dashboard/tasks/documents/budget/closure/financial projections |
| M07.Tenders | 6 | Technical/financial/BOQ/guarantee and workflow views |
| M08 Field Maintenance | 12 | Repeated branch classification, requests/orders/visits/parts/labor/billing/reports |
| M09 Workshop Repairs | 8 | Workshop settings/dashboard/intake/jobs/custody/quote/QC/delivery paths |
| M11 Accounting | 13 | Setup/periods/journals and actual financial reports/reconciliation |
| M12 ReceivablesPayables | 8 | Invoice/AR/AP/aging/settlement/credit projections and authoritative totals |
| M13 Treasury | 5 | Accounts/collections/payments/movement authorization/reconciliation |
| M17 System | 5 | System dashboard, imports/exports/document services and shared shell |
| M10 / M15 | No eligible cases in original audit | Confirm current implementation/route ownership; report unimplemented honestly |

## Required verification and outputs

Focused failing checks precede affected fixes. Verify unknown/default-deny permissions, inactive/verification guards, Administrator membership policy, immediate role/direct-grant/branch/warehouse revocation (including same process/transaction rollback), cross-user/request separation, and private documents. Never cache authoritative stock, AR/AP, GL, period or approval state.

At final source freeze run existing Laravel/PostgreSQL/aggregate QA, FIFO, AP/AR and GL reconciliation, tender/workshop journeys, browser interactions and file/export denials, concurrency/rollback checks, Vite build, Blade compilation and scoped Pint once. Investigate failures; no full-suite rerun for every small patch.

Deliver `ERP_PAGE_SPEED_AFTER.json`, `ERP_PAGE_SPEED_COMPARISON.csv` (all 166 original keys), `ERP_MODULE_PERFORMANCE_MATRIX.md`, `ERP_LIVEWIRE_INTERACTION_BENCHMARK.md`, and `ERP_PERFORMANCE_FINAL_REPORT.md`. Include before/after timing/TTFB/query count/SQL duration where measured, network/errors, opportunity, acceptance and classifications **IMPROVED / UNCHANGED / REGRESSED / REPAIRED / GATED/DEFERRED / NOT MEASURABLE**. Report pooled P95, not a pretend per-page P95 from three warm samples. Keep raw data on disk and surface only compact failures/regressions/module summaries during execution.

The final runner reuses the original browser samplers and native Debugbar separately. Exact case/viewport/temperature/trial checkpoints and stage receipts allow interrupted runs to resume without repeating completed measurements. Resume rejects a changed source/config/build fingerprint. The source candidate remains `fddffec637f3654b8a72e996bffe640a54f977dd7b715973c07c9a3d565fe493`.
