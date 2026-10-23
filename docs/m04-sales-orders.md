# M04 Sales Orders — owner SO-01–SO-11

The 2026-10-21 owner decisions supersede older rules requiring complete stock before approval, one warehouse/delivery, invoice-at-order creation, payment before closure or direct edits of approved commercial data. Unaffected Quotation, Product and Customer contracts remain intact. Both supplied prompt files have identical SHA-256 content.

Sales owns order drafts, approved commercial versions, amendment requests, source conversion allocations and its lifecycle. Inventory owns reservations, availability, warehouses, physical issue, release and return disposition. Invoice/Accounting owns actual invoices, tax/rounding and financial adjustments. DeliveryReturns ownership of physical delivery/return execution must remain behind its owning contract; this slice creates no competing physical movement tables.

| Decision | Enforced rule / dependent action |
| --- | --- |
| SO-01 | Direct drafts independent of downstream services. Quotation conversion requires a real accepted issued revision, evidence and locked remaining quantities. Current quotation schema cannot produce accepted/issued revisions; conversion remains denied with no allocation. |
| SO-02 | Draft editing only; approved data requires immutable amendment versions and reapproval. No generic postapproval overwrite. |
| SO-03/04 | No draft reservation. Approval must request M06 reservation once; only confirmed M06 results establish reservations/backorders. No simulated balances. |
| SO-05/06 | Partial multiwarehouse delivery requires real scoped allocation/issue references, separate delivery note and manual confirmation. No creation means no delivered quantity. |
| SO-07 | One durable invoice request per confirmed delivery; requests never count as issued invoice coverage. |
| SO-08 | Cancel unexecuted quantities only; release must succeed before cancellation completion when reservations exist. This slice only cancels draft/pending orders with no approved operational effects. |
| SO-09 | Separately navigable returns require real confirmed deliveries and owner receipt/disposition/finance contracts; no stock increase on request. |
| SO-10 | Independent lifecycle/reservation/delivery/invoice states; no manual fully delivered/invoiced toggles. |
| SO-11 | Close only approved, fulfilled, actually invoiced orders after cleanup and no conflicting operations. Collection is not a prerequisite. |

## Executable slice contract

S01–S03/S05: branch-scoped direct drafts, exact unrounded proposed amounts, active customer/product/unit lookup, optimistic versioning plus row locks, list/filter/KPIs/export/details and append-only audited edits. Draft UUID is a technical identifier, not an official SO number; official numbering is unresolved. Branch and customer/product snapshots come through owning contracts. No source quotation is inferred for direct orders.

S04 independent review: submit/withdraw/reject with persisted content snapshot/hash, target version and actor/time. Owner decision B authorizes M01 dynamic `M04.sales_order_approve` for compliant orders. The security administrator selects the exception-responsibility role through audited, versioned `/sales-orders/policy` settings; its members additionally need `M04.sales_order_exception_approve` and branch scope. Selecting the role grants no privilege. Exceptions require an additional decision bound to the evaluated content, not replacement of normal approval. No default money/discount limits are invented. Self-approval stays denied. The implemented authority/configuration mechanism does not fabricate a compliant pricing evaluation or a successful exception decision: approved pricing/terms comparison, accounting tax/rounding, official numbering and M06 contracts remain unavailable, so actual approval/exception decisions remain BLOCKED. Submission is a request for review, never commercial approval. Rejector cannot reject their own request.

S06–S11 dependent execution remains BLOCKED, surfaced by scoped readiness review pages and server guards. No speculative movement/invoice/return/amendment tables are created before a usable producer exists. Direct route/mutation attempts reauthorize, check current version/state and fail closed. This is not completion of these operational slices.

M04 `sales_order_*` permissions use branch scope and require role/direct grants plus allowed branch. During the QA campaign the owner explicitly resolved the conflicting Administrator instructions: Administrator receives ALL current/future registered permissions, including Sales Orders; account verification, branch/warehouse scopes and business prerequisites remain mandatory. Other roles remain default-deny. The exception-responsibility role remains separately configured. Immediate revocation applies to queries, exports, mutation and navigation.

No full QA/Playwright/screenshot comparison or production activation. Do not call the full M04 feature complete. `.ai` remains unchanged.

## Delivery receipt — 2026-10-21

| Slice | Actual delivery |
| --- | --- |
| S01/S02 | IMPLEMENTED — QA DEFERRED: minimal order/line/review schema (120), scoped permission catalog, list/KPIs/filters/navigation |
| S03 | Direct draft create/edit IMPLEMENTED — QA DEFERRED. Quotation conversion BLOCKED on accepted issued revision producer; no conversion allocation created |
| S04 | Review submission/withdrawal/rejection and dynamic authority/settings (121) IMPLEMENTED — QA DEFERRED. Actual approval/reservation BLOCKED on real commercial/Inventory producers |
| S05 | Scoped details, four independent status indicators, lines/terms/audit/readiness sections IMPLEMENTED — QA DEFERRED |
| S06/S07/S08 | Formal amendments, actual delivery notes and invoice requests BLOCKED; their readiness screens are not operational implementation |
| S09 | Cancellation of never-approved draft/pending orders IMPLEMENTED — QA DEFERRED. Executed-order cancellation/release BLOCKED |
| S10/S11 | Independent Returns navigation/readiness and closure explanations delivered; actual return lifecycle and order closure BLOCKED |
| S12 | Authorized readonly SalesOrdersApi, shared WorkbookCodec export, owner-local factories, explicit repeat-safe SalesOrderDemoSeeder, essential checks integrated |

Reuse: existing shell/breadcrumbs/panels/stat cards/section cards/Flux forms/filters/tabs/pagination, exact DraftAmounts, Customer/Product contracts, private WorkbookCodec and System append-only audit. No shared DataTable/selector engine was claimed where none exists. No full quotation module copy.

Luna authored `.tools/check-m04-sales-orders.php` and `.tools/check-sales-order-factories.php`; lead runs/reviews them on the exact separate PostgreSQL `erp_elec_new_test` with rollback. Coverage includes exact drafts and ignored client totals, UUID retry idempotency, row/version guards, branch isolation/revocation/inactive-history behavior, immutable review snapshots/hash, withdrawal/rejection/cancellation, dynamic approval/exception responsibility settings, safe scoped XLSX export, append-only audit, authenticated route rendering, factory relationships and repeat-safe draft-only demo seeding. Concurrency primitives are checked through stale/replayed operations; a true multiprocess load/race suite remains deferred.

The demo seeder is not automatically invoked on the owner's development data. Explicit invocation requires `SALES_ORDER_DEMO_ACTOR_ID` for a real authorized actor; no privilege, fake operational transaction or security history is seeded. No official SO number is allocated in drafts.
