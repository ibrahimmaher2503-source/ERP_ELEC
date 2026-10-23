# M04 Sales / Quotations, local implementation

Latest owner override2026-10-21: Administrator now receives every current/future registered permission by default. Earlier no-automatic-grant statements in this receipt are historical, superseded for Administrator only. Other roles, scopes and business prerequisites remain unchanged. See [RBAC policy](m01-rbac.md).

Owner contract: [Q-01 through Q-29](m04-quotations-contract.md), copied verbatim from the current prompt; six current PNG references govern screen structure. Work remains local.

| Slice | Actual delivery | Status |
| --- | --- | --- |
| S01 | Sales owner, permission catalog, scalar read API, three foundational draft tables and commercial/approval migrations | IMPLEMENTED — QA DEFERRED |
| S02 | `/quotations`: real counts, server filtering/search/sort/pagination and gated navigation | IMPLEMENTED — QA DEFERRED |
| S03 | Catalog-only customer/product selection, validated units, dedicated manual-save editor, optimistic version protection, unsaved-change warning | IMPLEMENTED — QA DEFERRED; suggested prices BLOCKED on M02 pricing |
| S04 | Exact BCMath draft arithmetic, line/global percent or amount discounts, configurable validity/limits and versioned commercial templates | IMPLEMENTED — QA DEFERRED; official rounding/tax BLOCKED on M11 |
| S05 | All-exceptions snapshots, pending freeze, single approve/reject, required rejection reasons, withdraw/resubmit, policy/content/version checks, database-immutable resolved decisions, current M01 authority, audited role/user self-approval configuration | Application/UI implemented; live submission BLOCKED where approved pricing/configuration is absent |
| S06 | Exact readiness errors, PDF readiness screen | Official issue, annual numbering, immutable issued PDF and delivery recording BLOCKED on price/tax/rounding/company signature/PDF contracts; not delivered as official documents |
| S07 | Persisted details, internal documents, history, closed-draft restrictions, duplicate with current prices and reset discounts/terms | IMPLEMENTED — QA DEFERRED; issued revisions BLOCKED with issuance |
| S08 | Acceptance readiness screen, no false acceptance or evidence | Acceptance persistence BLOCKED pending issued revision |
| S09 | Conversion readiness screen and server guard, no fake quantity allocation or order | BLOCKED pending accepted issued revision and actual Sales Order contract |
| S10 | Template-first in-memory line preview/application and authorized quotation export through shared WorkbookCodec | IMPLEMENTED — QA DEFERRED |
| S11 | Local integration, factories, explicit repeat-safe draft demo seeder and focused checks | IMPLEMENTED — QA DEFERRED for delivered surfaces |

## Persistence and ownership

Migrations110–113 create sales_quotations, sales_quotation_lines, sales_quotation_attachments, sales_quotation_policies, sales_quotation_terms and sales_quotation_approvals. Organization owns quotation_self_approval_policies. No Order/Inventory/Accounting/Project schema is created. Product/customer identity comes through current owner APIs; no application cross-owner model query/write. Root bridges QuotationChanged synchronously into existing System audit. M01 owns authorization and self-approval configuration audit.

Drafts have a UUID label, not a fabricated QT identifier. Official QT-YYYY-NNNNNN allocation is deferred with actual issuance; no allocation is consumed by a draft. Draft monetary inputs use PostgreSQL NUMERIC, decimal strings and BCMath at scale40, sufficient for two percentage discount stages over scale8 inputs. Draft calculations are explicitly unrounded, tax and final total are null, not invented zero. Duplicate product lines remain distinct, in user order, with independent validation; no automatic consolidation or discount is assumed. Unknown prices produce unknown totals.

Existing term versions/text remain stable; selecting an active template snapshots its text server-side. Changing wording creates a new version and deactivates the former selection prospectively. Commercial settings have no seeded runtime defaults or thresholds. Configuring M04 limits requires explicit quotation_policy_manage; it does not grant price, tax or signing authority.

## Authorization and navigation

20 M04 permissions are registered without grants. Every page/action checks current authority. The Administrator role name never grants approval, prices or conversion. The sidebar exposes Quotations only with explicit view; security administrators without that permission receive a link to configure the matrix. Role/user details embed quotation self-approval settings within existing security surfaces. Self-approval is disabled unless explicitly enabled for a current role/user; approval permission remains mandatory and policy/decision changes are audited. No new local privileges are inferred from prior M03 access repairs.

## Files and spreadsheets

Internal PDF/PNG/JPEG uploads are validated, at most10MiB, private, hashed and permission/quotation-owned. Downloads use no-store/nosniff and attachments; internal files never become official quotation PDFs or acceptance automatically. Uploads advance the quotation version and cannot overwrite pending/closed data.

The existing master-data OperationService persists staging and executes writes; using its confirmation path for Q28 would violate draft-only import. QuotationLineImportDefinition therefore reuses its underlying shared WorkbookCodec for the same versioned template validation, archive/formula safety, lookups and decoding, with a locked ephemeral Livewire preview and revalidated apply into UI state only. No second spreadsheet parser/engine, queued master write or persisted import receipt is created. Prices/discounts are excluded from import. QuotationExportDefinition uses that same codec with explicit view/export checks and honest unavailable tax/total cells. This is an intentional adapter to the shared infrastructure.

## Demo and verification

Explicit command: `php -d opcache.enable_cli=0 artisan db:seed --class=QuotationDemoSeeder`. Ran twice locally. Creates one identifiable DEMO-RFQ-001 draft against existing actual DEMO customer/product lookups with a private synthetic RFQ PDF. Reruns preserve edits; no prices, company policy, approved terms, decisions, issued artifact, acceptance, orders, users or privileges are manufactured. Factories exist for all six Sales models; security configuration is not fake seed data. Commercial-policy and approval factories are isolated test fixtures only, never runtime defaults.

Focused Luna-authored verification lives in `.tools/check-m04-quotations.php`; it uses exact erp_elec_new_test PostgreSQL, isolated private files and rollback. Lead review/integration and actual final check outcomes are recorded below. Migrations, Laravel routes, Blade compile and Vite build passed. Full regression suites, Playwright, browser/screenshot/mobile parity and production acceptance are deferred. Do not claim the entire module or official issuance complete.


Final essential verification receipt, 2026-10-21: lead reviewed Luna-authored code and ran the focused check successfully. Authorized HTTP list/create/edit/policy/details/approval/acceptance-readiness/conversion-readiness/PDF-readiness/private-document routes return200; unauthorized actions/downloads are denied. Exact decimal/discount bounds, factory owner relationships and unit conversion, no-write import preview/application, authorized export, cancellation/clean duplication, isolated demo idempotence, stale-version protection, snapshot submission/withdrawal, required rejection reason, self-approval enable/revoke/default deny, policy staleness, current-approval validity and PostgreSQL decision immutability passed. Organization user/role embeds returned200. Test pricing/policy/approval fixtures exist only inside the rollback transaction; they are not runtime producers or company configuration. Scoped Pint --test, Blade cache, incremental migrations and npm run build passed. No measured visual-parity claim is made.

Sidebar verification also passed after separating Sales from the Products navigation condition: an account holding only M04 quotation_view sees Sales on the dashboard; an M01 security administrator without M02/M04 sees the permission-configuration link. Neither check grants runtime permissions. Final focused transaction rolled back successfully.
