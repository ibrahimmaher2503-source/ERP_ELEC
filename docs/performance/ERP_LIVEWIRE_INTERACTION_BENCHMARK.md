# Livewire interaction benchmark

Run: after-20261031200934. Browser: Microsoft Edge, 1440×900. Livewire update time is measured from the triggering action to response and pending-UI completion; browser navigation is separately recorded as form/page initialization.
Read probes are non-persistent. Validation and business mutations are not replayed on canonical 55461; the independent owner-action evidence below uses isolated 55460 and is not comparable with navigation timings.

| Module family | Route case | Interaction | Status | HTTP | Requests | Elapsed ms | Notes |
|---|---|---|---|---:|---:|---:|---|
| M11 Accounting | accounting.accounts|default | form/page-initialization | measured | 200 | 0 | 478.20000000298023 | — |
| M11 Accounting | accounting.accounts|default | search | measured | 200 | 1 | 590 | — |
| M01 Organization | appearance.edit|default | form/page-initialization | measured | 200 | 0 | 270.5 | — |
| M03 Parties | customers.index|default | form/page-initialization | measured | 200 | 0 | 338.79999999701977 | — |
| M03 Parties | customers.index|default | search | measured | 200 | 1 | 535 | — |
| M03 Parties | customers.index|default | filter | measured | 200 | 1 | 152 | — |
| M17 System | dashboard|default | form/page-initialization | measured | 200 | 0 | 231.90000000596046 | — |
| M12 ReceivablesPayables | financial.aging|default | form/page-initialization | measured | 200 | 0 | 272.79999999701977 | — |
| M12 ReceivablesPayables | financial.aging|default | search | measured | 200 | 1 | 482 | — |
| M12 ReceivablesPayables | financial.aging|default | filter | measured | 200 | 1 | 114 | — |
| M06 Inventory | inventory.dashboard|default | form/page-initialization | measured | 200 | 0 | 305.79999999701977 | — |
| M06 Inventory | inventory.dashboard|default | search | measured | 200 | 1 | 486 | — |
| M06 Inventory | inventory.dashboard|default | filter | measured | 200 | 1 | 140 | — |
| M08 FieldMaintenance | maintenance.dashboard|default | form/page-initialization | measured | 200 | 0 | 304.29999999701977 | — |
| M08 FieldMaintenance | maintenance.dashboard|default | search | measured | 200 | 1 | 448 | — |
| M08 FieldMaintenance | maintenance.dashboard|default | filter | measured | 200 | 1 | 122 | — |
| M02 Products | products.index|default | form/page-initialization | measured | 200 | 0 | 329 | — |
| M02 Products | products.index|default | search | measured | 200 | 1 | 615 | — |
| M02 Products | products.index|default | filter | measured | 200 | 1 | 228 | — |
| M02 Products | products.index|default | pagination | failed | — | 0 | 30015 | locator.click: Timeout 30000ms exceeded.
Call log:
[2m  - waiting for locator('#main-content').locator('[wire\\:click*="nextPage"],button[aria-label*="next" i],button[aria-label*="التالي"]').first()[22m
[2m    - locator resolved to <button type="b |
| M07 Projects | projects.dashboard|default | form/page-initialization | measured | 200 | 0 | 281.90000000596046 | — |
| M05 Purchasing | purchasing.dashboard|default | form/page-initialization | measured | 200 | 0 | 277.8999999910593 | — |
| M05 Purchasing | purchasing.dashboard|default | filter | measured | 200 | 1 | 154 | — |
| M04 Sales | quotations.index|default | form/page-initialization | measured | 200 | 0 | 400.80000001192093 | — |
| M04 Sales | quotations.index|default | search | measured | 200 | 1 | 572 | — |
| M04 Sales | quotations.index|default | filter | measured | 200 | 1 | 196 | — |
| M07 Projects (Tenders) | tenders.dashboard|default | form/page-initialization | measured | 200 | 0 | 245.8999999910593 | — |
| M07 Projects (Tenders) | tenders.dashboard|default | search | measured | 200 | 1 | 500 | — |
| M07 Projects (Tenders) | tenders.dashboard|default | filter | measured | 200 | 1 | 126 | — |
| M13 Treasury | treasury.accounts|default | form/page-initialization | measured | 200 | 0 | 274.5 | — |
| M09 WorkshopRepairs | workshops.dashboard|default | form/page-initialization | measured | 200 | 0 | 256.79999999701977 | — |

## Lifecycle action evidence

| Action | Status | Measurement source | Safety boundary |
|---|---|---|---|
| validation | passed (n=1, UI asserted; HTTP 200) | 55460 receipt 283b3b1f9ef8d22b; 2026-10-31T20:03:40.663Z → 2026-10-31T20:03:41.060Z; 397.62 ms | Separate fixture database; single observations only, no P95/statistical claim. |
| save-draft | passed (n=1, UI asserted; HTTP 200) | 55460 receipt 283b3b1f9ef8d22b; 2026-10-31T20:03:37.087Z → 2026-10-31T20:03:37.703Z; 616.37 ms | Separate fixture database; single observations only, no P95/statistical claim. |
| approval | passed (n=1, UI asserted; HTTP 200) | 55460 receipt 283b3b1f9ef8d22b; 2026-10-31T20:03:43.253Z → 2026-10-31T20:03:43.567Z; 313.42 ms | Separate fixture database; single observations only, no P95/statistical claim. |
| denial | passed (n=1, UI asserted; HTTP 200) | 55460 receipt 283b3b1f9ef8d22b; 2026-10-31T20:03:39.299Z → 2026-10-31T20:03:39.753Z; 453.39 ms | Separate fixture database; single observations only, no P95/statistical claim. |
| export | passed (n=1, UI asserted; HTTP download) | 55460 receipt 283b3b1f9ef8d22b; 2026-10-31T20:03:50.424Z → 2026-10-31T20:03:50.900Z; 476.18 ms | Separate fixture database; single observations only, no P95/statistical claim. |

Owner-action receipt: [livewire-operation-latencies-55460.json](../../.tmp/optimization-regression/livewire-operation-latencies-55460.json). The timestamps and UI assertions are retained; these timings are separate from the 55461 aggregate.

Available interaction categories:
- search
- filter
- pagination
- tab
- modal-open
- form/page-initialization
- validation
- save-draft
- approval
- denial
- export

Missing selectors or workflows are reported as unavailable; the runner does not fabricate interaction latency or submit guessed business forms.
