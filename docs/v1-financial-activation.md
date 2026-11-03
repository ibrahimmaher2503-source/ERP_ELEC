# V1 financial activation, tax and rounding

Phase 0 continuation (2026-10-27): the preserved implementation receipt below predates the current stabilization work. See [Phase 0 changes and direct acceptance evidence](release/PHASE0_STABILIZATION_REPORT.md). The bounded accepted-quotation/Sales reservation/delivery/FIFO/COGS path, valued transfer/adjustment paths and internal source-linked corrections now have incremental implementations under current QA. The original fourteen mappings remain, with an additional explicit inventory-in-transit purpose for cost-preserving transfers; no real company mapping or activation data was fabricated. Earlier V2 labels do not permanently defer owner-required M07/M08/full M11/M12/M15. Current completion/readiness follows the Phase 0 report, not the historical absence statements below.

Owner clarification adopted 2026-10-27. This supersedes historical statements that the general tax/precision/cutover policy is missing. Actual customer dates, tax treatment and balances remain activation inputs. Development does not require production activation.

Status: implemented locally, acceptance/automated QA deferred. No Go-Live date, tax rate, opening balance, stock quantity, journal or supplier liability was seeded or posted during this work.

## Implementation

`/accounting/setup` reuses the ERP shell, heading, panels and Flux controls. Seven steps cover company Go-Live, minimum mappings, tax configuration, manual/XLSX opening preparation/preview, reconciliation, independent approval and activation. Preview and save never post. Read permissions retain branch/warehouse scope, including on opening detail.

Accounting owns configuration, tax, opening staging/reconciliation and GL. Root `ActivateFinancialPosting` coordinates published Accounting, Inventory, ReceivablesPayables and Treasury contracts in one PostgreSQL transaction. The latter two owners implement opening contributions only, without payments, allocations, settlements or optional reports.

The singleton configuration begins with NULL Go-Live and inactive posting. Activation freezes the date/mappings. A configuration change before activation invalidates prior opening approval by version. Only the newest approved batch at the current version may activate. Its approver must differ from both its preparer and the current configuration preparer. Administrator receives registered permissions automatically but never bypasses independence, scope or source eligibility.

Opening GL balances per branch and reconciles supplier AP, cash, bank and inventory detail to their controls. Details are not journaled twice. Each FIFO layer has a unique reference and retains its supplied decimal cost. Import order establishes the approved oldest-first opening FIFO order within each product/warehouse; recognition date is Go-Live. Existing stock/movement history is never replaced. An explicit, evidenced, independently approved declaration of genuinely no opening balances creates no fake zero journal and is never selected automatically.

Opening content freezes at submission. Posted journals/contributions, tax rules/endings, issued snapshots and PDF evidence retain immutable history. Ordinary posting requires activation, a date on/after Go-Live, an open period and eligible accounts. Manual journals cannot bypass inventory, GRNI, AP, cash, bank or customer control accounts.

System allocates company/type/document-year official numbers and renders real Arabic PDF with mPDF. Source snapshot and private PDF bytes commit in PostgreSQL together; failed issuance rolls back numbering. SHA-256 is checked on retrieval. Owner controllers authorize downloads; PDFs are never regenerated from edited masters. This renderer does not register or electronically sign tax documents.

Approved EGP purchase orders can issue after financial activation/Go-Live using their saved inclusive/exclusive mode, eligible supplier/products and validated tax configuration. Their immutable issued tax/PDF record is distinct from internal approval. Supplier-reported tax is compared, never used as an override. PO issue creates no stock or AP.

Root `PostPurchaseReceipt` coordinates genuine issued-PO eligibility, stock movement/FIFO, a balanced inventory/GRNI journal and final receipt evidence synchronously. The poster differs from the receipt preparer; M05 receipt-post, M06 warehouse receipt-post and M11 receipt-post scopes/permissions apply. Posted retries add no effects. Partial values use cumulative allocation; the final receipt exhausts the exact issued capitalizable amount. Recoverable purchase tax is excluded from receipt cost/GRNI; explicitly nonrecoverable tax is capitalized.

Internal issues and negative adjustments consume FIFO and post their configured expense/inventory journal atomically. Zero allocated cost creates no fabricated zero journal. Positive adjustments, transfers, Sales delivery and financial corrections remain blocked until their actual costing/source integration exists. Historical quantity-only evidence does not establish current financial acceptance.

## Arithmetic and tax contract

`AccountingApi.calculateTax` selects rules by transaction, product and effective date. A product-specific rule takes precedence over an explicitly configured transaction rule. Missing/ambiguous rules fail closed; there is no default universal rate or client code/rate/amount override. Product master remains independent of tax configuration.

V1 supports one percentage tax or explicit exemption per line, inclusive/exclusive pricing, and eligible commercial discounts before tax. Purchase rules require an explicit recoverability decision. Compound/fixed taxes, withholding and other special treatments need their applicable producer before those transactions can issue; a percentage is not a substitute.

Inputs and authoritative calculations use decimal strings/BCMath and PostgreSQL NUMERIC. Internal scale is 32; FIFO costs and remaining/allocated raw values retain 16 fractional places. Posted EGP amounts have two places. Rounding is half away from zero; largest remainders allocate permissible cents with source order breaking ties. FIFO uses cumulative allocations, with the final consumption taking the exact remaining value. Deferred constraints reconcile layer quantities, raw values and posted allocations. Journals balance exactly, without an invented balancing line.

Tax rules select document precision 2 or 5 with an actual supporting reference. Snapshots preserve raw values, document/posting values, rounding differences, code, rate, mode, recoverability and summaries. Inclusive totals preserve the discounted agreed gross. Tiny values that cannot reconcile under the selected rule fail explicitly. Tax rules remain immutable; audited ending/withdrawal records retain history. After activation, new schedules start in the future and historical rules cannot be withdrawn.

## Actual customer activation prerequisites

1. The real company-wide Go-Live date, legal company identity and address. No date is inferred from the clock or fixtures.
2. Actual active/postable accounts and separate mappings for inventory, GRNI, PPV, AP/AR, cash/bank, input/output tax, sales, COGS, internal consumption, stock adjustments and permissible rounding. An open period covers Go-Live.
3. Competent company validation of actual tax codes/rates, exemptions, effective dates, recoverability, eligible discount treatment and rounding, with an evidence reference. Applicable sale/purchase rules cover Go-Live and each actual product/transaction. No production rate is shipped.
4. Evidenced detailed opening GL, supplier liabilities, cash, bank and oldest-first FIFO, reconciled and independently approved with current configuration/scopes. Existing stock history needs a real migration/reconciliation plan. Opening customer AR/unmatched GRNI, supplier debit/advances, bank overdrafts and serial opening stock require their detailed owning integration if present; they are not silently netted or fabricated.
5. Where electronic tax documents apply, validate the applicable ETA document/version, issuer/branch/activity/product codes, required certificates/signatures and submission/registration flow before statutory issuance. Local PO/JV PDF is not ETA acceptance. Official [invoice fields/precision/signatures](https://sdk.invoicing.eta.gov.eg/documents/invoice-v1-0/) and [receipt calculations](https://sdk.invoicing.eta.gov.eg/main-calculations/) differ; one two-place VAT assumption does not establish compliance. Actual applicability remains company/transaction-specific.
6. Current isolated acceptance and release evidence before customer use. Production activation/data is unnecessary for this development and testing, and cannot be bypassed for convenience.

## Factories, demos and execution boundary

TaxRule/OpeningBatch/OpeningLine factories provide explicit synthetic calculations and balanced unapproved preparation. Tax fixtures are not composed into local/production seeding. Opening payable, Treasury and FIFO factories expose workflow-backed creation from approved synthetic sources; they invoke actual activation/receipt actions, preserving approval/authorization/atomic controls. They never forge posted history with default `factory()->create()`. These entrypoints require `testing`, PostgreSQL and a database ending `_test`, including the configured `erp_elec_new_test`.

Explicit local/testing-only `php artisan db:seed --class=FinancialActivationDemoSeeder` is repeat-safe, is composed into the default EgyptMarketDemoSeeder, and reuses existing identifiable account masters. AP/Treasury composers deliberately seed no financial facts. No dates, rates, mappings, amounts, approvals, grants, layers or posting evidence are added. No demo seeder was run during this work.

Local migrations 155–159 applied. Blade compilation and Vite build succeeded; existing unresolved Noto font URL warnings remain. No new automated test/check code was authored or run, no test database was populated, and no browser/mobile/concurrency/PDF issuance/financial workflow acceptance was performed under the current owner deferral. Future test/check code remains Luna-authored when explicitly requested. Compilation is not financial acceptance.

Remaining V1 work includes real Sales commercial/issued-source and delivery integration, purchasing invoice matching/PPV/AP integration for released journeys, valued transfers/positive adjustments/corrections, and current regression/release/backup acceptance. Advanced M11 analytics and optional reports remain V2. Complete V1 release readiness is not claimed.
