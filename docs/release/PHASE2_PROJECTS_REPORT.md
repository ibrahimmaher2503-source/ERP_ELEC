# Phase 2 Projects report

Local implementation and isolated PostgreSQL acceptance, 2026-10-28/29 (Africa/Cairo). This report concerns M07 Projects. The development company remains inactive financially; no actual company balances or business transactions were supplied.

## Implementation

M07 keeps its existing `Projects` identity, master records, migrations 147–149, permissions, history and routes. `release.projects_enabled` now exposes Projects independently of the global V2 boundary. DeliveryReturns retains its historical identity only.

### Closed implementation gaps

| Entry gap | Implementation |
| --- | --- |
| Manager assignment implicitly granted sensitive operations | Manager/member assignment retains scoped project viewing. Management, activation, approval, financial visibility, documents, issue, close and reopen use explicit current M01 permissions inside Actions. |
| Budget submitter could approve its own proposal | Independent `budget_approve` authorization, submitter/approver inequality, locked versions, immutable approved baselines and a PostgreSQL decision guard. Revisions preserve earlier snapshots and can include approved schedule/scope/category/phase changes. EGP only. |
| Lifecycle ended before financial closure | Versioned, reasoned draft/planned/active/in-progress/hold/completed/closed transitions, eligible cancellation and authorized reopening; actual start/end dates and append-only transition evidence. |
| Tasks lacked blocked state, partial progress, dependencies and milestones | Block reasons, priorities, percentage progress, dependency/cycle checks, assignments, append-only comments, milestones with acceptance/waiver evidence and immutable handover records. |
| Materials were planning-only | Genuine M06 availability, demand-capped reservation, backorder allocation, release and authorized project issue; actual FIFO depletion and M11 expense posting in one transaction. |
| Source links had no financial projection | Owner APIs return deduplicated PO commitments/receipts, M12 invoices/open items/settlements/credits, M06 FIFO and M11 posted project dimensions. Purchase invoice, stock consumption and payment are not counted as three expenses. |
| Historical financial attribution could be guessed | Invoice project identity freezes at creation. Journal project dimension freezes while draft, before posting. Old posted entries are not changed; missing historical attribution remains unallocated and blocks unsupported margin/closure claims. |
| Financial exports bypassed the page's supported-margin guard | PDF/XLSX now use the same nullable recognized margin as the page; incomplete historical allocation or FIFO reconciliation produces an explicit unavailable value. |
| Documents lacked separate access/approval controls | Explicit view/download/manage/approval permissions, independent approval, private local files, revision history and SHA-256 integrity verification. Ordinary attachments receive local references, not official financial numbers. |
| Closure checklist contained unavailable producers | Live owner-source checks block unresolved reservations/backorders, open PO commitments, unposted invoices/credits/journals, uncleared GRNI, missing attribution and FIFO/GL differences. Required work, documents and handover must be evidenced. |
| Livewire availability action shared its name with public state | Renamed the real action to `checkAvailability`; authenticated browser response and owner availability values verified. |
| Completing a project raised a Livewire morph exception | Correlated error to `changeLifecycle(completed)` on Project Details. The status-dependent action container and buttons now have explicit keys. Mobile create/edit/work/handover/complete/close rerun `6E19F022A1` passes without browser errors; its PostgreSQL project is closed, version 15. |
| XLSX download response changed requested private caching to public | Both Projects export controllers explicitly mark the finished BinaryFileResponse private; PDF/XLSX signatures, private/no-store headers and 403 denials verified. |
| Material issue button did not reflect all owner execution permissions | Per-reservation visibility now requires current M07, M06 warehouse issue and M11 posting authority; Actions continue to enforce the same permissions. |
| Database session inherited the cluster timezone | PostgreSQL connections explicitly use UTC. A fixed Carbon instant persists with the exact expected Unix epoch; Projects timestamps display Cairo time. No historical timestamp was rewritten. |

M07 does not own a second stock, financial, AP/AR or Treasury ledger. Closing a project does not settle M12 open items. Outstanding payables/receivables are disclosed and remain collectible/payable through their real owners. Closed project records are read-only until an explicit authorized reopening.

### Forward migrations

- `2026_10_28_178000_complete_project_operations.php`: lifecycle/master extensions; task metadata/dependencies/comments; milestones; handovers; material source links/intents; immutable Accounting project dimensions; frozen M12 project identity; permission synchronization; deferred source/FIFO/GL constraints.
- `2026_10_28_179000_harden_project_acceptance_guards.php`: independent budget decisions, EGP baselines, dependency ownership, reasoned versioned lifecycle evidence and closed-record guards.
- `2026_10_28_180000_reconcile_project_stock_sources.php`: project issue/reservation-event matching and deferred reservation-to-owner-demand reconciliation.
- `2026_10_29_181000_link_project_task_documents.php`: optional task attachments on the existing private document/revision model; same-project task FK/guard and immutable document identity.

Existing historical migrations were not rewritten. During isolated construction, migration 178's new function declarations were made replaceable so Laravel's fresh-table test setup can run repeatedly; historical Phase 0/1 migration definitions remain intact.

### Files and public contracts

- M07 application: `ProjectAccess`, `ProjectActions`, `ProjectPlanning`, `ProjectSources`, `ProjectDocuments`; new `ProjectExecution`, `ProjectMaterials`, `ProjectFinancials`, `ProjectsService` and `Application/Contracts/ProjectsApi`.
- M06: existing `InventoryApi`/`InventoryService`, reservations, ledger and financial valuation path now accept validated Projects demand and the distinct `project_issue` source. The existing Sales source path remains separate.
- M11: `AccountingApi`/`AccountingService` publish project totals; `JournalPosting` freezes source dimensions and carries them into eligible reversals. `AccountingActions::journal()` supports explicit draft project attribution for legitimate additional costs. Posted lines remain immutable.
- M12: supplier/customer invoice creation freezes the project from real PO/Sales source links; source journals and credit notes retain that project. The owner publishes invoice, credit and settlement projections.
- M05: `PurchasingApi::projectSummary()` publishes issued, outstanding and actually received procurement values, deduplicating PO/receipt links.
- M04: delivery evidence includes the actual customer identity for project/customer revalidation. Existing Sales approval, reservation, delivery and COGS ownership remains unchanged.
- System audit receives real Projects activity through `RecordAudit`; original M07 append-only activity is preserved.
- Presentation: extended existing Project master/tasks/budget/documents/operations/closure pages; new financial page and authorized XLSX/PDF export controller; existing shell/sidebar/routes reused. Five timestamp displays explicitly use Africa/Cairo; `config/database.php` fixes the PostgreSQL session to UTC.
- Data: `ProjectMilestone`/`ProjectMilestoneFactory`; existing `ProjectsDemoSeeder` adds only pending, identifiable synthetic milestones when explicitly invoked locally/testing. No approval or posting history is seeded.

### UI destinations

`projects`, `projects/dashboard`, `projects/create`, `projects/{id}`, `projects/{id}/tasks`, `projects/{id}/budget`, `projects/{id}/operations`, `projects/{id}/documents`, `projects/{id}/financial`, `projects/{id}/closure`, `projects/reports`, `projects/export` and `projects/{id}/financial/{xlsx|pdf}`.

`php artisan route:list --path=projects --except-vendor` confirms 14 registered routes, including the private revision download. Exact output: `docs/qa/evidence/phase2-project-routes.txt`.

Phases/tasks/milestones/comments are together on the task page; budget history/revisions are together on the budget page; Sales/Purchasing links and Inventory execution share operations. Arabic RTL, the existing white sidebar, compact light surfaces, Flux controls and royal-blue actions are preserved. The interface skill guided use of existing components; PostgreSQL skill guidance informed indexes, constraints and lock ordering.

## Acceptance and exact evidence

### New M07 evidence

| Command | Current result |
| --- | --- |
| `php -d opcache.enable_cli=0 tests/Support/check-phase2-projects.php` | PASS: `RESULT all_pass=true assertions=36`, transaction rolled back on 5443. Master persistence/versions/branch/duplicates, independent budget revision/reapproval, unchanged old snapshot, hold/resume, blocked tasks/progress/dependencies, actual start date, exact UTC timestamp, legitimate closure/read-only/reopening and append-only history. Output: `phase2-projects-focused.txt`. |
| `php -d opcache.enable_cli=0 -d memory_limit=768M tests/Support/check-phase2-projects-financial.php` | PASS: `all_pass: true` on disposable 55439 through migration 181 with UTC sessions; real source graph, independent budget, source attribution, material FIFO/GL, invoice settlement, handover, blocked premature/GRNI closure and final close. Transaction rolled back. Latest evidence: `docs/qa/evidence/phase2-projects-financial.json`; earlier stdout retained in `phase2-check-phase2-projects-financial.php.txt`. |
| `php -d opcache.enable_cli=0 tests/Support/check-phase2-project-concurrency.php` on guarded 55437 | PASS after migration 181; two distinct PostgreSQL workers per race, budget one-winner, demand cap, source linking, one close/version increment and injected rollback. Evidence: `docs/qa/evidence/phase2-project-concurrency.json`. |
| New M07 PostgreSQL/private-file backup and restore | PASS inside concurrency campaign: migrations 178–181, same-project task/document FK association, source project, private file size and SHA-256 preserved in a restored disposable database. |
| `$env:PHASE2_BROWSER_DB_PORT='55440'; node tests/E2E/phase2-projects-browser.cjs` | PASS fresh full source-driven run `EB5C765344`: 16 checkpoints, zero browser errors; all 28 recorded Livewire POST responses are HTTP 200. Evidence: `phase2-projects-browser/EB5C765344/result.json`; fresh committed owner-Action fixture: `phase2-projects-browser/finance-55440-EB5C765344.json`. |
| `$env:PHASE2_BROWSER_DB_PORT='55440'; $env:PHASE2_E2E_DIAG_CLOSE='1'; node tests/E2E/phase2-projects-browser.cjs` | PASS final run `EE10CFDF91` on 390px: actual UI create/save/reopen/edit, phases/tasks/milestones, independent budget approval, handover, completion and closure. PostgreSQL project 4 is closed/version 15; task/milestone complete, handover present, baseline EGP 1,234.50 independently approved. Zero browser errors. Main source-driven project 3 stays closed/version 17. |

The financial project fixture uses actual PO receipts, supplier invoices and payments, approved Sales/delivery/customer invoices and collections, credit notes and a separately authorized project material issue. The initial accepted project projection has budget 100.00 EGP, recognized revenue 46.25, recognized cost 52.25, material cost 50.00 and FIFO/GL difference 0.00. A supplementary matched supplier invoice clears the remaining project GRNI before closure. AP/AR are never zeroed by project closure.

The final rollback financial check also posts a genuine delivery/invoice before its Sales source is linked to a new project. Evidence `historical_margin_negative` proves invoice project identity remains NULL, unallocated invoice count is 1, FIFO/GL difference is 10.00 and recognized margin is NULL. The original posted journal and its three lines remain unchanged. Actual XLSX `Data!B7` contains `غير متاح؛ راجع التخصيص والمصالحة`, not an unsupported numeric margin. The complete synthetic fixture accumulates PDF generation and XLSX in one CLI process; its first default-128M attempt exhausted memory, and the explicit 768M verification passed. Ordinary web export requests are verified separately by the browser campaign.

All new test/check code is Luna-authored. Failure attempts remain distinct from passing evidence. A browser fixture correctly refused to replace an already activated disposable clone; the refusal is retained as `phase2-projects-financial-activation-refused.json`. A new clean disposable database was used for its Action-created synthetic graph.

The final fresh browser run `EB5C765344` creates project 3 through the UI and completes its entire journey on a new UTF-8 PostgreSQL cluster. SQL confirms closed/version 17, baseline EGP 1,234.50 approved independently by user 2, completed task 2/milestone 3, handover, and approved document attached to task 2. Reservation 3 → project issue 2 → movement 9 has one actual FIFO allocation. The linked source projection shows recognized cost 20.00, revenue 12.00, received/cleared GRNI 10.00 and FIFO/GL difference 0.00. Actual invoice AP 11.40 and AR 13.68 remain visible and unchanged by project closure. Private financial exports and the approved document return HTTP 200 with valid signatures; the denied actor receives HTTP 403. All 16 checkpoints pass with zero browser errors.

Final mobile run `EE10CFDF91` performs new persisted business actions, rather than counting screenshots as functional acceptance. It creates/edits a separate project at 390px, completes planning/work/handover/closure, and verifies PostgreSQL state. The budget reviewer uses a separate authenticated session. The original full-journey project and its external posted sources remain intact. Earlier mobile post-fix run `6E19F022A1` is also retained.

### Business acceptance

| Area | Final result |
| --- | --- |
| Project master and lifecycle | PASS: create/save/reopen/edit, scoped transitions, hold/resume, actual dates, stale-version/duplicate denials and append-only history. |
| Phases/tasks/milestones | PASS: assigned work, exact progress, dependencies, blocked reasons, acceptance/waiver evidence and persisted browser execution. |
| Budget/revisions | PASS: independent approval, concurrent one-winner decision, immutable old baseline and revision/reapproval. |
| Sales/Purchasing links | PASS: real eligible sources, identity checks, deduplicated commitments/receipts/invoices and frozen attribution. |
| Materials/FIFO/GL | PASS: owner availability, demand-capped reservation, real issue, exact FIFO cost, balanced GL and zero reconciliation difference. |
| Financial projection | PASS: budget/commitment/cost/revenue/cash separation; invoice settlement/credit values from M12; historical attribution remains unavailable and unchanged. |
| Documents/exports | PASS: private task attachments, independent approval, hash/signature checks, authorized downloads and 403 denials; unavailable margin preserved in XLSX. |
| Completion/closure | PASS: missing work/evidence/GRNI/owner reconciliation blocks closure; accepted desktop and mobile projects close without rewriting external transactions or settling open items. |
| Concurrency/failure/recovery | PASS: independent PostgreSQL workers, version/cap/uniqueness guards, atomic injected rollback and database/private-file restore through 181. |

Earlier browser attempts remain intact: `4A4724BB84` created/edited project 7 and approved its budget, then failed availability and recorded a console error; `54EDEFFA5A` persisted stock/source links, then failed XLSX caching; `822A25CF17` completed closure, then failed readback serialization. Read-only runs `07854D3468`/`5F5D320DC2` subsequently verified that saved state. Fresh attempts exposed harness variable shadowing, missing request-completion waits and isolated Windows database encoding setup; their failed receipts/data directories were preserved. `AB76D8B77B` completed the money flow but correctly remained FAIL because of the reproduced lifecycle rendering exception. Mobile repro `4E4075CA76` identified `changeLifecycle(completed)`; post-fix `6E19F022A1` passes. Earlier FAIL receipts were never relabeled as PASS or substituted for the final fresh journey.

### Phase 0/1 regression campaign

| Command | Result |
| --- | --- |
| `php artisan test --env=testing` | PASS: `41 passed (106 assertions)`, 11.07s on disposable 55439, schema 181 and UTC connection; `docs/qa/evidence/phase2-laravel-final-schema181.txt`. Includes unchanged runtime identity/security assertions and the legitimate expanded exact M07 permission list. |
| `php .tools/run-safe-qa-checks.php` | PASS on final UTC configuration: `21/21 scripts passed; 0 failed; 0 incomplete; 873 explicit PASS assertions`; Sales Orders 155 assertions. Evidence: `safe-check-run-20261029T135709Z.json` and `phase2-aggregate-regression.txt`. The earlier `safe-check-run-20261028T205045Z.json` remains preserved. |
| `check-m05-m06-roundtrip.php` / `check-m06-inventory.php` | PASS; full Purchasing/Inventory round trip and focused Inventory security/ledger/reservation/transfer/count/adjustment checks. Exact outputs are retained under `docs/qa/evidence/phase2-check-*.txt`. |
| `check-phase0-valued-inventory.php` | PASS, `all_pass: true`; valued receipts, transfers, counts, adjustments, FIFO/GL, official PDF and rollback checkpoints. |
| `check-phase0-direct-sales.php` | PASS on forward-migrated isolated 55433; compliant/exception approval, source identity, reservation, immutable decision and permission negatives. |
| `check-phase0-financial-core.php` | PASS on 55433; manual journal/PDF/denials and sparse workbook preview; unchanged activation/opening state. |
| `check-phase0-concurrency.php` | PASS on 55433; retry uniqueness, simultaneous valid receipts, remaining-quantity cap and distinct official numbering. |
| Phase 1 supplier/invoice/journey checks | PASS; exact financial amounts, AP/AR, posted sources, credit notes, tax, rollback and all reconciliations retained in `phase2-check-phase1-*.txt`. |
| `check-phase1-financial-concurrency.php` | PASS, `all_pass: true`; same-key posting/payment, official numbering, matching, competing allocations, customer invoicing, credit cap, customer advance, injected rollback and close-versus-post. Fresh source fixtures configure the optional advance liability through the approved Accounting Actions. |
| `check-phase1-journal-reversal.php` | PASS, `all_pass: true`; exact inverse lines, one retry identity, unchanged original/PDF and source-owner/reversal-of-reversal denials. Its synthetic period setup reopens through the authorized Action inside a rolled-back transaction. |
| `node tests/E2E/phase1-financial-browser.cjs` | PASS run `5484210850`; authenticated desktop/mobile financial pages and authorized/private PDF/XLSX integrity with restricted-access denials. |
| `node tests/E2E/phase0-financial-browser.cjs` | PASS run `CD6C653810E7`: real Sales/Purchasing/Inventory desktop/mobile RTL workflow, immutable/private documents, partial/final transfer receipt, count, adjustment, backorder/delivery and final stock/FIFO/GL reconciliation. |
| `npm run build` | PASS: 22 modules, 22.02s. Existing Noto runtime URL warnings remain. |
| `php artisan view:cache` | PASS. |
| `php vendor/bin/pint --test --format=json app config database/factories database/seeders routes tests/Support tests/E2E/support` | PASS: `{"tool":"pint","result":"passed"}`; `phase2-pint-scoped.json`. New migrations 178–181 also pass the explicit scoped check (`phase2-pint-migrations.json`). |
| `php vendor/bin/pint --test --format=json` | FAIL only for seven already-applied legacy migrations 150–154, 160–161; exact paths/fixers in `phase2-pint-full.json`. Accepted formatting debt; no new-code violation remains. |

Prior canonical Phase 0/1 financial/concurrency evidence was copied into `docs/qa/evidence/phase2-baseline/` before rerunning writers, preserving the earlier receipts.

Rerun setup failures were retained and corrected: the first final Laravel invocation omitted the disposable cluster's `postgres` test role (`phase2-laravel-missing-disposable-role.txt`); its corrected invocation passed above. A financial rerun against an already activated test fixture refused safely (`phase2-projects-financial-activation-refused-rerun.json`); the same check then passed on the separate disposable 55439 database. No business guard was weakened.

## Operational data preservation

Development forward migrations 172–181 were applied without activating financial posting. Before/after readback: project IDs 1/2/3 (`DEMO-EG-PRJ-001/002/003`) remain draft, version 1; `activated_at` and `go_live_date` remain NULL; stock movements, posted GL, supplier/customer invoices and Treasury movements remain zero. Administrator permission additions use the existing synchronization Action. All acceptance financial/stock effects are synthetic and isolated from development.

Final read-only development evidence is `docs/qa/evidence/phase2-development-preservation.txt`. No development/operational reset, forced checkout, remote publish, production activation or M08/M15 implementation was performed. Laravel's standard RefreshDatabase was used only on isolated test databases; the earlier shared-test fixture was preserved in a private `.tmp` dump before its test-only refresh.

## Revalidation — 2026-10-30

The repeated Phase 2 instruction was checked against the existing implementation and preserved acceptance receipts. No replacement Projects implementation or additional business change was needed. Current source retains the M07 identity, owner API boundaries, independent budget approval, private documents, actual FIFO/GL integration and guarded lifecycle/closure.

- `php -d opcache.enable_cli=0 tests/Support/check-phase2-projects.php`: fresh PASS, `RESULT all_pass=true assertions=36`; isolated `erp_elec_new_test` on PostgreSQL port 5443, outer transaction rolled back. The initial invocation encountered a stopped local PostgreSQL server before any fixture writes. The existing cluster was restarted without initialization or data replacement; the subsequent invocation passed.
- `php -d opcache.enable_cli=0 vendor/bin/pint --test --format=json app/Modules/Projects resources/views/projects tests/Support/check-phase2-projects.php`: fresh PASS, `{"tool":"pint","result":"passed"}`.
- `php -d opcache.enable_cli=0 artisan route:list --path=projects --except-vendor`: all 14 existing Projects routes remain registered.
- Re-read the preserved full desktop browser receipt `EB5C765344`, functional mobile receipt `EE10CFDF91`, financial source/reconciliation receipt and concurrent-worker/recovery receipt: both browser results are `PASS`; financial and concurrency receipts have `all_pass: true`. The full regression results above remain the prior completed campaign; they were not represented as newly executed during this revalidation.

Existing uncommitted work, historical migrations and operational records were preserved. M08 and M15 were not started.

## Remaining gaps

- P0: none.
- P1: none. Mandatory functional, financial, concurrency, authorization, recovery and regression acceptance passed.
- Allowed unavailable projection: future profitability/cost-to-complete/WIP forecasting has no approved estimate producer. It remains explicitly unavailable; posted recognized revenue, cost, margin and cash/subledger values are available from real sources.
- Existing M06 does not publish a project-specific physical material-return correction contract. The UI identifies this boundary; no manual receipt or false stock return is exposed. Source-linked returns remain available only where the owning source supports them.
- Accepted legacy formatting debt: already-applied migrations 150–154 and 160–161.

## Final decision

`PHASE 2 COMPLETE — READY FOR M08 FIELD MAINTENANCE`

This accepts local M07 delivery. It does not activate the company or authorize production financial inputs. M08 and M15 remain mandatory subsequent phases and were not implemented here.
