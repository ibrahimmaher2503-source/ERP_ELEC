# ERP performance comparison — final report

After run: after-20261031200934. Baseline: mvwmlhzx-nba600.
Source fingerprint and freeze marker are in the after JSON. Manual 14-family source review and explicit limits: [ERP_SOURCE_REVIEW.md](ERP_SOURCE_REVIEW.md). Actual read-only EXPLAIN plans are reported separately when captured SQL can be safely replayed with synthetic bindings.
Only uninstrumented Playwright samples are used for timing comparisons. SQL profiles are separate Debugbar requests. Per-page P95 is withheld for n=3; aggregate P95 is shown only for pools with at least 20 samples. Aggregate before/after pools use only original route cases measured in both runs; repaired and additional cases are reported separately.

## Aggregate page timings

| Viewport | Before samples | Before median / P95 ms | After samples | After median / P95 ms |
|---|---:|---:|---:|---:|
| desktop | 483 | 696.5 / 1424.8 | 483 | 261.1 / 421.5 |
| mobile | 483 | 803.6 / 1872.1 | 483 | 272.1 / 469.6 |

## SPA navigation transitions

Six original sidebar routes × desktop/mobile × three trials. These are same-document Livewire navigation timings from click to `livewire:navigated`; they are reported separately from full-page UI-ready timings.

| Route | Viewport | Samples | Passed | Median transition ms | Failed/unavailable |
|---|---|---:|---:|---:|---:|
| customers.index | desktop | 3 | 3 | 495.8 | 0 |
| customers.index | mobile | 3 | 3 | 425.6 | 0 |
| suppliers.index | desktop | 3 | 3 | 462.8 | 0 |
| suppliers.index | mobile | 3 | 3 | 472.1 | 0 |
| products.index | desktop | 3 | 3 | 473 | 0 |
| products.index | mobile | 3 | 3 | 434.2 | 0 |
| organization.company | desktop | 3 | 3 | 347.3 | 0 |
| organization.company | mobile | 3 | 3 | 301.1 | 0 |
| organization.users | desktop | 3 | 3 | 471.9 | 0 |
| organization.users | mobile | 3 | 3 | 403.5 | 0 |
| sales-orders.index | desktop | 3 | 3 | 436.3 | 0 |
| sales-orders.index | mobile | 3 | 3 | 622.3 | 0 |

## Actual SQL query plans

Selected captured business SELECTs use actual synthetic bindings, a read-only transaction, `EXPLAIN (ANALYZE, BUFFERS)`, and a 3-second per-statement timeout. Raw SQL, bindings, and full plans remain private under `.tmp`.

| Module | Status | Route | Execution ms | Actual rows | Shared hit/read blocks | Seq scans | Index scans | Sort keys |
|---|---|---|---:|---:|---:|---|---|---|
| M11 Accounting | explained | accounting.reconciliation|default | 0.382 | 1 | 6 / 0 | rp_supplier_invoice_lines, rp_supplier_invoices | rp_supplier_invoice_matches.rp_supplier_invoice_matches_invoice_line_id_receipt_line_id_key | — |
| M01 Organization | explained | organization.branches|default | 0.041 | 1 | 1 / 0 | branches | — | — |
| M03 Parties | explained | customers.show|financial | 0.06 | 1 | 1 / 0 | rp_customer_invoices | — | — |
| M17 System | explained | operations.show|default | 0.048 | 1 | 1 / 0 | projects, project_members | — | — |
| M12 ReceivablesPayables | explained | financial.invoices.show|customer | 0.03 | 1 | 1 / 0 | projects, project_members | — | — |
| M06 Inventory | explained | inventory.document.show|transfer | 0.155 | 100 | 15 / 0 | — | products.products_pkey | — |
| M02 Products | explained | products.index|default | 1.393 | 20 | 131 / 0 | product_media | — | id |
| M07 Projects | explained | projects.financial|default | 0.071 | 0 | 1 / 0 | — | accounting_journals.accounting_journals_branch_date, accounting_journal_lines.accounting_lines_journal, accounting_project_dimensions.accounting_project_dimensions_pkey, accounting_accounts.accounting_accounts_pkey | j.id, l.id |
| M05 Purchasing | explained | purchasing.requisitions.show|default | 0.031 | 1 | 1 / 0 | projects, project_members | — | — |
| GLOBAL_AUTH | explained | purchasing.requisitions.show|default | 0.065 | 1 | 5 / 0 | users | — | — |
| M08 FieldMaintenance | no captured domain SELECT with replayable bindings | — | — | — | — / — | — | — | — |

Machine-readable public plan summary: [ERP_QUERY_PLAN_SUMMARY.json](ERP_QUERY_PLAN_SUMMARY.json).
Separate owner-action acceptance and latency evidence: [ERP_LIVEWIRE_INTERACTION_BENCHMARK.md](ERP_LIVEWIRE_INTERACTION_BENCHMARK.md).

## Result counts

- IMPROVED: 161
- NOT MEASURABLE: 1
- REPAIRED: 1
- GATED/DEFERRED: 3

The comparison table preserves all 166 baseline route+variant keys. Baseline 404 routes stay gated/deferred. The dashboard HTTP500 has no before timing and is classified repaired only if the after page renders; its missing baseline is not treated as a speedup.
New eligible route cases discovered after optimization are reported separately in ERP_PAGE_SPEED_NEW_ROUTES.csv (1); they are not mixed into the 166-case baseline comparison.
SQL profile fields are null when no matching before profile exists. Duplicate-query flags are evidence for review, not by themselves proof of an N+1 defect.

## Final measured outcome and priorities

The primary browser campaign retained **1,336 records** for 166 original route cases plus one additional eligible password-confirmation route. **1,304 navigation samples measured successfully**; the remaining records are the three intentional V2 gates, the repaired/unavailable cases, and the preserved `operations.show` fixture attempts. There were **0 HTTP 500 responses, 0 JavaScript-error samples and 0 failed-network samples** in the valid page campaign. The eight first `operations.show` attempts remain `NOT MEASURABLE` because their actor-owned preview receipt did not belong to the fresh benchmark actor; a guarded preview-only fixture was created separately during SQL recovery and was not substituted into the timing comparison.

The main measured bottleneck before the changes was shared authorization work: `organization.users.show` fell from 3,724 to 21 captured SQL statements and from roughly 2,038 ms to 138 ms of database time. Other improvements came from deferring hidden access/history children, batching project progress, grouping workshop counts, and avoiding irrelevant maintenance datasets. The remaining measured limits are the bounded per-line transit receipt lookup in M06, project-report financial summaries, large detail collections, and the failed pagination probe; these need owner-specific evidence before another change.

Highest-priority follow-up is to batch the M06 transit receipt aggregate while preserving accepted-plus-damaged BCMath subtraction, then measure project-report financial summaries at production-like cardinality. Review the M02 product-media sequential scan and M11 reconciliation scans only if real volume makes them material. Do not add broad caching for authorization, stock, AR/AP, GL, periods or approvals.

The stretch budgets were met in this local run: comparable desktop warm median/P95 **261.1 / 421.5 ms** and mobile **272.1 / 469.6 ms**. These figures are local PHP built-in server and isolated PostgreSQL results, with no network throttling; they are not production capacity or deployment evidence.

## Largest warm median regressions and improvements

| Route case | Class | Before desktop/mobile | After desktop/mobile | Query count before → after | DB ms before → after |
|---|---|---:|---:|---:|---:|
| organization.users.show|default | IMPROVED | 3283.2 / 2991.4 | 232.2 / 245.8 | 3724 → 21 | 2037.91 → 138.04 |
| maintenance.work-orders.show|default | IMPROVED | 2646.3 / 2943.2 | 395.2 / 594.5 | 1433 → 0 | 831.25 → 0 |
| maintenance.index|default | IMPROVED | 1066 / 2593.2 | 256.9 / 281.4 | 786 → 0 | 465.96 → 0 |
| sales-orders.create|default | IMPROVED | 719.3 / 2351.1 | 265.3 / 290.1 | 367 → 16 | 240.54 → 55.91 |
| sales-orders.screen|amendment | IMPROVED | 550.6 / 2348.1 | 228.6 / 389.4 | 413 → 24 | 286.17 → 65.86 |
| inventory.document.create|opening | IMPROVED | 832.4 / 2162.7 | 256.7 / 277.2 | 329 → 14 | 233.28 → 55.59 |
| inventory.document.create|receipt | IMPROVED | 619.1 / 2146.4 | 271.8 / 311.8 | 329 → 14 | 218.21 → 53.6 |
| projects.index|default | IMPROVED | 735.7 / 1872.1 | 240.4 / 227.1 | 318 → 17 | 224.13 → 85.2 |
| customers.index|default | IMPROVED | 1869.5 / 1295.8 | 330.4 / 324.2 | 341 → 22 | 231.26 → 69.19 |
| purchasing.receipts.create|default | IMPROVED | 525.4 / 1872.2 | 210.8 / 365.1 | 326 → 17 | 218.04 → 94.43 |
| organization.branches|default | IMPROVED | 1730 / 780.6 | 243.6 / 256.9 | 376 → 17 | 438.64 → 196.96 |
| sales-orders.edit|default | IMPROVED | 689.1 / 1805.1 | 261.2 / 361.2 | 416 → 19 | 274.6 → 57.28 |
| maintenance.requests|default | IMPROVED | 1621.7 / 1369.3 | 255.8 / 284.7 | 1226 → 0 | 701.84 → 0 |
| inventory.warehouses|default | IMPROVED | 687.5 / 1526.7 | 234.4 / 271.6 | 346 → 13 | 237.32 → 51.57 |
| projects.tasks|default | IMPROVED | 704.1 / 1512.1 | 287.9 / 292.1 | 404 → 24 | 280.88 → 154.4 |
| inventory.document.create|adjustment | IMPROVED | 1468 / 1152.5 | 256.5 / 260.2 | 329 → 14 | 229.48 → 53.02 |
| projects.financial|default | IMPROVED | 591.1 / 1437.1 | 229.8 / 255.4 | 415 → 35 | 282.8 → 206.58 |
| maintenance.reports|default | IMPROVED | 1387.7 / 1043.4 | 243.6 / 285.2 | 786 → 0 | 481.78 → 0 |
| maintenance.schedule|default | IMPROVED | 1394.4 / 1055.1 | 254 / 393.4 | 786 → 0 | 472.8 → 0 |
| maintenance.dashboard|default | IMPROVED | 1326.2 / 1313.5 | 237.2 / 250.6 | — → 0 | — → 0 |

## desktop fastest 10 and slowest 20 after pages

| Rank | Route case | Warm median UI ready ms |
|---:|---|---:|
| 1 | password.request|default | 178 |
| 2 | login|default | 192 |
| 3 | products.import|default | 196.7 |
| 4 | sales-orders.policy|default | 202.2 |
| 5 | purchasing.comparison|default | 202.4 |
| 6 | profile.edit|default | 205.7 |
| 7 | purchasing.offers.index|default | 205.7 |
| 8 | purchasing.rfqs.index|default | 206.7 |
| 9 | suppliers.import|default | 207.6 |
| 10 | quotations.policy|default | 208.6 |
| … | … | … |
| 142 | customers.show|financial | 341.8 |
| 143 | inventory.document.show|adjustment | 349.5 |
| 144 | accounting.income-statement|default | 350.1 |
| 145 | accounting.ppv|default | 356 |
| 146 | accounting.periods|default | 365.1 |
| 147 | customers.create|default | 368.9 |
| 148 | inventory.document.show|transfer | 377.3 |
| 149 | accounting.dashboard|default | 380.8 |
| 150 | accounting.balance-sheet|default | 389.5 |
| 151 | organization.roles.show|default | 389.7 |
| 152 | maintenance.work-orders.show|default | 395.2 |
| 153 | accounting.accounts|default | 395.4 |
| 154 | organization.roles|default | 399.5 |
| 155 | accounting.grni|default | 411.1 |
| 156 | workshops.jobs.show|default | 420.7 |
| 157 | workshops.intakes.show|default | 425.2 |
| 158 | accounting.trial-balance|default | 438.4 |
| 159 | accounting.setup|default | 440.2 |
| 160 | workshops.intakes|default | 525.8 |
| 161 | products.index|default | 549.1 |

## mobile fastest 10 and slowest 20 after pages

| Rank | Route case | Warm median UI ready ms |
|---:|---|---:|
| 1 | password.request|default | 188 |
| 2 | organization.company|default | 206.1 |
| 3 | operations.import|products | 211 |
| 4 | financial.invoices.show|customer | 216.2 |
| 5 | treasury.accounts|default | 217.7 |
| 6 | inventory.issues|default | 218.4 |
| 7 | profile.edit|default | 219.7 |
| 8 | inventory.adjustments|default | 220.5 |
| 9 | purchasing.offers.index|default | 222.3 |
| 10 | products.prices|default | 222.5 |
| … | … | … |
| 142 | appearance.edit|default | 368.9 |
| 143 | treasury.collections|default | 377.3 |
| 144 | sales-orders.screen|delivery | 377.7 |
| 145 | suppliers.create|default | 383.1 |
| 146 | projects.documents|default | 384.8 |
| 147 | sales-orders.screen|amendment | 389.4 |
| 148 | maintenance.schedule|default | 393.4 |
| 149 | accounting.grni|default | 397.1 |
| 150 | organization.roles.show|default | 397.9 |
| 151 | sales-orders.screen|closure | 404.7 |
| 152 | purchasing.dashboard|default | 404.8 |
| 153 | customers.create|default | 406.5 |
| 154 | accounting.trial-balance|default | 411.3 |
| 155 | suppliers.index|default | 438.6 |
| 156 | maintenance.work-orders.create|default | 442.4 |
| 157 | customers.show|financial | 471.5 |
| 158 | accounting.dashboard|default | 499.1 |
| 159 | organization.roles|default | 507.5 |
| 160 | maintenance.work-orders.show|default | 594.5 |
| 161 | products.index|default | 661 |

## Correct ranked after lists

The generated dashboard now ranks by the actual warm median (rank 1 is fastest or slowest as labelled) and includes TTFB, load, failure count and the recorded opportunity for every row.

### Desktop fastest 10

| # | Route | Module | UI ms | TTFB ms |
|---:|---|---|---:|---:|
| 1 | password.request|default | M01 Organization | 178 | 35.3 |
| 2 | login|default | M01 Organization | 192 | 43 |
| 3 | products.import|default | M02 Products | 196.7 | 168.1 |
| 4 | sales-orders.policy|default | M04 Sales | 202.2 | 170.3 |
| 5 | purchasing.comparison|default | M05 Purchasing | 202.4 | 167.1 |
| 6 | profile.edit|default | M01 Organization | 205.7 | 175.4 |
| 7 | purchasing.offers.index|default | M05 Purchasing | 205.7 | 167.9 |
| 8 | purchasing.rfqs.index|default | M05 Purchasing | 206.7 | 171 |
| 9 | suppliers.import|default | M03 Parties | 207.6 | 173.8 |
| 10 | quotations.policy|default | M04 Sales | 208.6 | 174.9 |

### Desktop slowest 20

| # | Route | Module | UI ms | TTFB ms |
|---:|---|---|---:|---:|
| 1 | products.index|default | M02 Products | 549.1 | 507.2 |
| 2 | workshops.intakes|default | M09 WorkshopRepairs | 525.8 | 426.5 |
| 3 | accounting.setup|default | M11 Accounting | 440.2 | 388.5 |
| 4 | accounting.trial-balance|default | M11 Accounting | 438.4 | 353.1 |
| 5 | workshops.intakes.show|default | M09 WorkshopRepairs | 425.2 | 372.2 |
| 6 | workshops.jobs.show|default | M09 WorkshopRepairs | 420.7 | 352.1 |
| 7 | accounting.grni|default | M11 Accounting | 411.1 | 324.2 |
| 8 | organization.roles|default | M01 Organization | 399.5 | 309.7 |
| 9 | accounting.accounts|default | M11 Accounting | 395.4 | 353.1 |
| 10 | maintenance.work-orders.show|default | M08 FieldMaintenance | 395.2 | 361.4 |
| 11 | organization.roles.show|default | M01 Organization | 389.7 | 300 |
| 12 | accounting.balance-sheet|default | M11 Accounting | 389.5 | 302.2 |
| 13 | accounting.dashboard|default | M11 Accounting | 380.8 | 317.4 |
| 14 | inventory.document.show|transfer | M06 Inventory | 377.3 | 331.7 |
| 15 | customers.create|default | M03 Parties | 368.9 | 328.9 |
| 16 | accounting.periods|default | M11 Accounting | 365.1 | 311.2 |
| 17 | accounting.ppv|default | M11 Accounting | 356 | 310 |
| 18 | accounting.income-statement|default | M11 Accounting | 350.1 | 274.5 |
| 19 | inventory.document.show|adjustment | M06 Inventory | 349.5 | 286.6 |
| 20 | customers.show|financial | M03 Parties | 341.8 | 301.1 |

### Mobile fastest 10

| # | Route | Module | UI ms | TTFB ms |
|---:|---|---|---:|---:|
| 1 | password.request|default | M01 Organization | 188 | 40 |
| 2 | organization.company|default | M01 Organization | 206.1 | 174.2 |
| 3 | operations.import|products | M17 System | 211 | 182.1 |
| 4 | financial.invoices.show|customer | M12 ReceivablesPayables | 216.2 | 178.5 |
| 5 | treasury.accounts|default | M13 Treasury | 217.7 | 185 |
| 6 | inventory.issues|default | M06 Inventory | 218.4 | 179.6 |
| 7 | profile.edit|default | M01 Organization | 219.7 | 187.5 |
| 8 | inventory.adjustments|default | M06 Inventory | 220.5 | 184 |
| 9 | purchasing.offers.index|default | M05 Purchasing | 222.3 | 182.5 |
| 10 | products.prices|default | M02 Products | 222.5 | 190.1 |

### Mobile slowest 20

| # | Route | Module | UI ms | TTFB ms |
|---:|---|---|---:|---:|
| 1 | products.index|default | M02 Products | 661 | 616.2 |
| 2 | maintenance.work-orders.show|default | M08 FieldMaintenance | 594.5 | 531.3 |
| 3 | organization.roles|default | M01 Organization | 507.5 | 457.8 |
| 4 | accounting.dashboard|default | M11 Accounting | 499.1 | 418.8 |
| 5 | customers.show|financial | M03 Parties | 471.5 | 421.7 |
| 6 | maintenance.work-orders.create|default | M08 FieldMaintenance | 442.4 | 359.9 |
| 7 | suppliers.index|default | M03 Parties | 438.6 | 398.9 |
| 8 | accounting.trial-balance|default | M11 Accounting | 411.3 | 332.8 |
| 9 | customers.create|default | M03 Parties | 406.5 | 339.1 |
| 10 | purchasing.dashboard|default | M05 Purchasing | 404.8 | 331.9 |
| 11 | sales-orders.screen|closure | M04 Sales | 404.7 | 339.8 |
| 12 | organization.roles.show|default | M01 Organization | 397.9 | 352.7 |
| 13 | accounting.grni|default | M11 Accounting | 397.1 | 323.6 |
| 14 | maintenance.schedule|default | M08 FieldMaintenance | 393.4 | 336.2 |
| 15 | sales-orders.screen|amendment | M04 Sales | 389.4 | 325.4 |
| 16 | projects.documents|default | M07 Projects | 384.8 | 318.5 |
| 17 | suppliers.create|default | M03 Parties | 383.1 | 334 |
| 18 | sales-orders.screen|delivery | M04 Sales | 377.7 | 316.5 |
| 19 | treasury.collections|default | M13 Treasury | 377.3 | 332.1 |
| 20 | appearance.edit|default | M01 Organization | 368.9 | 298.7 |

### Measurement caveat

The eight initial `operations.show` rows remain preserved as NOT MEASURABLE because the first diagnostic URL referenced an actor-owned preview receipt that was not owned by the fresh benchmark actor. The valid owner preview was created through the guarded operation service during SQL recovery; it is retained as a separate fixture evidence path and is not silently substituted into the 166-case timing comparison.
