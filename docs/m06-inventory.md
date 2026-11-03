# M06 Inventory — executable contract

2026-10-27 continuation: [V1 financial activation](v1-financial-activation.md) provides the controlled valued opening path, atomic purchase receipt/FIFO/GRNI and internal issue/negative-adjustment FIFO/GL source code. Unvalued opening, positive adjustment, transfer and correction paths cannot bypass activation or missing costing. Historical quantity-only evidence below remains historical; no current financial acceptance is claimed.

Recovery 2026-10-22: the owner explicitly authorized regression testing. See [current matrix](qa/M05_M06_FUNCTIONAL_COVERAGE_MATRIX.md), [defect evidence](qa/M05_M06_DEFECT_REGISTER.md) and [integration boundaries](qa/M05_M06_INTEGRATION_CONTRACTS.md). Historical QA-deferred text below is not the current campaign result.

Count edits retain their original recorded quantities and cutoff. Intervening warehouse stock/reservation changes reject stale edits/approval and later linked-adjustment posting, including pre-existing linked adjustments. Migration146 adds persisted lifecycle/version/identity/count-cutoff and nonzero-movement guards. Transfer dispatch replay after full receiving returns the completed document. Movement replay validates complete source identity and unit, not only quantity/product/location.

InventoryApi.getAvailability returns separate exact on_hand, reserved, available and incoming in_transit without creating balances. InventoryApi.reconcileWarehouse compares the ledger and reservation residuals with projections under the Inventory lock; it requires all three view capabilities and scope, reports discrepancies and never edits balances. Overview controls invoke this real read operation. Screen and XLSX filters share implementation. Transfer listing requires the same capability at both endpoints; exports additionally require export scope at both.

Count barcode identification uses the existing Products-owned barcode lookup, M02.product_view and scoped M06.count_create. It supports quantity goods, requires explicit entry of the actual counted quantity and never increments quantities on repeated scans. Serial occupancy remains unavailable. Inventory pages inherit shell direction for RTL/LTR rather than forcing RTL.

Owner decision 2026-10-22: dynamic M01 approval permissions; document creator cannot approve own opening, adjustment, transfer or issue. Administrator retains all capabilities but explicit branch and warehouse scopes remain required. No automatic scopes.

Quantities are canonical base-unit decimal strings NUMERIC(24,8). Available is generated on_hand-reserved; nonnegative constraints and row locks serialize allocations. Posted movement ledger is append-only; balance projection changes in the same transaction. No costing/financial values.

Warehouse belongs to a fixed branch. Organization owns user_warehouse_scopes and checks both branch and warehouse scopes. Warehouse directory administration is company scoped, stock facts are warehouse scoped. No destructive warehouse delete. Inactivation requires no stock, outstanding reservations or live documents.

Opening, issue, transfer and adjustment documents: draft -> submitted -> approved by a different actor -> posted (transfer dispatched -> received). Opening and adjustment require explicit reason. Receipt sources from Purchasing/returns/service are unavailable until the owning source eligibility contract exists. No user supplied source claims can bypass this. Quantity-only products supported; serial-tracked posting is blocked pending serial occupancy support.

Counts freeze recorded quantities and warehouse ledger cutoff when created; submitted counts do not mutate stock. Approval creates a linked adjustment only if no warehouse movement/reservation has intervened; adjustment uses its own approval and posting. No silent overwrite. Transfer receipt is full and exact; partial/damage handling remains blocked pending policy.

Reservation commands are idempotent, consume only available quantities and report unreserved demand. Source validation is performed via the Sales owner contract; unapproved Sales drafts cannot reserve. Fulfillment atomically reduces matching reservation and physical balance. Release never restores physical issued stock. Backorder remains Sales-owned.

Imports never overwrite balances. Scoped XLSX exports reuse WorkbookCodec. Valuation, accepted quotation conversion, purchasing receipt eligibility and customer delivery are separate dependencies.

Status: PARTIALLY IMPLEMENTED — QA DEFERRED. Quantity-only independent workflows function locally. Migrations130–132 and the implementation receipt in EXECUTION_PIPELINE.md describe actual evidence and remaining dependencies.

The release adapter requires an eligible cancellation from the Sales owner; generic warehouse authority cannot silently release a live Sales commitment. Amendments remain blocked until their owner producer exists. Warehouse scope navigation uses a bulk Organization projection and reauthorizes each mutation. Private template imports preview only; files are removed immediately after processing; explicit draft save and approval/posting remain separate.

Owner's latest instruction defers further automated test writing/execution until explicitly requested. Do not start a new test campaign automatically. No browser visual parity, full QA or production readiness is claimed.
