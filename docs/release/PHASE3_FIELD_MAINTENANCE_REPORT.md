# Phase 3 — Field Maintenance

Local implementation and acceptance, 2026-10-30. M08 is **FieldMaintenance**, not Human Resources. Requests through field execution, source-backed costing, service invoicing and guarded closure are implemented and accepted. M15 Attendance remains the next required phase.

## A. Implementation

The existing modular Laravel/Livewire/Flux application and Arabic RTL shell are retained. No organization, employee, customer, stock, accounting or invoice subsystem was duplicated. Existing dirty and untracked Phase 0–2 work was preserved; nothing was published or activated in production.

- `app/Modules/FieldMaintenance/Application`: request and work-order Actions, branch access and assigned-technician visibility, assignments/rescheduling, visits, configurable checklist snapshots/results, actual labor, independent cost proposals/approvals, parts coordination, costs/billing and private evidence.
- `app/Modules/FieldMaintenance/Application/Contracts/FieldMaintenanceApi.php`: owner contracts for Inventory demand/issue authorization, technical reconciliation, service billing evidence and project source projections.
- `app/Modules/FieldMaintenance/Domain`: scoped request/work-order/template/planned-visit models and Laravel factories. `FieldMaintenanceDemoSeeder` is explicit, local/testing only and repeat safe; EgyptMarketDemoSeeder includes it. Fixtures remain identifiable DEMO-EG drafts/configuration/planned records, with no actual labor, rates, postings or privileges invented.
- Forward migrations 182–187: maintenance business records, source relationships, NUMERIC amounts/quantities, immutable execution/history, unique replay identities, source-linked stock/FIFO/GL integrity, service invoice sources, cost proposals/recognition and project task/milestone links. Migration 187 repairs the request/work-order identity trigger through a separate forward change.
- `MaintenancePage`, `MaintenanceRequestPage`, `MaintenanceWorkOrderPage` and `MaintenanceDownloadController`, with `resources/views/maintenance`: dashboard, requests/create/edit/details, work orders, workload/assignments, visit schedule/details/checklists, parts, time, costs, verification/closure, billing, reports and private files through pages and tabs.
- Dynamic `M08.*` permissions are registered in Organization. Ordinary roles remain default deny. Explicit Administrator membership receives all registered permissions through `SyncAdministratorPermissions`; account, branch, warehouse and source guards remain mandatory.

Owner integrations:

- M01 supplies existing scoped, active/verified user identities. M02 supplies catalog identities and units. M03 validates customer/site/contact ownership. M07 validates project/customer/branch and optional task/milestone links; open linked maintenance blocks project closure.
- M06 reservations accept a real maintenance source; requesting a part changes no on-hand stock. Authorized fulfillment uses `maintenance_issue`, existing FIFO allocation and mandatory M11 posting. Unused returns use the exact original issue/allocation cost and FIFO date, with quantity caps and owner correction authorization. Maintenance never writes stock balances or FIFO layers.
- M11 retains journals and project dimensions. Labor may be recognized only from genuine approved rate/time sources using explicitly configured optional expense/accrual mappings. Other expenses reference a unique approved posted manual expense source, excluding protected control accounts and prior material/labor sources.
- `AccountingApi::inventorySourceProject` supplies the frozen original Inventory journal attribution for source-linked returns; Inventory no longer queries Accounting tables. Technical contracts and the local demo policy are documented in `docs/m08-field-maintenance.md`.
- M12 owns service invoices, AR, credits and settlement projections. A source is a verified billable service with an independently submitted/approved price and owner-calculated tax; no goods-delivery placeholder is created. Posting revalidates service evidence under M12 posting authority without granting M08 request authority. Closure never settles AR or requires payment.
- M17 supplies official MR/WO sequences, immutable private service-report PDFs and audit. Uploaded evidence stays on the private disk and is authorized and hash checked on download.

## B. Business acceptance

The Luna-authored focused workflow check passes 26 assertions. The final financial check passes 35 assertions for actual FIFO issue, exact-source return, replay, wrong-warehouse denial, injected rollback after stock/GL writes, immutable sources, project dimensions, approved/unrated labor, approved manual expense attribution and genuine M12 service invoice/AR/GL. It also proves that cancellation is blocked while a reservation is outstanding, allowed after its authorized release, preserves on-hand stock and rejects a stale retry. A separate 10-assertion edge check accepts reason-only cancellation, replacement visits with frozen checklists, cancellation/reopen history, conflict overrides and partial costing. These exercise functioning Actions rather than UI prototypes.

In the accepted synthetic financial fixture, net material FIFO cost is `10.00`, approved labor is `22.50`, project recognized cost is `32.50`, and the FIFO/GL difference is `0.00`. Linking an independently posted manual expense of `10.00` creates no new journal and increases recognized cost once to `42.50`. The service invoice has net `100.00`, configured synthetic tax `14.00`, and balanced gross AR/GL `114.00`. These are isolated acceptance values, never company activation inputs or development balances.

## C. Verification

| Check | Current evidence |
| --- | --- |
| Laravel suite | PASS, final post-fix 41 tests / 106 assertions on ephemeral PostgreSQL 55446 at schema 187, 33.91 seconds. `docs/qa/evidence/phase3-final-laravel-suite.txt`. The source 55443 clone was preserved before RefreshDatabase; the ephemeral server was stopped afterward. The earlier receipt is retained separately. |
| M08 focused Action/factory workflow | PASS, fresh 26 assertions including factory persistence, repeated seeding, version/permission guards, visits/checklists/labor, verification, closure and immutable history. `docs/qa/evidence/phase3-final-maintenance-focused.txt`. |
| M08 financial/project acceptance | PASS, fresh 35 assertions on disposable PostgreSQL 55441, migrations through 187, transaction rolled back. Includes exact-source return project attribution through AccountingApi and cancellation after reservation release. `docs/qa/evidence/phase3-final-maintenance-financial-cancellation.json`; original committed race fixture receipt preserved separately. |
| Focused runtime/contract regressions | PASS: Livewire stale-transition validation, labor-tab rendering, actual saveAttachment persistence, private/no-store downloads with intact bytes/hash and wrong-branch 403; Accounting frozen attribution, missing-source 409 and wrong-branch 403. `phase3-final-maintenance-livewire-exceptions.txt`, `phase3-final-maintenance-accounting-contract.txt`. Both use isolated PostgreSQL and roll back. |
| M08 lifecycle/cost edges | PASS, fresh 10 assertions on disposable PostgreSQL 55443, rolled back. `docs/qa/evidence/phase3-final-maintenance-edge-cases.json`. |
| M08 two-worker races | PASS: same-key issue converges on one movement/journal; distinct issue and reservation allocation respect stock caps; competing assignments reject stale versions; invoice replay creates one invoice; competing close/reopen attempts preserve version checks. Included in the financial JSON receipt. |
| Aggregate 21 QA scripts | PASS, fresh 21/21 with 873 assertions on disposable PostgreSQL 55442. `docs/qa/evidence/phase3-final-aggregate.txt`, `safe-check-run-20261030T155651Z.json`. Luna extended real M08 demo-audit coverage and the stale CustomersApi test double without weakening business assertions. Initial timeout/intermediate failures remain recorded. |
| Browser desktop/mobile | PASS, Edge/Playwright run `A87DD11DA8`, authenticated 1440px desktop and 390px mobile on isolated PostgreSQL 55440. Request reopen/resave, checklist, assignment/schedule, actual visit, parts request/reservation/issue/use, labor, cost view, upload/download, verification/PDF/closure and denials all persisted. Zero browser errors or failed Livewire actions. [Sanitized receipt](../qa/evidence/phase3-maintenance-browser/run-A87DD11DA8/result.json) and four screenshots are retained. |
| Vite / Blade / scoped Pint | Fresh build PASS, 22 modules in 1m29s; final Blade compilation and application/config/factory/seeder/route/migrations 182–187 Pint PASS. `phase3-final-build.txt`, `phase3-final-blade.txt`, `phase3-final-pint.json`. All 11 new M08 support checks pass scoped Pint (`phase3-final-pint-checks.json`). Existing Noto runtime URL warnings remain. |
| Prior financial/inventory/project regressions | PASS: M05/M06 roundtrip, full M06, valued stock/GL, Phase 1 journeys/concurrency/reversal and M07 focused 36 assertions. Fresh supplier-invoice and M07 financial checks pass on clean 55443 after safely refusing already activated fixtures. `docs/qa/evidence/phase3-standalone-regression.txt`. Historical receipts remain preserved. |
| Backup/restore and private PDF integrity | PASS: actual current 55441 dump restored to independent 55444; 45 owner/ledger table fingerprints and 212 private official PDF hashes match. Source readback is unchanged. `docs/qa/evidence/phase3-backup-restore-regression.txt` and paired JSON receipts. |
| Development preservation | PASS, read only: project IDs 1–3 remain draft/version 1; Go-Live/activation NULL; stock/posted GL/invoices/Treasury and M08 labor/issues/completions remain zero. Only M08 demo drafts/configuration and one unstarted planned visit exist. `docs/qa/evidence/phase3-development-preservation.json`. |
| Development runtime database privileges | PASS, metadata only: the three costing/proposal/recognition tables have normal runtime SELECT/INSERT and their owned sequences have USAGE. `phase3-dev-maintenance-expense-privileges.json`. No development grants or data were changed. The browser clone's separate migration-owner grant gap was corrected only in isolated acceptance setup. |

All new test/check code is Luna authored and lead reviewed. Test databases are separate PostgreSQL instances; there is no SQLite substitute. Financial acceptance effects and committed worker fixtures are confined to disposable test databases. Local demo seeding was invoked twice successfully and creates only synthetic drafts/configuration/plans. No development Go-Live or financial activation was authorized.

Browser readback proves request `82` resaved to version `2` before submission,
work order `86` (`WO-2026-000037`) closed at version `13`, visit `55` recorded with
customer acknowledgment, a completed checklist and 15 minutes of actual unrated
labor (monetary cost remains NULL). The part demand/reservation left on-hand stock
at `7.00000000`; actual M06 issue movement `15` / M11 journal `41` consumed
`1.00000000` at genuine FIFO cost `10.00`, leaving `6.00000000`. The cost screen
showed that source amount. Private attachment `2` returned the exact 86 uploaded
bytes and stored SHA-256; completion `3` returned a private 44,348-byte official
PDF. An unprivileged branch member received 403 for the work order, attachment and
report. Closed desktop/mobile readback has no document overflow.

The browser server uses the local upload runtime against the explicitly guarded
test database, private storage, sessions, array mail/cache and sync queue. The
fixture helper retains its testing database guards. This avoids Livewire's
unit-test-only temporary disk behavior while exercising actual browser uploads.
Its existing posted stock was reused; the isolated current accounting period was
reopened through the authorized M11 Action. No stock balances, FIFO layers or
financial postings were fabricated through fixture SQL. Earlier failed browser
runs may leave clearly synthetic unissued reservations in this disposable clone;
before repeating stock acceptance, release those through owner Actions or use a
new isolated clone, never reset balances directly.

Final browser diagnosis found and corrected four runtime defects: M08 exception-hook
parameter names now match Livewire's named `e` argument, preventing recursive
binding exceptions during validation; labor record collections use `laborEntries`
to avoid shadowing the public `labor` form state; attachment forms call
`saveAttachment`, avoiding Livewire's reserved client `upload` alias; file downloads
explicitly call `setPrivate()` after BinaryFileResponse construction, preserving
private/no-store semantics instead of its public default.
Creation/lifecycle buttons have stable keys. Missing-reason validation, the labor
tab and private uploads/downloads have focused Livewire
regression coverage. Historical failing browser receipts remain under private
`.tmp/phase3-maintenance-browser`; they are not passing acceptance.

## D. Remaining work

- P0: none.
- P1: none. Required operational, financial, concurrency, private-document,
  desktop/mobile and regression acceptance passed.
- Existing accepted formatting debt in seven already-applied legacy migrations
  remains unchanged; current application code, new migrations and M08 checks pass
  scoped Pint. M15 Attendance is the required next phase, outside M08 delivery.

## E. Decision

`PHASE 3 COMPLETE — READY FOR M15 ATTENDANCE`
