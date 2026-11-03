# Domain model and exclusive ownership — ERP-DOC-03 v2

> **UI stack decision — 2026-10-20:** [ADR-0015](../adr/0015-livewire-flux-ui.md) adopts **Livewire 4 + Flux UI 2 + Tailwind CSS 4**. Earlier Filament-first instructions are superseded; historical runtime/test receipts retain their original scope. Documentation is adopted; runtime migration is pending. Business contracts, authorization, SQLite isolation and local-first policy remain mandatory.

Adopted 2026-10-12. One deployable modular monolith, exactly 17 modules. This registry assigns owners; the exhaustive dependency DAG and concrete methods exist only in [IMPLEMENTATION_CONTRACT](IMPLEMENTATION_CONTRACT.md) and [public contracts](05-MODULE-CONTRACTS-EVENTS.md). Do not implement historical illustrative API names or infer dependencies from business collaboration.

## Current identity compatibility decision — 2026-10-27

The table below retains imported requirement identifiers for traceability. Its historical M07 entry is **not a runtime permission assignment**. Current implemented `Projects` owns M07 and all persisted `M07.*` permissions. The owner-required M07 Projects identity, `projects.*` routes, `project_*` tables, `Projects` audit source and public integration references remain unchanged. `DeliveryReturns` is an unimplemented historical owner designation with no runtime ID, permission prefix or alias to Projects; a Projects permission must never authorize delivery/returns work. No DeliveryReturns module is introduced in Phase 0.

The explicit compatibility entries are in `config/modules.php`, keyed by owner to prevent an ambiguous ID lookup. This resolves identity only; existing release gates remain in force. M07 Projects, M08 FieldMaintenance, complete M11 Accounting, M12 ReceivablesPayables and M15 Attendance remain required subsequent delivery scope. Earlier V1/V2 labels in audit receipts do not permanently defer them. This clarification neither renumbers historical requirements nor adds a new runtime owner. No persisted rows or applied migrations are rewritten.

| ID | Code module | Context | Exclusive owned data |
|---|---|---|---|
| M01 | `Organization` | Organization | branches, users, roles, grants, warehouse scopes |
| M02 | `Products` | Catalog | products, brands, units, specifications, price lists, serial policy |
| M03 | `Parties` | Party Registry | customers, supplier profiles, sites, contacts, interactions |
| M04 | `Sales` | Sales | quotations, quotation revisions, orders, sales invoices |
| M05 | `Purchasing` | Procurement | purchase orders, receiving documents, supplier invoices |
| M06 | `Inventory` | Inventory | warehouses, stock movements, balances, reservations, transfers, counts, serial occupancy, valuation |
| M07 | `DeliveryReturns` | Fulfilment | delivery orders, handover evidence, sales returns, supplier returns |
| M08 | `FieldMaintenance` | Field Service | site tickets, field work orders, visits, technician assignment, parts requests |
| M09 | `WorkshopRepairs` | Workshop Service | workshops, customer breaker intakes, custody moves, repairs, tests, handovers |
| M10 | `ServiceContracts` | Service Commercial | contracts, coverage, estimates, installment schedules, progress claims |
| M11 | `Accounting` | General Ledger | chart of accounts, journal entries/lines, accounting periods, posting rules |
| M12 | `ReceivablesPayables` | Subledgers | customer/supplier open items, allocations, credit positions |
| M13 | `Treasury` | Treasury | cashboxes, bank accounts, payment orders, cash/bank movements, reconciliation |
| M14 | `Payroll` | Payroll | employees, pay rates, bonuses, monthly payroll runs, payslips |
| M15 | `Attendance` | Workforce Time | biometric imports, manual entries, attendance corrections, leave, advances |
| M16 | `Reporting` | Analytics | read models, predefined report definitions, report exports |
| M17 | `System` | Platform Services | settings, numbered-document settings, files, audit, import staging, outbox |

## Domain terms and invariant owners

- Inventory stock is company property; WorkshopRepairs custody is customer property. An intake/transfer/handover never creates valued stock. Inventory alone writes balances, movement ledger and valuation pool.
- Parties owns a party that may be both customer and supplier, sites/equipment/contact history and approved credit limit. ReceivablesPayables owns its receivable/payable/credit balances; no automatic netting between roles.
- Sales owns quotations/orders/invoices and coordinates delivery billing/credits. DeliveryReturns owns physical commercial documents and calls Inventory; it cannot call Sales/Purchasing back. Purchasing owns supplier invoice/receipt commercial eligibility.
- ServiceContracts owns contract coverage, BOQ/claim/installment eligibility and invoice allocation. FieldMaintenance and WorkshopRepairs own performed work and send validated evidence through public contracts. Sales does not query those modules.
- Payroll owns employees, effective salary terms, statutory-input verification, payroll obligations and employee-dependent entry workflows. Attendance owns schedules/time/leave/advance facts. One ordinary employee/month payroll obligation; adjustments link it. Treasury owns actual money movement, not a second salary expense.
- Accounting alone writes journals/periods; each source owner requests its prescribed posting purpose. Required source/stock/GL/subledger changes commit together. System owns technical outbox and import staging, never imports foreign tables directly.
- Organization owns access and its own grant-change audit (no dependency on System); System handles other audit/documents. Reporting reads explicitly authorized SQL views without foreign models or source writes.

[Data dictionary](04-DATABASE-ARCHITECTURE.md) is the only table specification; [operating baseline](../scope/EGYPT_OPERATING_BASELINE.md) fixes valuation, serials, approvals, tax, payroll and custody; [PRDs](../prd/MODULE_PRDS.md) fix screens and requirements. No unresolved policy or proposed second ownership map remains.

Conversational addition2026-10-13: this registry stays exactly17 modules. System owns technical private conversations/messages/pending intents, reusing its audit and command receipt infrastructure. Organization owns channel/owner permission grants. Integrator-owned root AI composition calls published module contracts without foreign models/SQL; business owners still own actions/invariants. [ADR-0010](../adr/0010-conversational-erp-adapter.md) and [tool register](AI_TOOL_CONTRACTS.md) define this boundary; neither creates an eighteenth bounded context nor reverses the existing dependency direction.
