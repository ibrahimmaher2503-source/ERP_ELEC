# V1 critical journeys and dependencies — 2026-10-27

> Phase 0 continuation, 2026-10-27: this file is the preserved pre-stabilization audit receipt. Current incremental changes, remaining blockers and acceptance are recorded in [PHASE0_STABILIZATION_REPORT.md](PHASE0_STABILIZATION_REPORT.md). Its historical absence and V1/V2 labels are not current implementation claims. M07 Projects, M08 Field Maintenance, full M11 Accounting, M12 ReceivablesPayables and M15 Attendance remain owner-required delivery scope; their expansion follows stabilization. No release readiness is implied.

**Sales: BLOCKED. Purchasing: PARTIAL, with a passing real receipt/GRNI backend slice. Inventory: PARTIAL.**

SOURCE VERIFIED means the current path was inspected; RUNTIME VERIFIED means a named isolated invocation succeeded; BROWSER VERIFIED is limited to observed current browser interactions. There is no end-to-end browser PASS for any of the three full journeys. All financial examples below are clearly synthetic rollback-only acceptance data in `erp_elec_new_test`; they are not company activation values.

## Journey A — Sales: BLOCKED

| Required step | Current implementation and evidence | Acceptance |
| --- | --- | --- |
| Customer | Real Parties records/lookup and customer snapshots; current master checks and create/reopen/edit browser interactions | RUNTIME VERIFIED and bounded BROWSER VERIFIED. |
| Quotation | Real quotation/line persistence and creation-key/version controls | RUNTIME VERIFIED in `check-m04-quotations.php`; drafts only. |
| Save/reopen/edit | Quotation actions and current 76-assertion check support bounded persistence/validation; old after-fix browser artifacts exist | RUNTIME VERIFIED; quotation browser evidence is HISTORICAL, not a fresh full journey run. |
| Official issue | `QuotationReadiness::requireIssueReady()` unconditionally throws; pricing binding returns null; quotation tax/official document consumer not complete | SOURCE VERIFIED blocker; deliberately refused by checks. No official quote issued. |
| Sales Order from quotation | Acceptance page controls disabled; conversion goes through readiness guard; SO source constraint is direct-only | SOURCE VERIFIED missing integration. A directly created draft is not accepted-quotation conversion. |
| Approval | SO guard always throws; schema permits draft/pending_approval/cancelled and pending/withdrawn/rejected reviews, not approved | SOURCE VERIFIED blocker. No actual approved SO can be produced through the current lifecycle. |
| Inventory reservation | `SalesOrdersService::inventoryDemand()` requires an approved real order; real producer cannot supply one | BLOCKED BY DEPENDENCY. Stub-based Inventory checks do not satisfy this. |
| Delivery | Guarded `delivery_create/delivery_confirm`; no executable real delivery source leading to completed Sales stock effects | SOURCE VERIFIED blocker. |
| Stock deduction | Inventory adapter has reservation/issue infrastructure, but real Sales cannot reach it; ledger rejects the unsupported valued source | NOT EXECUTABLE end-to-end. No Sales deduction claimed. |
| Accurate order status | `getOrder()` returns fixed reservation `not_requested`, delivery `not_started`, invoicing `not_requested` | SOURCE VERIFIED placeholder projection; not fulfilled-state acceptance. |

Exact current files:

- `app/Modules/Sales/Infrastructure/UnavailableQuotationPricing.php`, bound from `app/Providers/AppServiceProvider.php`.
- `app/Modules/Sales/Application/QuotationReadiness.php`, `QuotationActions.php`, `QuotationApprovals.php`, `SalesOrderReadiness.php`, `SalesOrderActions.php`, `SalesOrdersService.php`.
- `app/Modules/Sales/Presentation/QuotationPdfPage.php`, `QuotationAcceptancePage.php`, `QuotationConversionPage.php`, `SalesOrderDetailsPage.php`.
- `resources/views/sales/quotations/acceptance.blade.php` and order detail Blade.
- `database/migrations/2026_10_21_120000_create_sales_order_drafts.php`.
- `app/Modules/Inventory/Application/InventoryReservations.php`, `InventoryLedger.php` and published `InventoryApi`.

The current Sales browser run `11713F4EAD7E` completed only guest redirect/RTL and a wide-list capture before its Save click timed out. Its server routes static assets incorrectly (HTML MIME responses for module scripts), leaving an oversized unstyled sidebar logo intercepting the click. This is failed browser evidence, not proof of a successful draft save or a production stylesheet defect. Current backend checks verify many drafts/denials, not the unavailable commercial lifecycle.

**Required next acceptance:** create the customer and quote through real actions, reopen/edit it, issue and retrieve its immutable revision, record authorized acceptance, convert once, independently approve a real SO, reserve under actor/warehouse scopes, confirm a real delivery, consume actual FIFO stock/GL and update partial/final status. Replay and cross-scope denial must preserve exact stock, cost and audit effects. No direct approved-state insertion or stub Sales producer is acceptable.

## Journey B — Purchasing: PARTIAL

The backend positive slice now works; old absence claims in the ship matrix and historical reports are obsolete.

| Required step | Current implementation and execution | Acceptance |
| --- | --- | --- |
| Supplier | Active real supplier master, snapshot and current supplier revalidation at approval/issue | RUNTIME VERIFIED. |
| PO | Real direct and awarded PO drafts; save/edit/version/idempotency; exact EGP line values | RUNTIME VERIFIED by recovery/roundtrip checks and final probe. |
| Approval | Actual submit then a different authorized approver; self-approval rejected | RUNTIME VERIFIED. |
| Official issue | Real tax configuration and go-live gate, frozen tax snapshot, System number and mPDF bytes | RUNTIME VERIFIED; final probe PO `PO-2024-000001`, genuine 47,702-byte PDF. |
| Goods receipt | Real draft and submit to `integration_pending`; no stock before post; independent receipt posting actor | RUNTIME VERIFIED. Pending is an intermediate state, not success. |
| Actual stock increase | Root `PostPurchaseReceipt` calls real Inventory receive; actual balance/ledger updates | RUNTIME VERIFIED: 1 then 2 units received, final on-hand 3.00000000. |
| FIFO layers | Real source movements produce actual FIFO layers | RUNTIME VERIFIED: two source layers, values .34 and .67, total 1.01. |
| GRNI | Accounting source posting produces two-line balanced Inventory DR / GRNI CR journal for each nonzero receipt | RUNTIME VERIFIED: first receipt DR=CR=.34; final .67; current constraints checked. |
| Posted source refs | Receipt stores actual journal ID and line posting rows bind actual movement/layer/source-line IDs | RUNTIME VERIFIED: final probe partial receipt 7→journal12/movement12/layer10; final receipt8→journal13/movement13/layer11. All rolled back afterward. |
| Partial/final receiving | Quantity ceilings and exact cumulative value allocation work. Parent PO lacks an explicit accurate partial/final receiving lifecycle projection | Backend quantities RUNTIME VERIFIED; operational projection incomplete. |
| Repeated submission/post | Existing draft submit retry plus same receipt posting retry do not duplicate effects | RUNTIME VERIFIED sequentially; concurrent workers NOT TESTED for the current real flow. |
| Failure rollback | Injected exception after Purchasing completion, after stock/FIFO/GL/source writes, leaves pending receipt and pre-attempt counts | RUNTIME VERIFIED, including no extra official document. |

Exact orchestration and owning files:

1. `app/Modules/Purchasing/Application/PurchaseOrders.php`: drafts, independent approval, actual official issue and reported-tax mismatch rejection.
2. `PurchaseReceiving.php`: draft quantities, warehouse/source authorization, submit/retry.
3. `app/Application/PostPurchaseReceipt.php`: outer transaction coordinating published owner APIs.
4. `app/Modules/Purchasing/Application/PurchaseReceiptPosting.php`: locked eligible source, frozen issued commercial values, remaining quantities, cumulative cent allocation and final source references.
5. `app/Modules/Inventory/Application/FinancialStock.php`, `InventoryLedger.php`, `FifoValuation.php`: actual balance/movement/layer production.
6. `app/Modules/Accounting/Application/AccountingService.php`, `JournalPosting.php`: actual source-controlled balanced GRNI posting.
7. `app/Modules/System/Application/IssueOfficialDocument.php`: real number/PDF snapshot.
8. Migrations `2026_10_27_155000` through `159000`: NUMERIC financial storage, immutable evidence and deferred source/stock/GL consistency guards.

The test used quantity 3 × price .335, exclusive synthetic 14% tax: net 1.01, tax .14, total 1.15. Recoverable tax is excluded from inventory value. Cumulative allocation capitalizes .34 then .67, exactly exhausting 1.01; no lost or duplicated cent. This is a test arithmetic case, not an Egyptian tax-rate recommendation or production setting.

Remaining gaps:

- `OrdersPage.php` and `resources/views/purchasing/orders.blade.php` read parent `official_number`, while issuance writes `purchasing_order_issues`. The list can incorrectly say unissued; `PurchasingExportController.php` exports the same stale field. The detail view has the real issue source.
- Parent PO status is internal approval, without an explicit received-partial/received-final operational projection. Actual receipt quantities are not evidence of a finished receiving UI.
- Actual issue/post/PDF download from the browser and HTTP cross-scope denial are not current accepted journeys.
- Real concurrent same-receipt replay, competing receipts against remaining PO quantity, sequence contention and deadlock recovery have not been proved.
- Supplier invoice matching, GRNI clearing/PPV, ordinary AP and payments are absent. The frozen matrix defers advanced AP/Treasury. They are not silently claimed complete and are not made universal P0 work for a receipt/GRNI-only V1. If a pilot is sold as invoicing/payment-capable, that would exceed the evidenced scope and require explicit completion before those operations.

**Required next acceptance:** correct issue/receiving projections; drive the actual pages through approval, issue, private PDF and partial/final posting; run two real PostgreSQL workers using real approved/issued POs; prove over-receipt denial and exact stock/FIFO/GRNI under success, retry and failure. Keep the current fail-closed financial controls.

## Journey C — Inventory: PARTIAL

| Required step | Current source and execution | Acceptance |
| --- | --- | --- |
| Warehouse | Actual warehouse action, active branch/warehouse checks and explicit scopes | RUNTIME VERIFIED by probe setup; broad current warehouse browser journey NOT TESTED. |
| Opening stock | Correct supported path is detailed financial opening, approved independently and activated atomically | RUNTIME VERIFIED: FIFO opening quantity2/value20, with reconciled GL/AP/cash/bank. Old Inventory opening-document path is deliberately blocked. |
| Balance | Real opening movement and balance established, no direct stock balance shortcut in financial probe | RUNTIME VERIFIED. |
| Available stock | Actual availability query enforces `M06.balance_view` and warehouse scope; reservation infrastructure exists | RUNTIME VERIFIED bounded opening query; reservation contention passes only with a stub Sales source. |
| Internal issue | Real draft→submit→independent approve→post, FIFO and GL synchronously | RUNTIME VERIFIED: issue1.5 from2 costs15, DR=CR15, balance.5. |
| Transfer dispatch | Transfer document code exists, but `InventoryLedger::post` rejects `transfer_dispatch` as an unsupported valued source | SOURCE VERIFIED blocker, regardless of activated setup. |
| Receive transfer | Transfer receipt code exists, but unsupported valued ledger path cannot complete | SOURCE VERIFIED blocker; no current actual valued transfer success. |
| Physical count | Real cutoff snapshot/count approval and linked variance-adjustment drafting exist | SOURCE VERIFIED; fresh end-to-end valued count campaign NOT TESTED. Count approval with variance is not a posted adjustment. |
| Adjustment | Negative adjustment has FIFO/GL code; positive `adjustment_post` is rejected by the ledger gate | Positive case SOURCE VERIFIED blocker; negative adjustment itself NOT separately RUNTIME VERIFIED in this audit. |
| Ledger reconciliation | `InventoryReconciliation::warehouse()` compares quantities, reservations and isolation; it does not reconcile monetary FIFO against GL | SOURCE VERIFIED implementation. Probe checked its own quantities/values/source refs, not a complete production reconciliation screen. |

Exact files: `InventoryActions.php`, `InventoryAccess.php`, `InventoryDocuments.php`, `InventoryQuery.php`, `InventoryReservations.php`, `InventoryTransfers.php`, `InventoryLedger.php`, `FinancialStock.php`, `FifoValuation.php`, `InventoryReconciliation.php`, `InventoryCorrections.php`, `InventorySerials.php`, `InventoryConditions.php`, plus owning presentation components under `app/Modules/Inventory/Presentation`.

The decisive ledger gate allows financial openings, purchase receipts and negative issue/adjustment postings. Other operations first check posting activation/date and then fail with the missing FIFO/financial integration message. Activating real company data would not implement the missing paths. Do not bypass this guard to obtain a misleading stock-only pass.

Serial infrastructure is substantial, but `FinancialStock::receive` rejects tracking other than quantity. Serial-valued opening/receiving is not accepted. The specific pilot must use supported quantity goods unless actual serial-valued workflows are implemented and tested. Optional advanced serial/condition features do not justify expanding the entire ERP.

**Required next acceptance:** controlled opening → real balance/availability → independently approved issue → cost-preserving transfer dispatch/partial-or-full receive → physical count → approved negative and positive adjustment → reconcile movement quantities, FIFO remaining values and relevant inventory GL. Denied source/destination scopes, duplicate workers and rollback must leave no partial effects.

## Critical dependency audit

| Dependency | Exists / executable? | Current execution evidence | Connected consumers | Blocks V1 / exact remediation |
| --- | --- | --- | --- | --- |
| Official numbering | Yes; System company/type/year UPSERT plus source/revision identity | PO and financial source issue/retry PASS; actual concurrent issuer NOT TESTED | Purchasing, opening and journals; not Sales | P0 for Sales consumer; add supported issued Sales lifecycle, then real multiworker proof. |
| Genuine server PDF | Yes; mPDF UTF-8 RTL bytes, no HTML file masquerading as PDF | `%PDF-`, 47,702 bytes and SHA-256 PASS | Same three consumer types | P0 for quotation/SO consumer; P1 for HTTP/visual/content acceptance. |
| Immutable PDF | Yes; snapshot/hash/base64 private DB storage and immutable trigger | Retrieve/hash, rejected UPDATE and DELETE PASS | Issued POs/financial evidence | Core works; complete authorized download and restore including populated evidence. |
| Official revisions | Generic revision key exists; business consumers issue revision1 | Only same-revision replay verified | No accepted Sales revision or applied PO amendment workflow | P0 as part of required Sales issue/accept-convert semantics; P1 minimum issued-document correction procedure. No advanced revision framework requested. |
| Configured tax | Effective dates, product/transaction specificity, percentage/exemption, mode, eligible discounts, recoverability and captured values | Exclusive1.01/.14/1.15; inclusive discounted1.93/.27/2.20; future exemption selection; reported override rejected | Purchasing/financial core, not official Sales | Minimum engine works; connect Sales. Validate actual customer tax before activation. Unsupported tax types must stay disabled for transactions needing them. |
| Cutover/openings | Seven-step setup, reconciliation, independent current-version approval and activation | Actual detailed GL/AP/cash/bank/FIFO and no-balances declaration both PASS; pre-Go-Live denied | Root activation and owning opening contracts | No missing owner date/data blocks development. Excel UI and upgrade/import acceptance still needed. |
| COA | Real accounts and type/mapping validation | Real synthetic mapping accounts used for opening/GRNI/issue PASS | Accounting and source journals | Minimum works; customer chart/mappings are activation inputs. Hierarchy/reporting beyond core V2. |
| Fiscal periods | Real period records/open-date posting check | Open synthetic periods used successfully; full period-close race NOT TESTED | Journal/source posting | Minimum exists; verify closed-period and posting contention before release. |
| Journal posting | Immutable official balanced two-place posting with controlled-source restrictions | Opening, receipt and issue journals PASS | M05/M06 sources and manual foundation | Connect missing sources; manual-journal negative/reversal acceptance not established. |
| Account mapping | Fourteen required purpose mappings; versioned setup | All14 used/validated in synthetic setup PASS | Activation, stock and journals | Real customer mappings required only at activation; don't insert defaults as owner balances. |
| FIFO | NUMERIC/BCMath layers and cumulative residual allocation, locked ordered consumption | Opening, receipts .34+.67 and issue15 PASS | Supported M06/M11 sources | Missing valued transfer/positive adjustment/Sales paths P0. FIFO classes alone do not complete them. |
| GRNI | Real atomic root, source refs and deferred consistency guards | Positive, replay, unauthorized denial, injected rollback and constraints PASS | Actual M05→M06→M11 | Real concurrent receipt acceptance remains P0 verification. |
| Supplier invoice matching/PPV | Not implemented as a working invoice/match/clear producer | NOT TESTED; absent | No ordinary AP source | Deferred by frozen receipt/GRNI-only scope; explicitly exclude invoicing/payment from delivery claim. If included later, implement owning contracts before enabling it. |
| AP/payment contracts | Opening contracts exist and execute; ordinary AP/payment-source contracts absent | Opening AP10/cash10/bank5 PASS | M11 opening only | Minimum opening dependency works. Ordinary collections/payments V2. |
| User/branch/warehouse RBAC | Yes; active/verified actor, registered permission, explicit warehouse and branch rules in owning actions | Broad M01/master negative tests and no-grant receipt denial PASS | All current owner APIs/controllers | No demonstrated bypass; complete new financial/stock HTTP/negative matrix P1. Administrator default grant is authorized policy, not a bypass. |
| Audit logs | Actual events/append-only safe snapshots and user-specific history | Existing M01/history checks and source event paths; full new posting audit assertions NOT TESTED | Organization owns security; System owns business history | Preserve real attribution and immutable records. Verify new successful/failed commands without inventing security history. |

## Financial probe provenance and limitations

Luna authored the new audit-only check as required by AGENTS.md; the lead reviewed, corrected fixture expectations through the author, and executed it. It remains outside application source:

- Script: `C:\Users\N\AppData\Local\Temp\financial-audit-probe.php`.
- Script SHA-256: `6BD5AB2A031DB7172A7B08C515BA1DD7A7B84E3AB2E98346B4629904B65F5049`.
- Final trace: `C:\Users\N\AppData\Local\Temp\financial-audit-probe-stdout.json`, final run 2026-10-27 approximately 12:47 Cairo, exit0, `all_pass=true`.
- Exact driver/database check: PostgreSQL17.11, `erp_elec_new_test`; private storage redirected to a unique temporary directory before bootstrap; all writes rolled back in `finally`; no posted fixture was inserted directly.
- Initial run had one fixture authorization failure (missing `M06.balance_view` for detailed opening lookup). The author added the real capability to synthetic actors and reran the actual lifecycle. It was not an application fix. The final run above passed all13 checkpoints. Intermediate passing checkpoints are not counted again.

The probe verifies real persisted effects within its transaction and forces deferred constraints immediately, but deliberately rolls everything back. It is not a committed production cutover, a two-worker race, HTTP download acceptance or a complete tax-law certification. Those limits remain visible in the release gates.
