# M05/M06 recovery integration contracts

Authority: owner recovery request dated 2026-10-22; AGENTS.md; docs/m05-purchasing.md; docs/m06-inventory.md; docs/05-MODULE-CONTRACTS-EVENTS.md; CROSS_MODULE_WORKFLOWS WF-01/02/03. Historical implementation receipts are not acceptance evidence.

## Current executable boundaries

| Producer → consumer | Executable operation | Preconditions and result | Actual limit |
| --- | --- | --- | --- |
| Organization → Inventory | authorize, inventoryScopes | Active verified actor, registered capability, explicit branch/warehouse scopes. Administrator membership supplies known permissions, never scopes. | No automatic future warehouse scopes. |
| Products → Inventory | inventoryProduct, inventoryProducts | Product identity, base unit and tracking projection. Inventory postings support active quantity goods. | Serial occupancy and condition pools absent. |
| Products/Suppliers → Purchasing | getProduct, getSupplier | Directory permissions plus M05 capability; immutable source snapshots retained. | No tax, cost, credit or approval authority comes from directory lookup. |
| Inventory → consumers | getAvailability(actor, warehouse, product) | M06.balance_view plus scopes; one Inventory lock/transaction reads decimal on_hand/reserved/available and incoming in_transit. Empty position returns exact zeros without creating stock. | Available excludes incoming transit. No valuation/quarantine/backorder claim. |
| Inventory → authorized operator | reconcileWarehouse(actor, warehouse) | balance_view + movement_view + reservation_view and scope; compare signed movement SUM and outstanding reservation SUM against projection under Inventory lock. | Read-only discrepancy report. Never overwrites balances or synthesizes movements. |
| Sales → Inventory | requestReservation/status/adjust/release/fulfill | SalesOrdersApi supplies eligible source/product/branch/quantity; durable UUIDs and immutable reservation events. Physical issue settles reserved and on_hand atomically. | Real Sales approval/delivery/amendment producer absent. Isolated harness tests explicit source preconditions, not an operational Sales success. |
| Purchasing → Inventory | warehouse lookup | Receipt draft validates active matching branch warehouse and M06.receipt_create scope. | No callable purchase stock receipt command exists. |
| Purchasing → System | ProcurementChanged and existing audit bridge | Same transaction as business action. Review snapshots and original supplier files remain append-only. | No fabricated past approvals/dispatch/postings. |

## Purchase receipt: mandatory atomic contract remains unimplemented

Approved contract requires Purchasing to lock approved PO/remaining lines, then Inventory to post stock **and Dr Inventory / Cr GRNI** once, then Purchasing to record the immutable posting result, before one commit. Accounting period/posting/valuation producers do not exist in this checkout. Supplier invoices require separate GRNI/AP/VAT matching and never another stock receipt. Supplier returns require DeliveryReturns plus Inventory supplier-return clearing and mandatory simultaneous supplier credit/AP effects.

The current local receipt schema explicitly allows only draft/integration_pending with NULL inventory_document_id. Submission is a persisted integration request, not confirmation. Repeated submission by the same authorized creator returns that unchanged immutable request. Draft allocations bound quantities; they never count as physically received. No M05 balance write, orphan Inventory receipt, GRNI placeholder or successful return is introduced by recovery.

To implement successful receiving, supply the adopted valuation/account-role/period contracts and implement the Accounting producer, then the Inventory purchase receipt command, then Purchasing coordinator and reconciliation in that dependency order. Existing approval permissions alone do not waive mandatory financial effects.

## Direct versus awarded purchase orders

The owner assigns internal PO approval dynamically through M01 and prohibits self-approval. Original M05 order requirements do not require an RFQ award for every PO. Direct internal PO approval therefore uses the same current capability, scopes, version, active supplier/product/unit validation and independent reviewer as an awarded order. Awarded orders additionally match the approved immutable offer. Neither internal approval issues an official commitment or posts stock.

## Safety and retries

Current write serialization acquires Organization advisory lock10401, then Purchasing50501 or Inventory60601. Stock balance and immutable movement writes share a transaction. Inventory source key replay must match warehouse/product/quantity/unit/operation/source module/source ID/source line/document. Transfers deduct only at dispatch and add only at full receipt; dispatch replay after full receipt returns the received result without another ledger leg. Count edits preserve their original snapshot; an intervening warehouse movement/reservation rejects stale count editing/approval and linked variance posting.

The general Accounting period/stock lock plan in the original contract remains a future prerequisite for financial posting. Existing coarse local locks are not evidence that the full financial lock protocol is implemented.
