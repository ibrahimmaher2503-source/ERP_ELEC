# M03-B Suppliers

Latest owner override2026-10-21: Administrator now receives every current/future registered permission by default. Earlier no-automatic-grant statements in this receipt are historical, superseded for Administrator only. Other roles, scopes and business prerequisites remain unchanged. See [RBAC policy](m01-rbac.md).

Owner-requested local access repair: the existing local user2 was already linked to Administrator role1, but the role had no Supplier capabilities, so the permission-gated sidebar correctly hid Supplier destinations. After the owner reported the missing entry,16 Supplier permissions were explicitly added to that existing local role, retaining all previous permissions/scopes and incrementing its version. Organization recorded `local_cli.owner_requested_supplier_access`. This is an explicit local configuration repair, not an automatic grant or authorization bypass. The Luna-authored local access check verifies all16 capabilities and actual HTTP responses/sidebar links on Suppliers and Dashboard.

S01–S09: IMPLEMENTED — QA DEFERRED after the focused checks below. Current authority is `docs/client_ui_ref/prompt.txt` (M03-B) and its Supplier list image. That folder no longer contains the earlier eight Customer references. Exact visual parity, browser interaction and comprehensive QA remain deferred by the current prompt.

## Reuse and ownership

Inspection found working customer actions, forms/details/catalog, private document routes, System bulk/XLSX adapters and audit bridge. There was no shared legal Party entity, reusable contact/address view component or separate global geography reference producer. Supplier identity is therefore separate and stable; no automatic linking to Customer contacts or financial identities is inferred.

The three Customer page lifecycles were extracted to owner-local `PartyCatalogPage`, `PartyFormPage` and `PartyDetailsPage`. Customer and Supplier pages are thin adapters selecting a server-defined `PartyPresentation`; the entity cannot be changed through client state. Shared Blade views provide profile header, table/filter/selection, preview, detail tabs, contact/address editors, uploads, notes and activity. Neutral contact/address field components and shared party CSS replace duplicated presentation. Customer routes remain in place. The existing mistakenly nested Customer navigation was moved out of Products, and Supplier destinations are independently permission-gated.

`CustomerActions` and `SupplierActions` remain separate mutation boundaries. The only business correction to Customer actions excludes the edited record when demoting other primary contacts/addresses; previously editing an existing primary could clear its primary flag because Eloquent considered the original true value unchanged. This is covered for both parties and their forms.

System's EntityRegistry, OperationService, template generator, workbook parser, confirmation/queue executor, private upload lifecycle and result/error reports are reused. `SupplierDataDefinition` supplies only supplier schema, lookup, authorization and action plans. No second spreadsheet or bulk engine was added. The existing protected file policy (PDF/JPEG/PNG,10MiB, private storage) and Flux upload controls are reused.

## Working features

- `/suppliers`: reference-shaped list with five real master-data KPIs (total/active/inactive/archived/new), primary contact, category/country/city/type/status filters, indexed search, sorting, pagination, explicit cross-page selection and authorized row actions. Evaluation metrics, logos/companies and transaction figures from the image are not fabricated.
- `/suppliers/create`, `/suppliers/{id}/edit`: shared full-page form and live preview with supplier type/category, legal identity, general contact channels, primary contact, registered/office addresses, notes and private attachments. Codes are canonical and immutable; profile edits are versioned. Supplier forms do not inherit Customer billing/shipping defaults. Geography uses validated text fields pending an approved geography source, rather than inventing lookup tables or codes.
- `/suppliers/{id}`: overview, contacts/addresses, truthful Purchasing/Accounting availability, documents/notes/history. Contacts can be edited/deactivated and made primary; registered/office/pickup/other addresses have one primary per purpose. Ownership checks isolate Supplier children from other suppliers and all Customer children. Documents require independent view/manage permissions and supplier-owned protected downloads.
- `/suppliers/categories`: supplier-only category catalog with stable unique codes and active/inactive state. Product classifications are not reused as supplier classifications.
- `/suppliers/import`: distinct `M03-suppliers-v1` template; category-code lookup, create-only/update-by-code, preview without business writes, explicit confirmation, reports, selected/filtered/all-authorized export and formula safety. Shared limits remain500 import rows/selected bulk IDs,10MiB uploads and10,000 export rows. Bulk supports status/category/type only, through normal Supplier actions and current permissions.
- `SuppliersApi`: readonly scalar identity/search contract with current actor authorization and safe errors, independent of CustomersApi.

## Database, permissions and fixtures

Migration100 adds `supplier_categories`, `suppliers`, `supplier_contacts`, `supplier_addresses`, `supplier_documents`, `supplier_notes`; code uniqueness, reference foreign keys, enum checks, search GIN index and partial primary-contact/address indexes. It registers16 company-scope `M03.supplier_*` capabilities without granting them to any account or role. Configure these explicitly through M01 security management; Customer permissions do not imply Supplier access. ManagerReadOnly can be explicitly configured with Supplier read permissions, while write/import permissions remain rejected.

Six realistic factories and explicit local/testing `php artisan db:seed --class=SupplierDemoSeeder` are included. The seeder was invoked twice locally: six identifiable `DEMO-SUP-*` suppliers, three Supplier classifications, related contacts/addresses/notes and valid private synthetic PDFs. It preserves existing rows and edits, and creates no users, privileges, security history, purchasing facts or financial postings.

## Focused receipt

Lead reviewed and ran Luna-authored checks on exact separate PostgreSQL `erp_elec_new_test`, with transaction rollback and isolated file cleanup:

- `.tools/check-supplier-factories.php`: factory graphs, category persistence, independent/default-deny permissions, create/update/status/version validation, child isolation/primaries, notes/files, typed API, selected-only bulk, XLSX metadata/version/formula checks, category-code import, formula-safe exports, Customer/Supplier forms and tabs, category UI, full HTTP index/create/details/import/receipt/private-document routes and idempotent seeding without security/history writes.
- `.tools/check-party-primary-edit.php`: Customer and Supplier action/form edits preserve primary contacts and purpose-specific primary addresses.
- `.tools/check-party-readonly-role.php`: explicit Supplier read configuration succeeds; write/import configuration fails and leaves the matrix unchanged; original matrix/version restored after rollback.

Incremental migrations, Laravel boot/routes, Blade compilation, scoped Pint and Vite build pass. No full suite or Playwright run and no screenshot comparison/QA acceptance. Purchasing, payable balances, payment execution, price/terms/currency policies and performance metrics remain unavailable owner integrations. Next dependency-ready module: M04 Sales, subject to its approved contracts; no commercial implementation is implied here.
