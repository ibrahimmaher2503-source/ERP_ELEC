# Actual implementation inventory — 2026-10-21

Source: `app/Modules`, `routes/web.php`, migrations, current contracts and `EXECUTION_PIPELINE.md`. Source presence is an implementation classification, not a test result. Runtime results belong to the coverage matrix/evidence. Route inventory: [routes.json](evidence/routes.json). Stack Laravel13.38/PHP8.4/Livewire4/Flux free/PostgreSQL. Five owners currently contain code: Organization49 PHP files, Products29, Parties50, Sales51, System15 at inventory time; the remaining bounded contexts are not implementations.

| Owner / module | Feature | Actual implementation / interfaces | Tables / dependencies | Classification |
| --- | --- | --- | --- | --- |
| Organization M01 | Company profile/logo | CompanyPage, SaveCompany, CompanyLogoController; `/organization/company` | companies; private local logo | IMPLEMENTED |
| Organization M01 | Branch directory/status | BranchesPage/BranchDetailsPage, SaveBranch, ChangeBranchStatus | branches; active-user deactivation guard | IMPLEMENTED |
| Organization M01 | Users/gender/avatar/password/sessions | UsersPage/UserDetailsPage, SaveUser/ChangeUserStatus/ResetManagedUserPassword/RevokeUserSessions | users, sessions, password_reset_tokens; actual login metadata only | IMPLEMENTED |
| Organization M01 | Fixed roles/dynamic matrix/user assignments | RolesPage/RoleDetailsPage/UserAccessSection, SaveRolePermissions/AssignUserRole | roles, permission_catalog, role_permissions, user_roles | IMPLEMENTED |
| Organization M01 | Branch scopes/history/security | SetUserBranchScope, PermissionAccess, UserSecurityHistory; integrated User Details tabs | user_branch_scopes/access_grants/access_audits; System audit | IMPLEMENTED |
| Organization M01 | Warehouses/scopes | OrganizationApi::listAllowedWarehouses throws IncompleteSetup | No Inventory owner producer | BLOCKED BY DEPENDENCY |
| Products M02 | Catalog/specifications/units/conversions | CatalogPage/ProductFormPage/ProductDetailsPage, ProductActions/ProductsApi/ProductsService | products/product_attributes/product_unit_conversions, exact decimal conversions | IMPLEMENTED |
| Products M02 | Categories/brands/units | MastersPage/ProductMasters; categories/brands/units routes | product_categories/brands/units_of_measure; cycle/dimension rules | IMPLEMENTED |
| Products M02 | Private media | ProductMediaActions/ProductMediaController | product_media + private local files | IMPLEMENTED |
| Products M02 | Price/stock production | Identity APIs exist; no operational price/stock ledger | Pricing/Inventory owners missing; no tax-profile registration gate | BLOCKED BY DEPENDENCY |
| System shared | Bulk/XLSX | OperationService/ProcessOperation/EntityDefinition, DataOperationPage, WorkbookCodec | data_operation_runs/items; products/customers/suppliers registered; private files | IMPLEMENTED |
| Parties M03-A | Customer CRUD/status/contacts/addresses/notes/docs | CustomerActions/CustomerAccess/CustomersApi + shared Party views | customers/customer_contacts/customer_addresses/customer_notes/customer_documents | IMPLEMENTED |
| Parties M03-B | Supplier CRUD/categories/contacts/addresses/notes/docs | SupplierActions/SupplierAccess/SuppliersApi + shared Party views | suppliers/supplier_categories/supplier_contacts/supplier_addresses/supplier_notes/supplier_documents | IMPLEMENTED |
| Parties | Financial balances/credit approvals | No financial producer; UI does not supply synthetic balance | Accounting / ReceivablesPayables | BLOCKED BY DEPENDENCY |
| Sales M04 Q | Drafts/proposed exact discounts/terms | QuotationActions/DraftAmounts/QuotationCommercialPolicy/QuotationCatalog, quotation list/form/details | sales_quotations/lines/terms/policy | IMPLEMENTED |
| Sales M04 Q | Exception request/decision/withdraw | QuotationApprovals + M01 QuotationDelegation/self-approval policy | sales_quotation_approvals; version/content/policy checks | IMPLEMENTED |
| Sales M04 Q | Internal documents/Excel | QuotationDocuments/ExportDefinition/LineImportDefinition, private controller; PDF screen is internal/unissued | sales_quotation_attachments; shared WorkbookCodec | IMPLEMENTED |
| Sales M04 Q | Official issue/numbering/immutable official PDF/revisions/acceptance/conversion | Readiness screens/server guards only; current quotation DB state excludes issued/accepted | Approved price, M11 tax/rounding, official identity/signature/document producers missing | BLOCKED BY DEPENDENCY |
| Sales M04 SO | Direct draft/list/details/Excel/audit | SalesOrderActions/Query/Catalog/ExportDefinition/SalesOrdersApi + SalesOrders/Form/DetailsPage | sales_orders/lines; scoped M01 + M02/M03 contracts | IMPLEMENTED |
| Sales M04 SO | Review/configuration | submit/withdraw/reject; SalesOrderApprovalPolicy; `/sales-orders/policy` | sales_order_reviews/policies; dynamic approval + separate configured exception role | PARTIALLY IMPLEMENTED: authority/configuration works; actual commercial approval blocked |
| Sales M04 SO | Cancellation | Never-approved draft/pending cancellation only | Preserves lines/review history; no stock release claimed | PARTIALLY IMPLEMENTED |
| Sales M04 SO | Approved amendments/reservation/backorders/delivery/invoice/returns/closure | Honest dependency screens/guards; independent returns destination | M06, DeliveryReturns, Accounting and official commercial inputs absent | BLOCKED BY DEPENDENCY |
| System | Append-only audit | RecordAudit and owner events/read contracts | audit_entries/access_audits protected by PG triggers | IMPLEMENTED |
| All owners | Factories/local demo | Explicit synthetic idempotent seeders; no automatic operational/security history | Separate-test checks + private fixtures | IMPLEMENTED for implemented entities |

## Authority resolution

Owner response in this campaign: Administrator receives all registered permissions, including SO, while account status/verification, branch/warehouse scope and business eligibility remain enforced. M04 earlier exclusion was superseded explicitly, not silently changed to make a test pass. No `.ai` file was edited. QA overrides Fast Development deferral for this campaign only.

## Scope limits

M05–M17 runtime producer absence is not a failing transaction or a passed integration. No stock reservation/posting, actual invoice, official PDF or successful accepted-quotation conversion is manufactured for test metrics. Authentication scaffold tests, guarded executable module checks and real browser workflows are different evidence levels; none alone proves full visual parity.
