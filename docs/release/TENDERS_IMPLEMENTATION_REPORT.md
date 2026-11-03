# Tender implementation report

Decision: **TENDERS COMPLETE** for the implemented Project/M04 handoff capability. M10 contract handoff remains **BLOCKED BY DEPENDENCY**. Current authenticated browser, PostgreSQL, concurrency, rollback and recovery evidence follows. This is local capability acceptance, not production activation.

## Ownership and implemented source

Tender Management was absent at Stage A. It is now the registered capability `M07.Tenders` under the existing Projects owner in `config/modules.php`, with its own `M07.tender_*` permission family and independent `release.tenders_enabled` gate. No duplicate owner or replacement M07 identity was created. Historical DeliveryReturns remains unimplemented.

New owner application files:

- `app/Modules/Projects/Application/TenderAccess.php`: default-deny branch authorization and scoped query/report intersections.
- `TenderActions.php`: normalized issuer/reference identity, exact creation-payload replay, optimistic version checks, UTC deadlines, master changes, bid/no-bid/state transitions, clarifications, documented extensions/addenda and authorized reopened evaluation.
- `TenderDocuments.php`: private classified uploads, source/hash verification, financial visibility and authorized attachment/official bid downloads.
- `TenderBoq.php`: existing System workbook codec, external descriptions, preview-before-save import and template export without creating M02 products.
- `TenderBids.php`: immutable technical and financial revisions, optional existing product mapping, explicit M02 unit mapping/conversion snapshots, compliance/deviations/alternatives/catalog references, approved-price provenance in the selected unit, exact internal cost/sell/margin separation, M11 tax, independent approvals, official bid issue and evidenced submission. External BOQ quantities never silently assume a product's base unit.
- `TenderAwards.php`: immutable result/evidence, operational guarantee/fee register and status history, explicit independently approved handoff plans, one-time Project/quotation conversion and exact winning-source contract.

Presentation is `Projects/Presentation/TendersPage.php`, `TenderPage.php`, `TenderExportController.php`, and `resources/views/tenders/{index,show,status,details,technical,financial,award,guarantees}.blade.php`. The existing RTL shell/Flux controls/logo remain in use. Sidebar registration is permission-aware.

## Forward migrations and contracts

- `2026_10_31_196000_create_projects_tender_capability.php`: master, documents, technical/financial revisions, approvals, bid documents, submissions, results, events, guarantees/status history, handoff plans/approvals/links; NUMERIC, source uniqueness, immutable evidence triggers and Administrator synchronization.
- `2026_10_31_197000_link_sales_tender_drafts.php`: Sales-owned immutable winning-bid provenance.
- `2026_10_31_198000_make_tender_guard_refresh_safe.php`: forward repair for PostgreSQL's retained standalone function during Laravel table refresh. It retires the original function name and attaches an equivalent replaceable v2 guard. Migration 196 was not rewritten after application.
- `AccountingApi::calculateTenderTax`: external unmapped BOQ lines use the actual dated company-wide M11 rule. Product-mapped lines retain normal product-specific selection. Missing/ambiguous rules block; there is no guessed tax rate or posting effect. Normal `calculateTax` keeps its existing required-product contract.
- `ProjectsApi::tenderAward`: returns the current winning source only for the exact quotation mapping in an independently approved handoff plan, under a shared transaction and current branch authority.
- `QuotationsApi::createTenderDraft` / `Sales/Application/TenderQuotationDraft.php`: creates an ordinary unapproved M04 draft using normal catalog/pricing controls. Inclusive source prices convert explicitly to exclusive draft net amounts, with source tax/money preserved separately. Normal M04 commercial approval remains required. No standard product price is overwritten.
- Source-linked M04 quotation reads/edits/private files/conversion are branch-restricted, and the awarded customer/branch cannot silently change. A Tender-created Project similarly retains its winning customer/branch identity.
- System `OfficialDocuments` supplies TN/TB numbering and immutable private PDF/hash storage. Issuing a PDF leaves the tender unsubmitted; actual classified submission evidence, method, reference, timestamp, current approved revisions and eligible deadlines are mandatory.

All new permissions are synchronized to explicitly assigned Administrator roles by the migration through `Organization.SyncAdministratorPermissions`. Other roles retain default deny. A matching display name never establishes membership. Maker-checker and scope restrictions still apply to Administrator.

## Routes and screens

| Route | Purpose |
|---|---|
| `/tenders`, `/tenders/dashboard`, `/tenders/reports` | Register, actual status counts, categories/deadlines/customer history and reporting |
| `/tenders/create`, `/tenders/{tender}` | Master and tabs: details, technical, financial, approvals, submission, award/handoff, guarantees, documents, history |
| `/tenders/exports/{xlsx|pdf}` | Scoped real-register exports, submitted/award values, converted destinations and outstanding guarantee expiry references |
| `/tenders/documents/{document}` | Authorized classified private attachment |
| `/tenders/{tender}/bids/{financial}/pdf` | Hash-checked official immutable bid revision |

Route receipt: `evidence/final-20261030/tender-routes.json`. Backend dates require unambiguous offsets; the UI explicitly interprets local inputs as Africa/Cairo and stores UTC.

## Runtime evidence

`evidence/final-20261030/check-tender-actions.php` was Luna-authored. Its final run, `tender-actions-branch-scope-final-20261031-1720.txt`, passes **58 checks** on disposable PostgreSQL. It covers actual domain Actions for external BOQ, private evidence, exact M11 tax/cost calculations without journals, independent approvals, official bid, recorded submission, award, guarantee and independently approved Project plus M04 draft conversion. It also verifies expired deadlines, unauthorized approval, wrong branch, financial-hidden and outsider private document denials, immutable SQL revisions using savepoints, changed identity/payload/version, M10 refusal, mismatched downstream identity, exact mapping and rollback. Factory relationships, both Livewire editor/snapshot tabs and real M02 nonbase-unit conversion are verified. Direct branch permission without actual user branch scope yields empty lists and 403 Actions; a real M01 scope grant restores access.

Real independent-worker receipts:

- `tender-creation-race-7F0FF02159.txt`: same key/payload, two PHP/PostgreSQL workers, one tender and one creation event.
- `tender-multirace-D3E561853C.txt`: same submission key returns one submission; competing award results produce one result/one 409; competing conversion produces exactly one Project and one ordinary M04 draft/one 409.
- The same multirace injects a failing trigger after downstream draft creation and verifies Project, quotation, Sales provenance, handoffs and audit counts all roll back. Committed synthetic fixtures exist only in the disposable clone so independent workers can see them.

The first final Laravel attempt failed **36 tests** because a PostgreSQL standalone function from 196 survived table refresh. This real defect is preserved in `final-current-laravel-suite.txt`, corrected by forward migration 198, and the rerun `final-current-laravel-suite-rerun.txt` passes **41 tests / 107 assertions**. Vite production build and Blade compilation pass in their recorded receipts.

Authenticated core browser: `evidence/m09-browser-20261031/tender-run-233CC98869/result.json`, PASS at 1440×900, 1366×768 and 390×844. Tender 6, `TN-2027-000006`, retains technical/financial revision 1, net 200.00, tax 28.00 and total/award 228.00 in the synthetic company; independent plan 3 creates draft Project 91 with the same branch/customer and winning source. The portal-method submission is a **local recorded test submission with synthetic receipt evidence**; no external portal was contacted.

Supplement: `supplemental-run-960A14BBF2/result.json`, PASS. Actual report-page linked XLSX/PDF downloads include the winning tender, private/no-store headers and denied outsider access; System BOQ template and Livewire import preview preserve the saved revision; current BOQ reload is verified. An operational guarantee is registered/returned with inventory/journal/Treasury counts unchanged. Tender 1 is genuinely reopened with version 10→11 and append-only actor history; Tender 6 stays unchanged. A private attachment URL denies the outsider.

Restore: `evidence/final-20261030/browser-clone-backup-restore-20261031-163438.json`, actual fresh PostgreSQL restoration with all 209 table counts and logical catalog inventory equal, 20 referenced private files and 223 official PDF hashes/identities valid on both sides. SQL dump text differs due textual normalization; logical nullability/constraints/indexes/functions/triggers remain compared. This does not establish production recovery or RTO/RPO.

Real browser defects fixed before acceptance include the inherited Livewire `transition` collision and technical/financial projection names shadowing editor arrays. The final views use separate immutable revision projections, complete readonly financial worksheet/plan review, and the existing heading component's real action slots. Failed campaign folders remain preserved.

## Factories and demo data

`Projects/Domain/Tender.php`, `TenderTechnical.php`, `database/factories/TenderFactory.php`, `TenderTechnicalFactory.php`, and `database/seeders/TendersDemoSeeder.php` provide synthetic identified/study records without fabricated approval, submission, award or financial posting. The explicit local/testing `EgyptMarketDemoSeeder` composition adds three stable DEMO-EG tender drafts with existing disabled demo owners. Repeat runs preserve edits and do not duplicate records. The canonical Egypt demo check was extended by Luna to verify the graph, lack of fabricated lifecycle authority and anonymous demo audit identity.

## Explicit dependencies and unverified areas

- M10 ServiceContracts is absent. Contract handoff is disabled with a controlled rejection; no contract producer or coverage was invented inside Projects/M09.
- Guarantees and tender purchase fees are **operational tracking only**. No Treasury guarantee posting contract was available. Registering, releasing or forfeiting a reference does not fabricate a cash movement or Accounting expense.
- Supplier estimate references can be documented; an unavailable supplier-quotation producer is not represented as a live automatic quotation import or posted cost.
- External procurement portal/email/physical submission is recorded from real supplied evidence. No external submission automation, legal signature or accepted statutory document is claimed.
- No production workflow, production recovery or company activation is verified. The overall ERP is NOT READY due to missing owners and separately reproduced financial blockers.
- Development is migrated through 198 with pre-backup, unchanged operational counts/activation and audited Administrator synchronization to 288/288 keys. Real production host, company-specific inputs and statutory/external submissions remain unverified. The final local decision and evidence inventory are consolidated in `TENDERS_WORKSHOPS_FINAL_ACCEPTANCE.md`.
