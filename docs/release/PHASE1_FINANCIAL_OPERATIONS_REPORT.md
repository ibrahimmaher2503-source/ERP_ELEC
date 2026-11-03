# Phase 1 financial operations report

Completed 2026-10-28 (Africa/Cairo). This is a local PostgreSQL acceptance report, not a production activation, remote publication, or assertion of real company balances.

## A. Implemented

### Ownership and contracts

- M11 Accounting remains the sole owner of chart of accounts, periods, journals, activation, account mapping, tax, General Ledger, reporting, reconciliation and period close. `AccountingApi::postSourceDocument()` is the operational posting boundary; protected control accounts remain unavailable to manual journals.
- M12 ReceivablesPayables owns supplier/customer invoices, three-way matches, AP/AR open items, allocations, credit notes, party credits and customer-advance subledger evidence. Purchasing publishes posted receipt evidence, Sales publishes confirmed-delivery commercial evidence, Inventory publishes FIFO valuation, and Treasury calls M12 settlement contracts. No M12 code posts stock or creates FIFO layers.
- M13 Treasury owns operational bank/cash accounts, payment requests, actual movements and direct transfers. The Phase 1 slice does not duplicate Treasury balances in M11/M12.
- Dynamic branch/company permissions were added under their real owners. Administrator synchronization remains governed by `Organization.SyncAdministratorPermissions`; other roles remain default-deny.

### Database and integrity

Forward-only migrations 172–177 add the operational financial schema and guards:

| Migration | Result |
| --- | --- |
| 172 | Supplier/customer invoices and lines, receipt matches, AP/AR open items, credit notes, settlement allocations, operational Treasury accounts/requests/movements, source-journal constraints and M11/M12/M13 permission synchronization. |
| 173 | Atomic paired bank/cash transfers and partial uniqueness for externally idempotent movements. |
| 174 | Immutable supplier/customer party-credit balances produced when a posted credit exceeds current open-item value. |
| 175 | Narrow posted-invoice projection transitions for partial/full settlement while preserving frozen financial evidence. |
| 176 | Immutable M12 customer-advance evidence linked to the real Treasury collection movement. |
| 177 | One-time post-activation insertion of the approved optional `customer_advance` mapping; existing/mandatory mappings remain frozen. |

All monetary columns are PostgreSQL `numeric`; calculations use decimal strings/BCMath and post at two decimals. Row/advisory locks, source/version checks, unique source/idempotency keys, immutable official documents, append-only audit, open-period checks and branch permissions execute inside the posting transactions.

### Supplier invoices and AP

- Genuine PO + posted receipt + supplier invoice matching supports partial/multiple receipts and invoices, exact cumulative-cent GRNI clearing, supplier revalidation, different invoice prices, recoverable/nonrecoverable tax and PPV.
- Lifecycle: draft → validated → matched → approved → posted → partially paid → paid. ACC-11 is preserved: an authorized accountant may approve/post the supplier invoice without applying the manual-journal independent-approval rule.
- Posting is `DR GRNI`, recoverable input tax where applicable, `DR/CR PPV`, `CR AP`; it neither reposts Inventory nor changes FIFO.
- AP open items expose original, settled, credited and remaining values, due date and aging. Locked allocations support partial/full payment, multiple allocations and one payment across multiple invoices without negative outstanding balances.

### Customer invoices and AR

- Customer invoices consume only confirmed Sales delivery lines and their frozen approved commercial/tax snapshots. Advisory locks cap both delivery-line and Sales-order quantities and prevent double billing.
- Posting is `DR AR`, `CR Sales`, `CR output tax`. Sales delivery remains the owner of Inventory/COGS, so invoice posting does not duplicate COGS.
- Lifecycle supports draft validation, official issuance/posting, partial collection and full collection. AR open items and aging use the same settlement projection as AP.

### Treasury, credit and correction

- Supplier payment requests require independent approval (ACC-13), real execution, available bank/cash funds, balanced GL, AP allocation, immutable evidence and safe retry.
- Customer collections allocate AR and may record an explicitly allowed excess as a mapped customer advance liability with linked M12 evidence. Missing mapping or implicit excess is rejected.
- Direct bank/cash transfer requires real funds and posts only asset-to-asset movement with paired immutable Treasury rows; it creates no revenue or expense (ACC-15).
- Supplier/customer credit notes require an original posted invoice, reason, independent approval, frozen proportional tax reversal and a strict remaining-creditable cap. The original invoice is never edited. Post-settlement excess becomes an immutable party credit and is included in control-account reconciliation.
- Manual journal reversal creates one balanced, source-linked reversing journal and leaves the original/PDF unchanged. Operational source journals cannot be reversed from M11 alone; correction must use the owning source Action so Inventory/AP/AR/Treasury remain reconciled.

### M11 operations and UI

- General Ledger, Trial Balance, Income Statement, Balance Sheet, GRNI, PPV, AP/AR/FIFO/Bank/Cash/customer-advance reconciliation and guarded period close are driven from posted sources.
- Trial Balance and financial reports export real XLSX/PDF with branch/date filters and private/no-store headers.
- Period close locks the period and is denied for unposted journals or any failed mandatory reconciliation.
- Arabic RTL Livewire/Flux pages use the established light shell for M11 reports, M12 invoice/AP/AR/credit/aging registers and details/matching, and M13 accounts/payments/collections/transfers. Enabled financial buttons invoke real owner Actions. Desktop/mobile screens contain no placeholder balances.
- The existing design system guided the compact forms, dense tables, pale panels, royal-blue controls and responsive RTL behavior; it did not change the approved architecture.

### Factories and local/testing seed composition

- `SupplierInvoiceFactory`, `CustomerInvoiceFactory` and `TreasuryAccountFactory` persist valid draft/configuration records only; they do not fabricate approval, posting, payment or privilege history.
- `FinancialOperationsDemoSeeder` is explicitly invoked by `EgyptMarketDemoSeeder`, is idempotent and creates only clearly identified Treasury configuration when the required real mappings exist. Operational balances and financial events are not invented.

## B. Business acceptance

| Area | Result | Evidence |
| --- | --- | --- |
| Supplier invoice / three-way match | PASS | Three invoices across three real receipts; 4 units, GRNI 40.00, net 44.00, input tax 6.16, PPV 4.00, AP 50.16; stock/FIFO unchanged. |
| AP / supplier payment | PASS | Partial and final actual payments; same-key retry; remaining AP 0.00; independent request approval. |
| Customer invoice / AR | PASS | Approved Sales Order → confirmed delivery/COGS → immutable invoice/AR → partial/final collections; remaining AR 0.00. |
| Credit notes | PASS | Independently approved supplier and customer notes, proportional tax/GL correction, party-credit evidence and exact AP/AR reconciliation. |
| Bank/cash | PASS | Actual movements, available-funds checks and direct cash-to-bank transfer with paired evidence and no P&L effect. |
| GL and reports | PASS | Trial Balance 294.76 debit / 294.76 credit; Income Statement and Balance Sheet source rows; XLSX/PDF exports. |
| Reconciliation | PASS | AP, AR, GRNI, FIFO Inventory, bank and cash all differ by 0.00; optional customer-advance control is included when mapped. |
| Closing/correction | PASS | Successful reconciled close, closed-period posting denial, immutable manual-journal reversal and source-owner reversal enforcement. |

## C. Acceptance evidence

### Phase 1 PostgreSQL journeys

- `php -d opcache.enable_cli=0 tests/Support/check-phase1-financial-journeys.php` — PASS on `erp_elec_new_test` at `127.0.0.1:5443`. Evidence: `docs/qa/evidence/phase1-financial-journeys.json`.
- `php -d opcache.enable_cli=0 tests/Support/check-phase1-supplier-invoice.php` — PASS. It additionally retains duplicate supplier number, supplier mismatch, overmatching, unauthorized actor and closed-period negative checks. Evidence: `docs/qa/evidence/phase1-supplier-invoice.json`.
- `php -d opcache.enable_cli=0 tests/Support/check-phase1-journal-reversal.php` — PASS: exact line inversion, one reversal on retry, original unchanged, reversal-of-reversal denied, source journal reversal denied. Evidence: `docs/qa/evidence/phase1-journal-reversal.json`.
- Factory persistence inside the journey passed for draft supplier/customer invoices and bank/cash Treasury accounts.

### Concurrency and rollback

The Luna-authored worker campaign uses independent PHP processes and PostgreSQL connections. `PHASE1_REUSE_COMMITTED_FIXTURES=1 php -d opcache.enable_cli=0 tests/Support/check-phase1-financial-concurrency.php` passed with `all_pass: true`. Final evidence is `docs/qa/evidence/phase1-financial-concurrency.json`.

PASS. The campaign verified concurrent duplicate supplier-invoice posting (one invoice, journal, PDF and AP item), official-number contention (distinct numbers), competing supplier matching and AP allocations (one winner, one rejection, no over-allocation), same-key payment retry (one movement and allocation), concurrent customer invoicing (one posted invoice), supplier-credit over-allocation prevention, customer-advance liability posting, injected PostgreSQL failure rollback with no partial records, and period-close versus posting (close won; posting remained unposted). Every journal remained balanced and `all_pass` is `true`.

### Browser, PDFs and exports

- `node tests/E2E/phase1-financial-browser.cjs` — PASS run `632748F345`: 19 desktop and 12 mobile authenticated pages. Phase 1 browser evidence is under `docs/qa/evidence/phase1-financial-browser/`. Microsoft Edge/Playwright exercises Arabic RTL at 1440×900 and 390×844 with no horizontal overflow, browser errors or failed Livewire requests.
- Persisted supplier/AP/customer/AR/credit/Treasury values render from PostgreSQL. Supplier/customer invoice details show frozen lines; supplier details show receipt matches and GRNI.
- Authorized supplier/customer/Treasury PDFs match immutable hashes. Trial Balance XLSX and PDF signatures/content disposition/private caching pass. An active unscoped actor receives HTTP 403 for all protected downloads. No credential is stored in evidence.

### Phase 0 regression

- `php artisan test --env=testing` — **40 passed, 103 assertions**.
- `php .tools/run-safe-qa-checks.php` — **21/21 scripts, 0 failed, 0 incomplete, 873 explicit PASS assertions**; includes the 155-assertion Sales Order regression. Evidence: `docs/qa/evidence/safe-check-run-20261028T200933Z.json`.
- `tests/Support/check-m05-m06-roundtrip.php` — PASS on isolated PostgreSQL; full Purchasing/Inventory lifecycle round trip.
- `tests/Support/check-m06-inventory.php` — PASS; focused Inventory permissions, ledger, reservation, transfer, count, adjustment, correction and deferred-constraint coverage.
- `tests/Support/check-phase0-valued-inventory.php` — PASS on a fresh isolated `55433` database; exact FIFO/GL/tax/PDF/rollback and partial/final receipt paths.
- `tests/Support/check-phase0-direct-sales.php` — PASS on restored isolated PostgreSQL.
- `tests/Support/check-phase0-financial-core.php` — PASS on restored isolated PostgreSQL.
- `tests/Support/check-phase0-concurrency.php` — PASS with two workers for receipt retry, two valid receipts, remaining-quantity contention and official numbering.
- `node tests/E2E/phase0-financial-browser.cjs` — PASS run `4FB4930BCB41`: real Sales/Purchasing/Inventory journey, partial/final transfer receipt, count, negative adjustment, partial/final Sales delivery, mobile RTL, unprivileged 403 and final stock/FIFO/GL reconciliation.
- Recovery: the Phase 0 dump was restored to disposable PostgreSQL `127.0.0.1:55433`, migrations 168–177 were applied forward, source counts/relationships and 19 private official PDFs/files matched hashes, authorized controllers returned 200 and denied controllers returned 403. Evidence: `docs/qa/evidence/phase0-recovery.json`.
- `npm run build` — PASS, 22 modules; the two existing Noto font URLs remain runtime-resolved warnings.
- `php artisan view:cache` — PASS.
- Full Pint: Phase 1 application/routes/migrations and Luna QA files pass. The only accepted findings are seven already-applied legacy migrations (150–154, 160–161), deliberately not rewritten solely for formatting.

## D. Remaining work

### P0

None.

### P1

None.

### Deferred optional / explicitly out of Phase 1

- External bank APIs, automatic statement feeds, complex multicurrency, payroll payments and an advanced bank-reconciliation ingestion engine were explicitly excluded.
- M05 physical supplier returns remain behind the existing V2 availability boundary. No return was fabricated. When that owner enables physical return, it must consume the posted/approved M12 supplier-credit evidence required by ACC-12 before stock can move.
- M07 Projects, M08 Field Maintenance and M15 Attendance remain mandatory later phases and were not started here.

## E. Final decision

`PHASE 1 COMPLETE — READY FOR M07 PROJECTS`
