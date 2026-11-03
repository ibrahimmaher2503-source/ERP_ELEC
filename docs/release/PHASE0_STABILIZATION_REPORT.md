# Phase 0 stabilization report

Started 2026-10-27; continued 2026-10-28 (Africa/Cairo). Local working tree, not a published release. This report supersedes the pre-Phase-0 absence claims in the V1 audit receipts only where current evidence is listed below.

**Decision: STABILIZATION COMPLETE — READY FOR REQUIRED MODULE COMPLETION.** All remaining Phase 0 acceptance gaps were closed on 2026-10-28. This is a local stabilization decision only; it is not production activation, a release publication, or approval of real company balances.

## A. Changes

### Preservation and identity

- Entry branch: `main`; entry commit: `28cca25ef242bbcdcfcd29a976f8e3a552768a87`. The tree already contained modified tracked files and untracked financial/System/Purchasing/Inventory/pricing work. No reset, checkout replacement, stash, remote publish, company financial activation or operational-table truncation was performed.
- The entry development schema had migrations through 159 applied; 160/161 were pending. Existing modified Projects migrations 147–149 were left intact. No applied development migration was rewritten.
- On 2026-10-28 the existing stopped `.local/postgres-dev` cluster was restarted without initialization or replacement. `php artisan migrate --no-ansi` incrementally applied 160–171 to local `erp_elec_new`. Before and after the financial integration migrations: Go-Live NULL, activation NULL, zero inventory movements, zero posted journals and three Projects. The real post-migration Administrator synchronization added exactly `M02.price_approve`, `M02.price_manage` and `M02.price_view`, with anonymous automated audit attribution; zero registered keys are missing from its persisted matrix. No other role or business scope was seeded.
- `config/modules.php` and `docs/03-DOMAIN-MODEL-BOUNDED-CONTEXTS.md` explicitly map runtime M07 to `Projects`. `DeliveryReturns` retains its historical documentation identifier only and receives no runtime ID or permission prefix. Three existing Projects and nine existing M07 permission keys were observed without changing their identities, routes, tables or audit attribution. No DeliveryReturns owner was implemented.
- M07 Projects, M08 Field Maintenance, complete M11 Accounting, M12 ReceivablesPayables and M15 Attendance remain required subsequent delivery scope. Their expansion is outside this phase, not permanently deferred.

### Shared foundations and System

- `app/Modules/Products/Application/SalePrices.php`: fixed Laravel splitting the regex alternation inside a pipe-delimited amount rule. Retains real approved M02 price provenance and independent approval.
- `app/Modules/Accounting/Application/TaxCalculator.php`: accepts monetary discounts at the approved 32-place internal scale. Unit-price/quantity validation and dated server-selected tax rules remain enforced; no tax rate or production price was introduced.
- `app/Modules/Organization/Application/OrganizationService.php`: official-company snapshots include the actual private logo bytes when configured and reject a missing referenced logo. Private paths are not exposed in the snapshot.
- `app/Modules/Parties/Application/CustomersService.php`: active customer identities requested inside a transaction retain an owner-local shared lock until that business transaction finishes, preventing concurrent status changes during commercial eligibility checks.
- `app/Modules/System/Application/IssueOfficialDocument.php`: serializes retries by immutable source/type/revision before lookup, while the existing annual sequence serializes distinct sources. Added QT, SO and DN types to the same mPDF/numbering producer. `resources/views/system/official-document.blade.php` renders the frozen logo and quotation signature area.
- The same System consumer translates expected missing company identity/private-logo contract failures into form validation, while preserving forbidden access as HTTP 403; no number or PDF commits on failure.
- `app/Modules/System/Infrastructure/Spreadsheets/WorkbookCodec.php`: checks whether a cell exists before reading it, avoiding creation and disk-cache writes for empty formatted template rows. Archive inspection, exact headers, formula rejection and import bounds remain intact. The original `A1:L501` opening template with two actual rows previews in 1.633 seconds without state changes.

### Purchasing projections

- `app/Modules/Purchasing/Application/PurchaseOrderProjection.php`: shared receiving-state/remaining-quantity projection, issued-number search and receipt-state filters. The immutable issue record remains the number source; no competing header number is populated.
- `app/Modules/Purchasing/Presentation/OrdersPage.php`, `PurchasingExportController.php` and `resources/views/purchasing/orders.blade.php`: use the same authorized projection/filter semantics. Existing detail projection and receipt/FIFO/GRNI posting actions are preserved.

### Valued Inventory

- Retained and exercised the entry implementation in `ValuedTransfers.php`, `InventoryDocuments.php`, `FinancialStock.php`, `InventoryLedger.php` and `FifoValuation.php`.
- `InventoryQuery.php` and `resources/views/inventory/overview.blade.php`: recognize financial opening and purchase receipt movement types.
- `InventoryReservations.php`, `InventoryService.php` and `Application/Contracts/InventoryApi.php`: allocate an existing backorder only against actual current availability, unchanged source demand and current warehouse mutation scope. Immutable allocation events reconcile monotonic reservation increases; neither fulfilled stock nor the original request is overwritten.
- `InventoryCorrections.php`, new `ValuedCorrections.php`, `FifoValuation.php`, `FinancialStock.php` and `InventoryLedger.php`: bounded, source-linked internal issue/adjustment reversal with exact FIFO/GL evidence. Original receipt layers are consumed for positive-adjustment reversals; issued quantities return with original FIFO date/cost provenance. A correction crossing original issue allocations must be split into allocation-sized requests. Later dependent stock movements remain a denial.
- Transfers, correction receipts and positive adjustments retain the authorized source unit-cost metadata, separately from exact cumulative posted-value allocation.
- `InventoryTransfers.php` and `InventoryConditions.php`: damaged transfer receipts now derive their quarantine key from the actual per-lot movement. The condition producer verifies the corresponding receipt/actor, source document/line, FIFO allocation and exact quantity; the old suffix-only check rejected every valued damaged receipt. Both-endpoint transfer scopes remain required.

### Sales commercial integration

- New `app/Modules/Sales/Application/QuotationIssuance.php`: checks current M02 price source, existing quotation exceptions/approval policy, active owning identities, approved tax-exclusive Q-14 arithmetic, dated M11 rules, company identity and validity. Freezes commercial/tax snapshots and genuine private System PDF. Records written acceptance tied to that issue with hashed private evidence.
- New `ConvertAcceptedQuotation.php`: one controlled full conversion through actual `SalesOrderActions`, preserving accepted prices/terms/tax. Same-key retry returns the same draft; another key/branch cannot convert the same quantities again. Commercial acceptance and conversion state stay separate.
- New `ApproveSalesOrder.php`: independently approves accepted-quotation and compliant direct orders, validates source/current tax and explicit warehouse choices, issues the official SO and requests real Inventory reservations/backorders atomically. No directly inserted approved order is used as acceptance evidence.
- New `SalesOrderCommercialReview.php`: direct-order departures from current approved prices and active term templates require a separate, reasoned decision by the configured exception role. The immutable decision binds the pending review, content hash, current exceptions and policy version. It neither approves nor reserves the order; ordinary independent approval follows. Stale price sources or decisions fail without effects. Existing permission keys and configured role policy are reused.
- `SalesOrderReadiness.php`: direct-order readiness checks the actual current pending review and commercial decision; it does not describe an already accepted exception as permanently unavailable. The Luna-authored durable Sales Order regression asserts the genuine independent-review prerequisite and retains the existing negative security, stale-version, immutable-history and downstream-denial coverage.
- New `SalesDeliveries.php`, `SalesDeliveryService.php`, `Application/Contracts/SalesDeliverySources.php`: Sales owns the commercial instruction; Inventory owns every physical movement. Explicit confirmation invokes actual FIFO consumption and the Accounting COGS/inventory journal, with the same System producer for delivery PDF. Generic Inventory permission cannot fabricate a staged Sales instruction. Replays retain current warehouse mutation checks.
- New `SalesOrderProgress.php` and changed `SalesOrdersService.php`: derive partial/final delivery from confirmed sources and reservations/backorders from authorized Inventory contracts. Restricted Inventory facts remain restricted. No invoice or revenue posting is fabricated.
- Changed Sales `QuotationReadiness.php`, `QuotationQuery.php`, `QuotationExportDefinition.php`, `SalesOrderActions.php`, `SalesOrderQuery.php`, `SalesOrderExportDefinition.php`, `Presentation/QuotationDetailsPage.php`, `SalesOrderDetailsPage.php`, new `QuotationOfficialController.php`, `SalesOrderOfficialController.php`, `app/Providers/AppServiceProvider.php` and `routes/web.php` connect the owner actions and authorized private downloads.
- `Presentation/QuotationFormPage.php`, `SalesOrderFormPage.php` and their form views now display real approved-price hints and refresh them when product, unit or document date changes. Authoritative save/issue still recompute source data; client hints never authorize prices. Acceptance business-date validation follows Africa/Cairo.
- Changed quotation `acceptance`, `conversion`, `pdf`, `details`, `partials/amounts`, `partials/identity` views and Sales Order `details`, `index`, `partials/status`, `partials/workflow-statuses`, new `partials/approval-action` and `partials/delivery-action` views use existing Flux/shell components and real persisted projections.

### Forward migrations

| Migration suffix | Purpose |
| --- | --- |
| 160 / 161 | Entry uncommitted valued-stock/pricing migrations. Their function creation was made replace-safe for PostgreSQL test-schema refresh before applying to development; business DDL semantics were retained. |
| 162 | Immutable quotation issues, private written acceptance and one-time conversion evidence; issued-line/content protection. |
| 163 | Reconciles accepted/damaged transfer receipts with their actual FIFO-lot movement keys, exact source and condition. |
| 164 | Preserves quotation-origin Sales Order identity. |
| 165 | Independently approved Sales Order issue/review and actual Inventory commitment constraints. |
| 166 | Sales delivery instructions, immutable lines and atomic Inventory/FIFO/GL completion constraints. |
| 167 | Evidenced monotonic allocation of existing backorders. |
| 168 | Internal correction-to-original FIFO source linkage and financial commit constraints. |
| 169 | Exact correction-allocation exhaustion, immutable issued quote number and nonempty approved order evidence. |
| 170 | Authorized FIFO unit-cost lineage on transfer/adjustment/correction receipts. |
| 171 | Immutable direct Sales Order commercial-exception decisions bound to review, content and policy version. |

All new acceptance/check code is Luna-authored. The lead reviews/integrates application changes and evidence. Repository QA entrypoints include `tests/Unit/ModuleIdentityRegistryTest.php`, repaired `tests/E2E/round2-masters.cjs` and its guarded master fixture, durable `tests/Support/check-m04-sales-orders.php`, `tests/Support/check-m05-m06-roundtrip.php`, canonical M06, valued-path, recovery and concurrency helpers, and their JSON receipts. The safe aggregate runner selects the durable Sales Order entrypoint.

`package.json` / `package-lock.json` pin the already-used Playwright version `1.62.1` as a development dependency for reproducible browser tooling. `.gitignore` excludes the disposable `.tmp` cluster/storage workspace without deleting its files. Recovery/concurrency helpers clear URL overrides and verify the resolved and actual PostgreSQL loopback address, port and database before business mutations.

### Final closeout repairs

- The stale Sales Order check now expects a direct draft to require its real independent review before approval. No self-approval, branch-scope, stale-content, immutable-review, policy, authorization or downstream negative assertion was removed.
- The browser harness now binds each click to the actual Livewire method call/response and checks persisted state after dispatch and each receipt. The original `98AE0564FA1E` failure was a harness response-selection defect: its click helper could consume an unrelated Livewire response while transfer #12 remained `approved`. No Inventory application defect was demonstrated.
- Browser run `CDB32D07A63A` executes a two-unit transfer with separate 1/2 and 2/2 receipts, an exact persisted-balance count, an independently approved/posted negative adjustment, genuine backorder allocation, partial/final Sales deliveries and an unprivileged 403. Its final PostgreSQL verifier reconciles both warehouse balances, transfer lots, receipt FIFO, adjustment FIFO and balanced source-linked journals.
- PostgreSQL 17 renders a cast `inet_server_addr()::text` as `127.0.0.1/32`. Recovery/concurrency parent and worker guards now use `host(inet_server_addr())` while retaining strict loopback, port 55433, database and empty-URL checks.
- Safe non-migration application, factory, seeder and QA files were formatted. The only full-repository Pint findings left are seven already-applied migrations (150–154, 160–161), which were deliberately not rewritten solely for formatting.
- Final recovery used a fresh, separately named disposable restore from the existing dump. Existing restore/browser clusters and their accumulated synthetic evidence were preserved rather than reset or overwritten.

## B. Fixed issues and evidence

| Before | Current behavior | Direct evidence |
| --- | --- | --- |
| Ambiguous M07 registry assignment | Projects identity is preserved; no DeliveryReturns permission alias | Registry regression: 2 tests / 5 assertions; observed existing records/catalog. |
| PostgreSQL refreshed schema failed on duplicate standalone functions | Entry pending migrations reinstall their functions safely | First suite failed during setup; subsequent suite passed 39 tests / 99 assertions. |
| Valued transfer work appeared successful until commit | Forward receipt guard recognizes exact per-lot ledger evidence | The initial deferred-constraint failure was reproduced; subsequent immediate-constraint check passed. |
| Price draft save raised malformed-regex error | Actual validated price Actions save/independently approve | Two-product real Sales action fixture. |
| Sales-to-tax discounts failed decimal validation | Approved internal-scale discounts reach M11 unchanged | Real quotation issue and tax snapshot/PDF fixture. |
| Issuance, acceptance, conversion and approval unconditionally unavailable | Bounded accepted-quotation lifecycle reaches actual reservations/delivery/FIFO/COGS | Current valued-path action receipt; final scenario delivers 2/2 units per product after a genuine backorder. |
| Fixed Sales fulfillment placeholders | Independent real reservation/delivery progress | Partial 1.5/2 and completed 2/2 projections checked; no fake invoicing. |
| Stale PO number/list/export/filter state | Immutable issued source and posted receipt state drive projections | Scoped official-number and not/partial/final receipt filter assertions. |
| Browser database lacked authorized operator/unit | Test-only prerequisites and audited explicit Administrator assignment | Master browser campaign: 19 checks; user-only subset: 4 checks. |
| Browser assets could be HTML from an incorrect router | Static-aware Laravel router plus manifest HTTP/MIME preflight | Current master browser campaign. Empty JavaScript entry is valid and remains accepted. |

## C. Verified workflows

| Owner | Current verified boundary |
| --- | --- |
| M01 | Laravel auth/settings and standalone company/users/contracts/RBAC/Administrator/role propagation checks pass; browser master/user saves pass. Administrator membership is explicit and scope/independent approval guards remain enforced. |
| M02 / M03 | Browser create/reopen/repeated save and persisted master identities; real active products/supplier/customer feed the financial fixture. Standalone product/customer/supplier, private access, import/export, route and read-only-role checks pass. Sparse workbook performance defect is repaired. |
| M04 | Real two-product quote → System PDF → written acceptance → one-time conversion → independent SO approval → actual reservation/backorder → partial delivery → genuine valued new stock → backorder allocation → final delivery/FIFO/COGS. Direct compliant and separately authorized exception orders pass action-level acceptance. Extended browser run `CDB32D07A63A` passes the complete Sales/Purchasing/Inventory journey and final PostgreSQL reconciliation. |
| M05 | Real independent approval/issue/private PDF; actual partial/final receipt/FIFO/GRNI with exact cumulative cents, authorization, replay and injected rollback; consistent list/export/filter projections. |
| M06 | Real controlled opening, issue, positive/negative count variance, explicit valued adjustment, same/cross-branch transfer dispatch/partial/final receipt, reservation/fulfillment, quantity reconciliation and source-linked correction/FIFO/GL lineage pass the valued action probe. Canonical M06 regression passes 113 assertions, including damaged/missing/recovered quantities, per-lot quarantine, forged accepted-as-damaged denial, partial Livewire receipt and immediate commit constraints. |
| M11 | Real isolated detailed opening/configuration, independent activation, dated inclusive/exclusive tax, recoverability and discounts, source journals/FIFO/GRNI/COGS. Manual journal create/submit/independent approve/post, immutable PDF, permission/control-account/unbalanced/outside-open-period denials and original 500-row-template XLSX preview now pass on isolated migration 170. |
| System | Genuine immutable mPDF bytes, source retries, annual numbering, private authorized download, SHA-256 retrieval and isolated restore proof. No production activation or statutory-registration claim. |

## D. Remaining issues and blockers

| Prior issue | Final result |
| --- | --- |
| Stale Sales Order readiness assertion | **RESOLVED.** Durable Luna-authored check passes all 155 assertions; aggregate campaign is 21/21. Negative security and business tests remain. |
| Extended Inventory browser stopped before receipt | **RESOLVED.** Run `CDB32D07A63A` passes dispatch, partial/final receipt, count, adjustment, partial/final delivery, authorization and final stock/FIFO/GL reconciliation. No application defect was demonstrated. |
| 26 Pint findings | **RESOLVED for mutable source.** Scoped application/factory/seeder/QA Pint passes. Seven applied migration files remain accepted legacy formatting debt and were not rewritten. |
| Corrected checks under ignored `.tools` | **RESOLVED.** Sales Order and M05/M06 roundtrip checks are repository-visible under `tests/Support`; canonical M06 and all Phase 0 support probes remain durable. |
| PostgreSQL recovery/concurrency address guard | **RESOLVED.** PostgreSQL 17-compatible strict guard passes; fresh restore and all four concurrency race classes pass. |

**Remaining genuine P0/P1 blockers: none.**

Functional boundaries remain intentional, not Phase 0 blockers: formal issued amendments/revisions, invoice/AR/AP matching and unsupported external/transfer corrections are not represented as completed by the quotation-to-delivery or internal correction path. Serialized financial stock remains restricted to completed owning contracts. M07, M08, full M11, M12 and M15 remain mandatory subsequent delivery.

## E. Test results

| Command / exercise | Final result |
| --- | --- |
| `php artisan test --filter=ModuleIdentityRegistryTest` | PASS, 2 tests / 5 assertions. |
| `php artisan test --env=testing` | PASS, 40 tests / 103 assertions, 14.66s; separate PostgreSQL `erp_elec_new_test`. |
| `php tests/Support/check-phase0-valued-inventory.php` | PASS, 14 named checkpoints with nested assertions for genuine Sales fulfillment, transfer/correction cost lineage, exact correction exhaustion and source-linked reversal journals; writes roll back. |
| `php tests/Support/check-phase0-direct-sales.php` on guarded port 55433 | PASS at migration 171: compliant direct order, separate price/discount/terms exception decision and all self/wrong-role/stale/changed-price/no-scope denials; rollback. |
| `php tests/Support/check-m05-m06-roundtrip.php` | PASS through completion marker with rollback: Purchasing draft/reopen/review/issue/receipt and Inventory opening/API/reconciliation/count/adjustment lifecycle, including negative security and stale-state checks. |
| `php tests/Support/check-m06-inventory.php` | PASS, 113 explicit assertions, immediate constraints and outer rollback, including damaged/missing/recovered transfer quantities and exact per-lot quarantine source. |
| `php .tools/run-safe-qa-checks.php` | PASS, 21/21 scripts, 0 failed, 0 incomplete, 873 explicit PASS assertions. Evidence `safe-check-run-20261028T132252Z.json`; Sales Order contributes 155 assertions. |
| `node tests/E2E/round2-masters.cjs` | PASS, 19 browser checks; isolated PostgreSQL database and actual HTTP asset validation. |
| User-only master browser subset | PASS, 4 checks. |
| `node tests/E2E/phase0-financial-browser.cjs` | PASS, run `CDB32D07A63A`, 24 checkpoints, no browser errors. Desktop plus Arabic RTL mobile 390px/document 390px, genuine PDFs/private evidence, deny-by-default 403 and final PostgreSQL reconciliation. |
| `php tests/Support/check-phase0-concurrency.php` | PASS: same-receipt uniqueness; two valid simultaneous postings with exact `2.00000000` stock delta and FIFO/GRNI; one-winner over-cap admission; distinct concurrent official numbers/documents. |
| Fresh PostgreSQL backup/private storage/restore | PASS: source migrations 61, restored 65 after four forward migrations, exact source counts/relationships, two private-file hashes, 19 nonempty official PDFs with matching SHA-256, controller statuses `200, 200, 403, 403`; `all_verified: true`. |
| `php tests/Support/check-phase0-financial-core.php` on guarded port 55433 | PASS: manual journal/PDF/denials and original `A1:L501` workbook preview; two rows in 0.369s, unchanged batch/line/activation counts. |
| `php -d opcache.enable_cli=0 vendor/bin/pint --test --format=json` | Expected non-green result limited to seven accepted applied migrations: 150–154 and 160–161. No mutable application, factory, seeder or QA file remains. |
| Scoped Pint on `app`, factories, seeders, `tests/Support` and the Phase 0 browser fixture | PASS. Formatting was mechanical; applied migrations were not rewritten. |
| `php artisan view:cache` | PASS. |
| `npm run build` | PASS, 22 modules in 16.28s. Noto URL warnings remain; both local font files exist and browser asset preflight passes. |

Evidence: [final extended browser result](../qa/evidence/phase0-financial-browser/CDB32D07A63A/result.json), [Luna verification receipt](../qa/evidence/phase0-luna-results.md), [valued action trace](../qa/evidence/phase0-valued-paths.json), [direct Sales trace](../qa/evidence/phase0-direct-sales.json), [M06 regression output](../qa/evidence/phase0-m06-current.txt), [standalone campaign](../qa/evidence/safe-check-run-20261028T132252Z.json), [financial core](../qa/evidence/phase0-financial-core.json), [concurrency trace](../qa/evidence/phase0-concurrency.json), [recovery trace](../qa/evidence/phase0-recovery.json), [recovery source manifest](../qa/evidence/phase0-recovery-source.json), [repeatable recovery runbook](PHASE0_RECOVERY_RUNBOOK.md). Historic failures, including `98AE0564FA1E`, remain retained and are not promoted to passing evidence.

## F. Integration acceptance

The Purchasing backend acceptance invokes actual purchase and root posting Actions; internal approval is never treated as issuance and an integration request is never treated as posted stock. The Sales backend acceptance invokes actual quotation/order/delivery Actions; no approved-order fixture bypasses the lifecycle. Inventory owns all stock/FIFO writes and Accounting owns all journals in the same PostgreSQL transaction. Delivery recognizes COGS/inventory effects; it does not invent an invoice, receivable or revenue posting.

Final browser `CDB32D07A63A` records 24 checkpoints across Sales, Purchasing and Inventory. It captures actual Livewire calls/responses; genuine quotation, PO and delivery PDFs/private acceptance evidence; desktop and Arabic mobile RTL screens; independent approvals; dispatch; separate partial/final transfer receipts; count; adjustment; backorder allocation; partial/final delivery; and the unprivileged lookalike Administrator denial. Guarded PostgreSQL readback proves transfer `received`, two receipts totaling `2.00000000`, exhausted transit, count `2.00000000 = 2.00000000`, adjustment `-1.00000000`, both warehouse balances equal their complete ledgers, and four source-linked FIFO/GL movements with exact quantities and values.

Real company Go-Live, balances and validated treatment remain customer activation inputs. Captured upload URL queries and CSRF fields remain redacted from browser artifacts.

## G. Readiness

**STABILIZATION COMPLETE — READY FOR REQUIRED MODULE COMPLETION**

No genuine P0/P1 stabilization blocker remains. Required next delivery scope is full M07 Projects, M08 Field Maintenance, M11 Accounting, M12 ReceivablesPayables and M15 Attendance. None was started as part of this closeout. This decision does not establish production release readiness or authorize real financial activation, balances or statutory treatment.
