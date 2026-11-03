# ERP page archetype and adoption matrix

Current registry: 145 authenticated named GET pages, with 166 historical route/variant benchmark cases retained separately. Route names, existing view files and fixture availability come from the current route inventory, not an invented module catalogue.

Status meanings: **CANDIDATE** is implemented presentation requiring rendered acceptance; **INHERITED** reuses the owner page/shell without a new page layout; **GATED** is deferred functionality, not a working page. Visual acceptance is recorded separately from code adoption.

| Module | Route | Archetype | Presentation adoption | Fixture | Visual acceptance |
|---|---|---|---|---|---|
| M01 Organization | `appearance.edit` | Form | MIGRATED / framework form | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.branches` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.branches.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.company` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.permissions` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.roles` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.roles.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.users` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `organization.users.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `profile.edit` | Form | MIGRATED / framework form | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M01 Organization | `security.edit` | Form | MIGRATED / framework form | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.brands` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.categories` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.edit` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.import` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.prices` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M02 Products | `products.units` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `customers.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `customers.edit` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `customers.import` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `customers.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `customers.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `suppliers.categories` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `suppliers.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `suppliers.edit` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `suppliers.import` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `suppliers.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M03 Parties | `suppliers.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.acceptance` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.approval` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.convert` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.edit` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.policy` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `quotations.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-orders.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-orders.edit` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-orders.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-orders.policy` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-orders.screen` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-orders.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M04 Sales | `sales-returns.index` | List | GATED | Intentional V2 gate | Expected 404; not activated |
| M05 Purchasing | `purchasing.comparison` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.offers.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.offers.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.offers.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.orders.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.orders.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.orders.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.receipts.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.receipts.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.receipts.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.reports` | Report | GATED | Intentional V2 gate | Expected 404; not activated |
| M05 Purchasing | `purchasing.requisitions.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.requisitions.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.requisitions.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.returns` | List | GATED | Intentional V2 gate | Expected 404; not activated |
| M05 Purchasing | `purchasing.rfqs.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.rfqs.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M05 Purchasing | `purchasing.rfqs.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.adjustments` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.balances` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.corrections` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.counts` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.document.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.document.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.isolations` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.issues` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.movements` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.receipts` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.reservations` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.serials` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.transfers` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M06 Inventory | `inventory.warehouses` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.budget` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.closure` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.dashboard` | Dashboard | MIGRATED | Actual GET / authorized fixture confirmed | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.documents` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.financial` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.operations` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.reports` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects | `projects.tasks` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects (Tenders) | `tenders.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects (Tenders) | `tenders.dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects (Tenders) | `tenders.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects (Tenders) | `tenders.reports` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M07 Projects (Tenders) | `tenders.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.assignments` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.checklists` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.index` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.reports` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.requests` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.requests.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.requests.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.schedule` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.work-orders` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.work-orders.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M08 FieldMaintenance | `maintenance.work-orders.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.intakes` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.intakes.create` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.intakes.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.jobs` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.jobs.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.reports` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M09 WorkshopRepairs | `workshops.settings` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.accounts` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.balance-sheet` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.closing` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.grni` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.income-statement` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.journals` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.ledger` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.periods` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.ppv` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.reconciliation` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.setup` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M11 Accounting | `accounting.trial-balance` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.aging` | Report | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.ap` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.ar` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.credit-notes` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.customer-invoices` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.invoices.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M12 ReceivablesPayables | `financial.supplier-invoices` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M13 Treasury | `treasury.accounts` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M13 Treasury | `treasury.collections` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M13 Treasury | `treasury.movements.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M13 Treasury | `treasury.payments` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M13 Treasury | `treasury.transfers` | List | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M17 System | `dashboard` | Dashboard | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M17 System | `operations.import` | Form | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| M17 System | `operations.show` | Details | MIGRATED | Existing isolated fixture | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |
| password | `password.confirm` | Form | MIGRATED / framework form | Actual GET / authorized fixture confirmed | 5/5 normal-state checks; source-stamped images; individual visual approval not implied |

## Expanded historical page cases

Variants remain distinct: different Sales workflow screens, inventory document kinds, financial customer/supplier invoices and party financial tabs are not collapsed into one accepted route. The following is an implementation inventory; it makes no new timing claim.

| Case | Archetype | View family | Migration / normal-state browser coverage |
|---|---|---|---|
| `accounting.accounts / default` | List | `accounting.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.balance-sheet / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.closing / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.dashboard / default` | Dashboard | `accounting.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.grni / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.income-statement / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.journals / default` | List | `accounting.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.ledger / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.periods / default` | List | `accounting.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.ppv / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.reconciliation / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.setup / default` | Form | `accounting.setup` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `accounting.trial-balance / default` | Report | `accounting.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `appearance.edit / default` | Form | `pages::settings.appearance` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `customers.create / default` | Form | `parties.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `customers.edit / default` | Form | `parties.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `customers.import / default` | Form | `data-operations.workflow` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `customers.index / default` | List | `parties.catalog` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `customers.show / default` | Details | `parties.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `customers.show / financial` | Details | `parties.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `dashboard / default` | Dashboard | `dashboard` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.aging / default` | Report | `receivables-payables.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.ap / default` | List | `receivables-payables.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.ar / default` | List | `receivables-payables.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.credit-notes / default` | List | `receivables-payables.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.customer-invoices / default` | List | `receivables-payables.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.invoices.show / customer` | Details | `receivables-payables.invoice-details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.invoices.show / supplier` | Details | `receivables-payables.invoice-details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `financial.supplier-invoices / default` | List | `receivables-payables.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.adjustments / default` | List | `inventory.documents` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.balances / default` | Report | `inventory.overview` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.corrections / default` | Form | `inventory.operations` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.counts / default` | List | `inventory.documents` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.dashboard / default` | Dashboard | `inventory.overview` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.create / adjustment` | Form | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.create / count` | Form | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.create / issue` | Form | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.create / opening` | Form | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.create / receipt` | Form | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.create / transfer` | Form | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.show / adjustment` | Details | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.show / count` | Details | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.show / issue` | Details | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.show / opening` | Details | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.show / receipt` | Details | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.document.show / transfer` | Details | `inventory.document` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.isolations / default` | Form | `inventory.operations` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.issues / default` | List | `inventory.documents` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.movements / default` | Report | `inventory.overview` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.receipts / default` | List | `inventory.documents` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.reservations / default` | List | `inventory.overview` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.serials / default` | List | `inventory.operations` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.transfers / default` | List | `inventory.documents` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `inventory.warehouses / default` | List | `inventory.warehouses` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `login / default` | List | Framework/dynamic response | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.assignments / default` | List | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.checklists / default` | List | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.dashboard / default` | Dashboard | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.index / default` | List | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.reports / default` | Report | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.requests.create / default` | Form | `maintenance.request` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.requests.show / default` | Details | `maintenance.request` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.requests / default` | List | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.schedule / default` | List | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.work-orders.create / default` | Form | `maintenance.request` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.work-orders.show / default` | Details | `maintenance.work-order` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `maintenance.work-orders / default` | List | `maintenance.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `operations.import / customers` | Form | `data-operations.workflow` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `operations.import / products` | Form | `data-operations.workflow` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `operations.import / suppliers` | Form | `data-operations.workflow` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `operations.show / default` | Details | `data-operations.workflow` | MIGRATED — real owner-action preview 5/5; stale old URL superseded |
| `organization.branches.show / default` | Details | `organization.branch-details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.branches / default` | List | `organization.branches` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.company / default` | Form | `organization.company` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.permissions / default` | List | `organization.roles` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.roles.show / default` | Details | `organization.role-details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.roles / default` | List | `organization.roles` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.users.show / default` | Details | `organization.user-details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `organization.users / default` | List | `organization.users` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `password.request / default` | List | Framework/dynamic response | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.brands / default` | List | `products.masters` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.categories / default` | List | `products.masters` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.create / default` | Form | `products.product-form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.edit / default` | Form | `products.product-form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.import / default` | Form | `data-operations.workflow` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.index / default` | List | `products.catalog` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.prices / default` | List | `products.prices` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.show / default` | Details | `products.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `products.units / default` | List | `products.masters` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `profile.edit / default` | Form | `pages::settings.profile` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.budget / default` | Details | `projects.budget` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.closure / default` | Details | `projects.closure` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.create / default` | Form | `projects.show` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.dashboard / default` | Dashboard | `projects.dashboard` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.documents / default` | Details | `projects.documents` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.financial / default` | Report | `projects.financial` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.index / default` | List | `projects.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.operations / default` | Details | `projects.operations` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.reports / default` | Report | `projects.reports` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.show / default` | Details | `projects.show` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `projects.tasks / default` | Details | `projects.show` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.comparison / default` | Report | `purchasing.comparison` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.dashboard / default` | Dashboard | `purchasing.dashboard` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.offers.create / default` | Form | `purchasing.offer` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.offers.index / default` | List | `purchasing.offers` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.offers.show / default` | Details | `purchasing.offer` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.orders.create / default` | Form | `purchasing.order` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.orders.index / default` | List | `purchasing.orders` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.orders.show / default` | Details | `purchasing.order` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.receipts.create / default` | Form | `purchasing.receipt` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.receipts.index / default` | List | `purchasing.receipts` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.receipts.show / default` | Details | `purchasing.receipt` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.reports / default` | Report | `purchasing.dashboard` | V2 GATED — expected 404; not activated |
| `purchasing.requisitions.create / default` | Form | `purchasing.requisition` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.requisitions.index / default` | List | `purchasing.requisitions` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.requisitions.show / default` | Details | `purchasing.requisition` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.returns / default` | List | `purchasing.returns` | V2 GATED — expected 404; not activated |
| `purchasing.rfqs.create / default` | Form | `purchasing.rfq` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.rfqs.index / default` | List | `purchasing.rfqs` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `purchasing.rfqs.show / default` | Details | `purchasing.rfq` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.acceptance / default` | Form | `sales.quotations.`, `sales.quotations.acceptance` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.approval / default` | Form | `sales.quotations.`, `sales.quotations.approval` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.convert / default` | Form | `sales.quotations.`, `sales.quotations.conversion` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.create / default` | Form | `sales.quotations.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.edit / default` | Form | `sales.quotations.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.index / default` | List | `sales.quotations.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.policy / default` | Form | `sales.quotations.policy` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `quotations.show / default` | Details | `sales.quotations.`, `sales.quotations.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.create / default` | Form | `sales.orders.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.edit / default` | Form | `sales.orders.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.index / default` | List | `sales.orders.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.policy / default` | Form | `sales.orders.policy` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.screen / amendment` | Details | `sales.orders.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.screen / closure` | Details | `sales.orders.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.screen / delivery` | Details | `sales.orders.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.screen / invoices` | Details | `sales.orders.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.screen / review` | Details | `sales.orders.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-orders.show / default` | Details | `sales.orders.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `sales-returns.index / default` | List | `sales.returns.index` | V2 GATED — expected 404; not activated |
| `security.edit / default` | Form | `pages::settings.security` | MIGRATED — actual password-confirmed ordinary actor 5/5 |
| `suppliers.categories / default` | List | `parties.categories` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `suppliers.create / default` | Form | `parties.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `suppliers.edit / default` | Form | `parties.form` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `suppliers.import / default` | Form | `data-operations.workflow` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `suppliers.index / default` | List | `parties.catalog` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `suppliers.show / default` | Details | `parties.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `suppliers.show / financial` | Details | `parties.details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `tenders.create / default` | Form | `tenders.show` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `tenders.dashboard / default` | Dashboard | `tenders.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `tenders.index / default` | List | `tenders.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `tenders.reports / default` | Report | `tenders.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `tenders.show / default` | Details | `tenders.show` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `tenders.show / financial` | Details | `tenders.show` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `treasury.accounts / default` | List | `treasury.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `treasury.collections / default` | List | `treasury.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `treasury.movements.show / default` | Details | `treasury.movement-details` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `treasury.payments / default` | List | `treasury.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `treasury.transfers / default` | List | `treasury.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.dashboard / default` | Dashboard | `workshops.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.intakes.create / default` | Form | `workshops.intake` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.intakes.show / default` | Details | `workshops.intake` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.intakes / default` | List | `workshops.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.jobs.show / default` | Details | `workshops.job` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.jobs / default` | List | `workshops.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.reports / default` | Report | `workshops.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |
| `workshops.settings / default` | Form | `workshops.index` | MIGRATED — 5/5 normal-state checks; source/build batch recorded |

M10 and M15 have no implemented named page family in the inspected registry. No interface or synthetic data was invented for them. Framework confirmation and intentionally gated routes remain explicitly tracked.

Additional eligible case: `password.confirm|default` is a framework Form, inspected at all five viewports with real form readiness. Guest login/password-request use unauthenticated contexts. Security/operation fixture-state aliases and per-record database identity are recorded in the coverage JSON. Normal-state checks do not assert every business-state/role combination or individual visual approval.
