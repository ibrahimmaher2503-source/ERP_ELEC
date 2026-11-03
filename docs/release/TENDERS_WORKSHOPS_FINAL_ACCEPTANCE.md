# Tenders and workshops final acceptance

Current decisions:

- **TENDERS INCOMPLETE**.
- **M09 INCOMPLETE**.
- **INCOMPLETE — REMAINING WORK IDENTIFIED**.

These are conservative acceptance decisions while final browser and recovery campaigns remain active. They must not be changed merely because source files exist or backend checks pass. Overall company activation is independently **NOT READY**.

## Evidence by verification level

| Capability | Implemented source | Runtime verified | Browser verified | Production verified |
|---|---|---|---|---|
| M09 standalone paid/internal/warranty repairs | `WorkshopRepairs` and forward 192–195 | Latest focused check 74 PASS; earlier real races 71 and injected rollback 69 PASS | Final full authenticated campaign pending; intermediate genuine PostgreSQL/PDF effects and failures preserved | NOT TESTED |
| Tender `M07.Tenders` | `Projects/Application/Tender*`, forward 196–198, Sales/Accounting contracts | Full Action journey 44 PASS; real creation/submission/award/conversion races and injected cross-owner rollback PASS | Final workflow campaign pending | NOT TESTED |
| Contract-covered repairs / contract award conversion | Disabled, missing M10 producer | Controlled refusal verified | No supported operational contract screen | MISSING |
| Broad ERP activation | Thirteen physical owners, mandatory M10/M15 absent | Two M11 release-blocking negative cases reproduce FAIL | All-owner page audit in progress; workflow coverage must be distinguished | NOT TESTED |

## Current source and runtime receipts

- Baseline audit: `TENDERS_WORKSHOPS_CURRENT_STATE.md` with exact requirement matrix, original dirty state, applied schema 191 and missing Phase 4/5 reports.
- M09 source detail: `M09_WORKSHOPS_IMPLEMENTATION_REPORT.md`, `docs/m09-workshop-repairs.md`.
- Tender source detail: `TENDERS_IMPLEMENTATION_REPORT.md`.
- M09 focused: `evidence/final-20261030/m09-audit-20261031-145402-financial-check-final.txt`, 74 PASS, including actual FIFO, M11 supported labor, M12 invoice/AR, M13 partial/final collections and expired warranty refusal.
- M09 races: `m09-audit-20261031-034230-financial-check.txt`, 71 PASS; rollback: `m09-audit-20261031-034230-financial-rollback.txt`, 69 PASS. Two real workers exercise issue, invoice, QC and delivery effects.
- Tender focused: `tender-actions-check-20261031-155603.txt`, 44 PASS.
- Tender races/rollback: `tender-creation-race-7F0FF02159.txt`, `tender-multirace-D3E561853C.txt`.
- Laravel final rerun after forward migration 198: `final-current-laravel-suite-rerun.txt`, 41 tests / 107 assertions PASS. The initial 36-failure run remains in `final-current-laravel-suite.txt`.
- Production Vite build: `final-current-vite-build.txt`, PASS, actual public manifest/assets emitted. Font build warnings refer to local `/fonts/*`; real asset HTTP checks determine runtime availability.
- Blade checkpoint: `tender-blade-checkpoint.txt`, PASS. Final scoped formatting/template receipts must reflect subsequent small UI fixes.

## Failures and limitations retained

The original workshop draft-input assertion failure was a harness `innerText`/input-value mismatch, with persisted PostgreSQL content intact. Later browser runs found real source issues in catalog access and a Livewire method-name collision; those were fixed. Other failed runs exposed actual posting authorization/closed-period prerequisites, invalid test ordering, uncaught Livewire selector timing, a deliberately dismissed posting confirmation and expected outsider 403 console handling. All failures remain preserved; only a completed final campaign will establish browser acceptance.

The M09 browser's 2027 virtual clock is a guarded test-only accommodation for the cloned company whose FY2026 is closed and FY2027 open. It uses database sessions and private temporary-upload timestamp alignment restricted to that run. It did not reopen real periods or alter production time/business validation.

## Final gates still open

1. One complete current M09 browser receipt, covering four workshop configuration records, intake save/reopen, exact quotation approval/acceptance, real partial parts issue/use/return, labor/QC, delivery, posted M12 invoice, private PDFs/exports and scope denials at the specified viewports.
2. One complete current Tender browser receipt for create/edit/reopen, technical/financial worksheets, independent approvals, official PDF, actual evidenced submission, award and supported handoff, including permission/private-document checks.
3. Final-schema database plus private-file backup/restore comparison and official PDF hashes on a new isolated PostgreSQL target.
4. Final aggregate regression, scoped Pint/Blade and development-preservation/migration receipt.
5. Final all-owner UI defects, remaining P0/P1 tasks and updated release/handoff documentation.

No real workshop names, production labor rates, margins, balances, tender submissions or coverage entitlements were invented. Final reports must retain the distinction between local capability acceptance and real production activation.
