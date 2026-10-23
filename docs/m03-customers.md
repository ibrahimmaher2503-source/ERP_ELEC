# M03-A Customers

Latest owner override2026-10-21: Administrator now receives every current/future registered permission by default. Earlier no-automatic-grant statements in this receipt are historical, superseded for Administrator only. Other roles, scopes and business prerequisites remain unchanged. See [RBAC policy](m01-rbac.md).

M03-A-S01 through S09: IMPLEMENTED — QA DEFERRED. The eight images and prompt in `docs/client_ui_ref` define the customer presentation. Browser interaction, viewport comparisons, screenshots and comprehensive QA remain deferred under Fast Development Mode; exact visual parity is not accepted.

## Ownership and contracts

`Parties` owns customer identity, profiles, contacts, addresses, private document relationships and append-only notes. There was no existing shared Party entity to reuse; this implementation adds customer records only, with no Supplier screens or commercial data. Future shared legal identity linking requires its own explicit migration and contract and must preserve these stable customer IDs/codes.

`CustomersApi` exposes readonly scalar customer identity and active customer search. It enforces the current actor's explicit `M03.customer_view` authority through OrganizationApi and returns safe customer errors. Consumers use the contract, never customer models or tables. No customer creation requires Accounting, Sales, tax profiles, credit limits or approved payment terms.

The module has company-scope permission boundaries matching the implemented customer registry. Authentication or Administrator membership alone does not grant customer authority. Assign the separate M03 permissions using the existing Roles & Permissions matrix. Supplier permissions and workflows are not introduced. No role or account receives automatic grants.

## Working screens and actions

- `/customers`: database-backed KPIs, indexed PostgreSQL full-text search over code/names/general contact identifiers, status/type/segment/city/country filters, reversible column sorting, pagination, explicit selection retained across pages and authorized row actions.
- `/customers/create` and `/customers/{id}/edit`: one full-page form, transient preview, required identity, duplicate candidate warning for matching name/tax number/email, general contact channels, optional primary contact/billing/shipping addresses, notes and private uploads. Code is explicitly entered, normalized uppercase and immutable after creation. Profile edits reject stale versions. Dependent writes and audit commit together; failed transactions clean uploaded files.
- `/customers/{id}`: profile and actual counts; Overview, Contacts & Addresses, Financial Profile and Documents & Activities tabs. Contacts can be edited/deactivated or set primary. Addresses have billing/shipping/other purposes and one primary per purpose. Child actions check customer ownership and serialize primary changes under the parent lock. Existing child history is retained; no destructive delete workflow.
- Notes require separate read/write permissions and retain real author/time. Customer audit events synchronously bridge through root composition to System; safe projections do not copy private notes or document contents. History requires its own permission.
- Documents accept PDF/JPEG/PNG, max10MiB, on private local storage. Download requires both customer-view and document-view permissions plus matching customer/document ownership. Download headers prevent public caching, inline execution and content sniffing. Uploading uses a separate document-manage capability.
- `/customers/import`: customer presentation over the shared System DataOperationPage. Download current `M03-customers-v1` XLSX template, create-only/update-by-code modes, version/header/duplicate/formula validation, persisted preview without customer writes, explicit confirmation, per-row receipts and downloadable error/results workbooks. Maximum500 import rows and10MiB follow the existing codec.
- Catalog bulk dialog registers customer handlers in the existing EntityRegistry/OperationService. Status, segment, city and country support explicit keep/set/clear choices as appropriate. Preview/confirm and per-record execution use versioned normal actions and current permissions. Only explicitly selected IDs are changed, max500; there is no implicit all-filtered mutation.
- XLSX exports provide selected/filtered/all-authorized modes with database-side selection and reauthorization. Only the explicit master-data field allowlist exports; private notes, documents and external financial data are excluded. General contact data requires the separate export grant. Shared string binding prevents formula injection; maximum10,000 export rows.

## Database and demo delivery

Migration090 creates `customers`, `customer_contacts`, `customer_addresses`, `customer_documents` and `customer_notes`, customer-code uniqueness, child foreign keys, enum checks, full-text GIN index and partial unique primary-contact/address indexes. It registers15 distinct M03 permissions with no grants. Migration091 normalizes customer permission display labels only, preserving role matrices, access grants and audit history.

All five models have realistic Laravel factories. Explicit local/testing command:

```shell
php artisan db:seed --class=CustomerDemoSeeder
```

It creates four clearly identified `DEMO-CUS-*` customers with contacts, primary billing/shipping addresses, synthetic notes and private synthetic PDF fixtures. It skips existing demo identities, preserves edits and adds no users, privileges, security history, invoices, balances or postings. Development seeding was explicitly invoked. The separate PostgreSQL check verifies factory persistence and repeat-run behavior with rollback and isolated file cleanup.

## Integration availability

Accounting balances, receivables/invoices, credit exposure/limits, guarantees, payment terms, sales representatives, price lists, discounts and related Sales operations do not have ready producer contracts here. Their areas show unavailable states. No financial authority, data or CRM scheduling engine is fabricated. Customer maintenance remains independent of these integrations.

Bulk/import execution reuses the existing dedicated `data-operations` queue. Run the local worker and scheduler as documented in `shared-data-operations.md`; original uploads are removed after execution and previews expire after one hour.

## Focused verification

Luna authored `.tools/check-m03-customers.php`; lead reviewed and ran it against the exact `erp_elec_new_test` PostgreSQL database. It verifies explicit RBAC/default denial, persisted factories/relationships, duplicate/required/type/version validation, child isolation/primary rules, private uploads and rejection, notes, indexed lookup, preview without customer writes, demo idempotence/preservation, selected-only bulk execution, official template compatibility, formula rejection, create/update imports, formula-safe selected export, Livewire create/edit persistence and validation, all four details tabs and shared operation rendering. Database changes roll back and private artifacts are isolated and cleaned.

Laravel boot/routes, incremental development migrations, Blade compilation, scoped Pint and Vite production build pass. The existing build warns about absent local Noto font files; system fallbacks remain available. CLI OPcache is disabled for verification because the local PHP cache retained a stale Pint PHAR path from another checkout.

Shared components reused: ERP shell/sidebar/topbar, page heading, panel, empty state, section cards, stat cards, Flux inputs/buttons/modals/dropdowns, pagination and audit timeline. Customer color/layout overrides are scoped to customer surfaces. The System bulk/XLSX orchestration, parsing and execution are reused without a second engine.

Next task: M03-B Suppliers, under a separate owner request.

## Owner-requested local access repair

The owner reported a403 after opening Customers. The current local session belonged to existing verified user2, already explicitly linked to Administrator role1; that role had zero M03 capabilities. The requested local repair added the15 customer capabilities to that existing role, preserved all previous permissions/grants/scopes, incremented its version and recorded `local_cli.owner_requested_customer_access` through Organization's security audit. This is a local owner-authorized configuration change, not an automatic Administrator bypass or future-module grant rule. Lead reviewed and ran the Luna-authored `.tools/check-local-customer-access.php`: all15 capabilities resolve for the existing account, and authenticated `GET /customers` returns200 with the Customers sidebar link. Verification uses an in-memory session, without fabricating a login event.
