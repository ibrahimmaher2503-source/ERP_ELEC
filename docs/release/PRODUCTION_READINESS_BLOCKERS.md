# Production readiness finding register

Audit: 2026-11-01, Africa/Cairo. Mode: AUDIT ONLY. Findings apply to the current working candidate, not just HEAD. No remediation was performed. Evidence base: `docs/release/evidence/production-readiness-20261101/` (abbreviated **E/** below).

Every finding below supplies category, severity, status, exact evidence/reference, reproduction, business impact, recommended remediation, blocking decision and closure acceptance. `BLOCKED`/`NOT TESTED` means evidence missing; it does not assert a vulnerability or an existing server fault. P0 = unsafe financial behavior or absent mandatory core capability; P1 = material release requirement; P2 = bounded defect/debt; P3 = documentation/style debt. Conditional scope limitations block the full requested scope; they are not new approved release exclusions.

## PR-FIN-001 — Company period close can skip reconciliation

- Category / severity / status: SOFTWARE — financial authorization; **P0; VERIFIED FAIL (current runtime and source)**. `E/financial-release-risks.json`: report_authority=false, close_outcome=closed; rollback verified.
- Evidence/reference: `app/Modules/Accounting/Application/FinancialReports.php:96–111`, specifically report branches at105 and an unchecked empty loop; `AccountingAccess::branches`; **E/financial-source-references.txt**. Prior FIN-P0-01 was read only as a reproduction lead.
- Reproduction: on isolated PostgreSQL create an active verified actor with company `M11.period_open`/`M11.period_close`, no report branches; create an empty future period through `AccountingActions::period`; call `closePeriod`; inspect status then roll back. Partial branch visibility has the same completeness problem.
- Impact: a company-wide period can be closed without examining every branch/control reconciliation; posted balances may be certified without authority/evidence.
- Remediation: independently authorize the complete-company close scope, reconcile the complete company branch set, fail closed when prerequisites/authority are absent, serialize with posting, retain immutable closure evidence.
- Blocking: **YES**, all financial activation.
- Acceptance: zero/partial-report-scope attempts fail without state/audit leakage; an authorized full-company reconciled close succeeds once; mismatch in any branch and concurrent posting/close are handled atomically with correct lock order.

## PR-FIN-002 — Historical reconciliation mixes date bases

- Category / severity / status: SOFTWARE — accounting correctness; **P0; VERIFIED FAIL (current runtime and source)**. `E/financial-release-risks.json`: historical GL0.00 versus FIFO10.00 at2000-12-31 with zero pre-cutoff journals; rollback verified.
- Evidence/reference: `FinancialReports.php:54–79` dates GL and Treasury but calls undated `ReceivablesPayablesApi::reconciliationValues`, `PurchasingApi::grniValue`, `InventoryApi::financialValue`; exact interface lines in **E/financial-source-references.txt**. M12 returns current open-item/credit/advance/clearing values (`ReceivablesPayablesService.php:173`).
- Reproduction: post a genuine receipt/opening and subsequent financial activity through owner Actions in an isolated company; request reconciliation at a date before that activity; compare GL at cutoff with the current FIFO/subledger source. Do not insert fake ledger values.
- Impact: historic statements/period close can show false differences or certify an incorrect match; later receipts, issues or settlements change an earlier date's supposed reconciliation.
- Remediation: owner-controlled as-of projections for FIFO, AP/AR, credits/advances, receipts and GRNI clearing; use the same cutoff and held close lock for every source.
- Blocking: **YES**, financial reporting/close and Go-Live.
- Acceptance: before/after receipts, issues, invoices, credits, partial settlements and returns all reconcile at each historical cutoff; later activity does not change earlier totals; full-company close uses those same values.

## PR-FIN-003 — Optional customer-advance control omitted from manual-journal protection

- Category / severity / status: SOFTWARE — source integrity; **P0; VERIFIED FAIL (current runtime and source)**. `E/financial-release-risks.json`: manual source-control posting succeeded with independent approval; advance GL126.00/source1.00/difference125.00, source row count1→1; rollback verified.
- Evidence/reference: `JournalPosting.php:25` protects inventory/transit/GRNI/AP/cash/bank/receivable but omits `customer_advance`, which is registered in `FinancialActivation.php:30` and reconciled in `FinancialReports.php:77`. **E/financial-source-references.txt**.
- Reproduction: map a distinct customer-advance liability via `saveOptionalMapping`; create a balanced ordinary manual journal affecting it, independently submit/approve/post using `AccountingActions`; compare GL versus `rp_customer_advances` and roll back. No subledger record should be forged to hide the difference.
- Impact: authorized manual posting can change a customer advance control balance without the real collection/advance source; balance remains double-entry balanced but subledger integrity is lost.
- Remediation: centrally protect every source-owned control purpose, including optional mappings, at posting and correction boundaries; keep valid non-control journals available.
- Blocking: **YES** for advance-enabled finance; full requested release cannot accept it unqualified.
- Acceptance: ordinary journal and reversal paths cannot originate unsupported customer-advance control changes; genuine Treasury/M12 advance workflows still post/reconcile atomically and legitimate non-control manual journals succeed.

## PR-SCOPE-001 — Mandatory M10 ServiceContracts absent

- Category / severity / status: SOFTWARE — missing module; **P0; VERIFIED FAIL**.
- Evidence/reference: **E/physical-owner-inventory.txt**; no `app/Modules/ServiceContracts`; current `config/modules.php`; M10 requirements in `docs/MODULE_PRDS.md`; M09/Tender contracts explicitly refuse unavailable M10.
- Reproduction: inspect owner/migration/route/contract inventory; attempt the supported contract handoff/coverage boundary in isolated acceptance and observe controlled unavailability.
- Impact: no signed-contract coverage, entitlement, preventive schedule, usage or contract billing; required Tender/M08/M09 contract integration cannot operate.
- Remediation: implement the real owner and approved lifecycle with factories/idempotent safe demo composition; integrate through current contracts.
- Blocking: **YES**, mandatory scope. Controlled refusal is safe behavior, not capability completion.
- Acceptance: signed contract→covered equipment→entitlement→preventive work→field/workshop consumption→correct billing, including expired coverage, duplicate/concurrent usage, scopes and independent approvals.

## PR-SCOPE-002 — Mandatory M15 Attendance absent

- Category / severity / status: SOFTWARE — missing module; **P0; VERIFIED FAIL**.
- Evidence/reference: **E/physical-owner-inventory.txt**; no Attendance owner, shift/punch/timesheet migrations or route family; `docs/03-DOMAIN-MODEL-BOUNDED-CONTEXTS.md` current mandatory-scope clarification and M15 PRD.
- Reproduction: inventory actual owners/routes/schema; no executable shift→punch→locked-timesheet chain exists.
- Impact: required company time, leave/overtime/correction approvals and monthly locked timesheets cannot be used.
- Remediation: implement Attendance with its real employee-source contract, scope/approval rules and idempotent imports; do not replace it with Reporting.
- Blocking: **YES**, mandatory scope.
- Acceptance: employee→shift→day/overnight punches→reviewed exceptions/corrections/leave/overtime→independent approval→locked timesheet; retries/imports and unauthorized salary/time exports fail safely.

## PR-SCOPE-003 — Conditional Payroll/Reporting scope unresolved

- Category / severity / status: SOFTWARE / release scope; **P1; BLOCKED**.
- Evidence/reference: no Payroll/Reporting owners in **E/physical-owner-inventory.txt**; M14/M16 PRD; current owner-local reports and workforce labor are different capabilities.
- Reproduction: compare the requested conditional rows with actual owner/contract/route inventory and absence of a current explicit release exclusion.
- Impact: workforce/employee dependency and executive reporting expectations cannot be promised; a scope assumption could conceal mandatory work.
- Remediation: record an explicit release boundary and dependency treatment; implement/accept any included owner. Retain absence honestly for excluded owners.
- Blocking: **YES for scope sign-off; conditional capability implementation**.
- Acceptance: signed scope maps each M14/M16 requirement to accepted producer or explicit exclusion with no exposed unsupported dependency; M15's employee source is real.

## PR-FUN-001 — Physical trade returns unavailable

- Category / severity / status: SOFTWARE — business lifecycle; **P1; VERIFIED FAIL for requested full scope**.
- Evidence/reference: `routes/web.php:213–216`, `config/release.php:5`, `EnsureV2Available::handle`; Sales/Purchasing return pages are behind false V2 flag. Use actual route/config source, not a past V1 label.
- Reproduction: request `/sales-returns` and `/purchasing/returns` while current release flag is false; routes reject. Financial credit notes do not create physical return/cost reversal logistics.
- Impact: company cannot complete the requested returned-goods lifecycle using these screens; stock, tax and credits require an integrated owner process.
- Remediation: implement/accept atomic physical/commercial return and source-correction flows, or explicitly exclude returns from a separately approved limited scope with an operational process.
- Blocking: **YES for the requested whole-ERP release**; conditional in an explicitly restricted release.
- Acceptance: partial/full customer/supplier returns from real original sources, paid/unpaid invoices, original FIFO/tax snapshots, credits/refunds, duplicate and cumulative limits, GL/stock reconciliation and rollback.

## PR-FUN-002 — Serialized valued stock lifecycle unsupported

- Category / severity / status: SOFTWARE — inventory; **P1; VERIFIED FAIL for serialized financial paths**.
- Evidence/reference: `Inventory/Application/FinancialStock.php:146–150` requires quantity tracking; `InventoryLedger.php:100` refuses serial tracking for valued issues/receipts; **E/inventory-source-references.txt**.
- Reproduction: use a genuine serial-tracked product in the isolated financial opening/purchase-receipt or valued-issue path; current guard refuses it. Existing serial identity/custody services do not bypass this guard.
- Impact: serial-required goods cannot complete end-to-end valued opening/purchase/delivery/service movements; generic catalog support overstates readiness for those goods.
- Remediation: complete owning serial/valuation source integration and exact source corrections, or approve a narrowly restricted quantity-only rollout.
- Blocking: **YES if serialized goods are included**; unresolved against requested full inventory scope.
- Acceptance: unique serial receipt→location/condition→reservation→issue/return/transfer/count with exact FIFO/GL, zero duplicate occupancy and concurrent protection; no manual stock/ledger workaround.

## PR-FUN-003 — Treasury/advance lifecycle incomplete

- Category / severity / status: SOFTWARE — finance operations; **P1; VERIFIED FAIL (capability gap)**.
- Evidence/reference: `Treasury/Application/Contracts/TreasuryApi.php` exposes accounts/payments/collections/transfers/reconciliation; `ReceivablesPayablesService.php:150–186` records customer advances and sums original amounts. No current public advance-application/refund, statement import/match or general cash-reversal operation was found in those owners.
- Reproduction: follow an excess customer collection through current operations; recording exists, subsequent advance consumption/refund is not supplied by that interface. Compare bank reconciliation UI/actions with a real statement-line matching workflow.
- Impact: normal corrections/unapplied money and external bank reconciliation cannot be accepted as complete merely because cash/GL balances tie at a point in time.
- Remediation: define and implement required owner-local correction/advance and statement-matching lifecycle; expose only supported operations and explicit limits.
- Blocking: **YES for full finance operations; any narrower pilot must exclude these deliberately**.
- Acceptance: advance→later invoice application/refund, partial/duplicate/concurrent use, credit refund and source-linked cash correction; real statement matching preserves unmatched differences and does not create balancing plugs.

## PR-INF-001 — Production target and security configuration not established

- Category / severity / status: INFRASTRUCTURE; **P1; BLOCKED**.
- Evidence/reference: `compose.yaml` supplies DB/optional Redis only; `.env.example` is local; no ERP target binding/inventory or tested domain/TLS/web-server deployment. See infrastructure report.
- Reproduction: identify the selected ERP target and request its sanitized configuration/health/security receipt; none established in this audit. This is not proof that the owner has no server.
- Impact: actual public deployment could be incompatible, insecure or unavailable; installation is not assessable from local runtime alone.
- Remediation: bind and inspect a concrete staging/production target, least-privilege roles, document root, HTTPS/proxy/cookies, network, storage and resources.
- Blocking: **YES**, deployment and operational pilot.
- Acceptance: exact artifact starts on the target with validated runtime/extensions/TLS, no debug/source/private-file exposure, scoped login/actions, health, storage/mail/worker/scheduler checks and documented access model.

## PR-DR-001 — Production recovery objectives and complete recovery unproven

- Category / severity / status: INFRASTRUCTURE / disaster recovery; **P1; BLOCKED for production**.
- Evidence/reference: local recovery receipts in backup report; `docs/release/PHASE0_RECOVERY_RUNBOOK.md`; no demonstrated production RPO/RTO, encryption/key-custody, offsite/retention/access policy or production-like restore drill.
- Reproduction: restore a coordinated DB/private-file/key/config backup on a separate target, start compatible app, hash documents and execute/reconcile permitted actions within declared objectives.
- Impact: backup files can exist while the company cannot recover its data/documents or resume within an acceptable outage.
- Remediation: define objectives/ownership, automate monitored encrypted/offsite backups and rehearse full recovery; preserve source/key compatibility and use forward corrections for irreversible migrations.
- Blocking: **YES**, Go-Live and operational pilot.
- Acceptance: independently measured complete restore meets approved RPO/RTO; counts, referential integrity, file/PDF hashes, private downloads and business/financial reconciliations pass; backup failure alert and key recovery are demonstrated.

## PR-CFG-001 — Real company setup and approved openings not ready for activation

- Category / severity / status: CONFIGURATION; **P1; BLOCKED**.
- Evidence/reference: **E/dev-readonly-snapshot.json**: Go-Live NULL, activation false, tax_validated false, zero stock/journals/invoices/Treasury movements. Company input register is in the Go-Live checklist. No actual company-approved opening package was established.
- Reproduction: inspect authorized setup readiness without changing it; request approved nonsecret opening reconciliation/sign-off references.
- Impact: actual stock/cost, balances, taxes, numbering, rates and authority cannot be trusted by copying synthetic data or treating NULL as zero.
- Remediation: supply real legal/branch/warehouse/workshop/employee/master inputs, actual tax/account policy, date, physical counts/FIFO and signed reconciled opening balances; independently approve through owning workflow.
- Blocking: **YES**, company activation.
- Acceptance: company-specific input register complete; stock/FIFO/AR/AP/GRNI/Treasury/GL openings reconcile to signed source cutoffs and independent approval; unsupported balances block; no demo value is adopted implicitly.

## PR-OPS-001 — Process supervision and operational alerts unverified

- Category / severity / status: INFRASTRUCTURE / operations; **P1; BLOCKED**.
- Evidence/reference: `routes/console.php:7`, `System/Application/DataOperations/ProcessOperation.php:13`, local `config/logging.php`, restricted `config/pulse.php` / `config/debugbar.php`; no target supervisor/alert-delivery receipt.
- Reproduction: execute an authorized background import and cleanup on disposable staging; simulate queue/scheduler/backup failure, 5xx, disk pressure and TLS expiry; observe responsible operator notification.
- Impact: imports can remain processing, cleanup can stop and faults can remain invisible. A default worker omitting `data-operations` does not serve its jobs.
- Remediation: configure actual queues, retry/timeout coordination, scheduler, log retention and alerts with named escalation; keep mandatory financial effects synchronous.
- Blocking: **YES**, operations.
- Acceptance: supervised processes restart, jobs and scheduled task complete, safe failures retain retry state, alerts deliver to the named operator, and no dev profiler is public.

## PR-REL-001 — No immutable reviewed release artifact

- Category / severity / status: RELEASE; **P1; BLOCKED**.
- Evidence/reference: **E/git-head.txt**, **E/git-status-before.txt**, **E/git-diff-stat-before.txt**; HEAD `28cca25...` with556 pre-existing dirty/untracked entries. **E/preservation-after.json** and **E/CANDIDATE_DRIFT.md** also show an unattributed concurrent CSS/public build change after browser capture; audited financial/environment hashes match, public manifest/tree do not.
- Reproduction: check out HEAD alone and compare implemented source/migrations/current documents with the working candidate; HEAD cannot represent all local work.
- Impact: deploying an arbitrary commit or directory can omit required owners/forward guards or include local data/secrets; acceptance cannot be pinned reliably.
- Remediation: review and package the exact candidate including contracts/migrations/assets/lockfiles and current acceptance; preserve unrelated work and exclude private fixtures/environment/backup data.
- Blocking: **YES**, release selection/deployment.
- Acceptance: reproducible artifact has source/assets/dependency hashes and accepted migration set; clean install and upgrade/recovery operate on that artifact; no secret/private data included.

## PR-CI-001 — CI does not provision its PostgreSQL prerequisite

- Category / severity / status: RELEASE / automation; **P1; VERIFIED FAIL (source configuration)**.
- Evidence/reference: `.github/workflows/tests.yml`, **E/ci-source.txt**; `composer.json::scripts.setup` copies `.env.example` and runs migrate; template targets loopback5443. No workflow DB service/init/testing-environment step is defined.
- Reproduction: use an otherwise clean hosted runner with the declared workflow; before `composer setup`, inspect for the required database endpoint and `.env.testing`. No remote run is claimed by this audit.
- Impact: clean continuous acceptance/reproducibility lacks a declared database prerequisite; local prior PASS does not establish CI protection.
- Remediation: explicitly provision supported PostgreSQL/database/roles/testing config/extensions; use locked Composer/npm installs and deterministic artifact output.
- Blocking: **YES** for reliable release pipeline or supply an independently accepted equivalent rehearsal.
- Acceptance: fresh runner without local state builds/migrates/tests using isolated PostgreSQL and stores sanitized results; failure prevents promotion; production install works without dev dependencies.

## PR-CAP-001 — Target capacity and realistic workloads unaccepted

- Category / severity / status: PERFORMANCE / infrastructure; **P1; NOT TESTED on target**.
- Evidence/reference: `docs/performance/ERP_PHASE2_FINAL_REPORT.md` explicitly incomplete; current UI report records unresolved sample timing variance; local browser/load evidence in performance report has built-in-server/small-fixture limits.
- Reproduction: benchmark selected target-like staging at 5/20/50/100 active users with realistic document/catalog/history volumes and declared read/write/report/export/upload/job mix.
- Impact: financial correctness may hold at one user while users experience queuing, contention, timeouts or resource exhaustion at normal company load.
- Remediation: define service objectives, workload and dataset, profile bottlenecks, run controlled contention/capacity campaign; make changes only after separate authorization.
- Blocking: **YES** for promised production capacity.
- Acceptance: recorded latency/P95, throughput, error/timeout rate, CPU/memory/disk/DB waits and correctness at required concurrency meet agreed objectives; no extrapolation from synthetic loopback data.

## PR-SEC-001 — Affected Livewire dependency

- Category / severity / status: SECURITY — dependency; **P2; VERIFIED FAIL (advisory scan)**.
- Evidence/reference: **E/composer-audit.json**; installed Livewire4.4.7, [GHSA-mhf7-xp6r-6qrc](https://github.com/livewire/livewire/security/advisories/GHSA-mhf7-xp6r-6qrc), fixed4.4.8. No app `#[Authorize]`/`#[Json]` usage found in inspected source; exploitation NOT demonstrated.
- Reproduction: `php .tools/composer.phar audit --locked --format=json` (exit1).
- Impact: affected runtime dependency is present; the vendor's exploitable pattern is conditional on attribute usage.
- Remediation: update compatibly in an authorized change and retain in-method current-state checks.
- Blocking: **NO independently on currently demonstrated reachability; security acceptance open**.
- Acceptance: fixed compatible lockfile, clean advisory scan and positive/negative action/event/JSON authorization regression, including reordered keys where relevant.

## PR-SEC-002 — Affected Symfony MIME dependency

- Category / severity / status: SECURITY — dependency; **P2; VERIFIED FAIL (advisory scan)**.
- Evidence/reference: **E/composer-audit.json**; installed MIME8.1.7, [CVE-2026-45070](https://symfony.com/cve-2026-45070), fixed8.1.10. Untrusted mail-header parameter-name path not demonstrated.
- Reproduction: same fresh Composer audit; inspect structured MIME parameter-name construction on actual mail flows.
- Impact: header injection is possible if attacker-controlled parameter names reach affected API; local log mail does not establish safe real delivery.
- Remediation: resolve a compatible patched MIME dependency and validate mail metadata boundaries.
- Blocking: **NO independent demonstrated exploit; unresolved security debt**.
- Acceptance: advisory-free fixed lockfile; CR/LF/non-token parameter names rejected and real password-reset/verification delivery works without sensitive logging.

## PR-SEC-003 — Full object/action/role security matrix incomplete

- Category / severity / status: SECURITY — acceptance; **P1; NOT TESTED in full**.
- Evidence/reference: fresh broad auth and browser IDOR subset in **E/**; current source samples in security report. No complete all-owner route/action/export/private-file/revocation matrix exists for this exact candidate/target.
- Reproduction: ordinary role with branchA scope attempts real existing branchB records, sensitive costs, paired transfer warehouses, exports/downloads and stale/revoked/disabled-session actions; compare denied effects, not just HTTP status.
- Impact: untested access paths may leak or mutate business records; this finding does not assert all reviewed paths are vulnerable.
- Remediation: finish the evidence-led matrix using real isolated fixtures and current grants; no middleware disabling or Administrator-only blanket acceptance.
- Blocking: **YES**, whole-system security acceptance.
- Acceptance: every included owner/action/archetype has allowed and denied stateful cases, correct field masking, preserved audit, and zero unauthorized effects; target headers/session/TLS controls independently pass.

## PR-UI-001 — Current whole-interface/state acceptance incomplete

- Category / severity / status: UI / accessibility; **P2; NOT TESTED in full**.
- Evidence/reference: `docs/design/ERP_UI_UNIFICATION_FINAL_REPORT.md` and its route/archetype matrix explicitly incomplete; fresh representative samples in **E/browser/** are bounded.
- Reproduction: expand current-source checks to every included route/variant with desktop/laptop/tablet/mobile, RTL, ordinary roles, keyboard, loading/empty/error/validation/modal and output states.
- Impact: a consistent shell can conceal unreachable controls, dense data, inaccessible fields or state-specific defects outside the sample.
- Remediation: finish the current matrix and triage actual defects without claiming old screenshots as current closure.
- Blocking: **YES for full UI acceptance; individual cosmetic debt may be nonblocking after triage**.
- Acceptance: completed signed route/state/role/viewport matrix, native keyboard/assistive checks and resolved material defects; automated screenshot checks are distinguished from actual visual inspection.

## PR-UI-002 — Executive dashboard analytics absent

- Category / severity / status: UI / product capability; **P2; VERIFIED FAIL against requested analytics direction**.
- Evidence/reference: `resources/views/dashboard.blade.php`, `tests/Feature/FoundationTest.php:36` assert an authorized module workspace without business totals; UI unification report's outstanding authoritative-projection dependency.
- Reproduction: open current `/dashboard`; inspect actual workspace. No real KPI/trend/cash-flow/workshop/tender/project analytic producers/charts are supplied by that workspace.
- Impact: executives receive navigation rather than the requested financial/operational analytic overview.
- Remediation: define scoped authoritative measures/cutoffs/freshness and implement the owning projections before UI charts; never insert invented values.
- Blocking: **CONDITIONAL** on dashboard/reporting release scope; does not by itself invalidate an accurate operational list.
- Acceptance: scoped known-fixture KPIs/trends tie to owner ledgers, dates and definitions; cost/private data masked and unavailable data explicit; desktop/mobile executive view accepted.

## PR-DOC-001 — Contract and acceptance documents contradict current source

- Category / severity / status: DOCUMENTATION; **P3; VERIFIED FAIL**.
- Evidence/reference: `docs/05-MODULE-CONTRACTS-EVENTS.md` claims 17 interfaces/a ModuleServiceProvider and historical signatures not present in this checkout; imported database headers mention SQLite despite current PostgreSQL authority. `TENDERS_WORKSHOPS_FINAL_ACCEPTANCE.md` still says incomplete while newer individual M09/Tender reports say bounded complete.
- Reproduction: compare those exact assertions with actual `app/Modules`, `bootstrap/providers.php`, current interfaces and individual report receipts.
- Impact: maintainers/operators may mistake specification for implemented contract or stale report for current acceptance.
- Remediation: reconcile current implementation index and dated/superseded report pointers while preserving historical evidence; keep unavailable external links labeled context only.
- Blocking: **NO independently**, but close before handover/release instructions are relied upon.
- Acceptance: current source/contract/flag/schema inventory agrees with the selected release and reports explicitly identify historical versus current acceptance.

## PR-STYLE-001 — Source formatting check fails

- Category / severity / status: MAINTAINABILITY; **P3; VERIFIED FAIL**.
- Evidence/reference: **E/pint-source.txt**: `php -d opcache.enable_cli=0 vendor/bin/pint --test app bootstrap config database routes tests`; 27 reported paths, including two generated cache files and seven older applied migrations, plus source/test style findings. Default unbounded scan was stopped because it traversed disposable `.tmp` trees; **E/pint-default-scan-limit.txt**.
- Reproduction: run the bounded source command read-only; no formatter fix was run.
- Impact: formatting gate is not clean; this does not demonstrate a financial/runtime defect.
- Remediation: exclude generated/runtime trees from the intended check and format only authorized source changes; preserve applied-migration semantics.
- Blocking: **NO** for correctness; release's declared style gate remains failed.
- Acceptance: agreed source scope passes Pint --test without modifying applied behavior or including disposable generated output.

## PR-TRN-001 — Real operator/cutover/incident acceptance missing

- Category / severity / status: TRAINING / OPERATIONS; **P1; NOT TESTED**.
- Evidence/reference: no actual company operator UAT/training/cutover/incident sign-off in the audited release documents; current checklist keeps these inputs open.
- Reproduction: ask named company operators to complete their real permitted journeys and approved recovery/escalation/cutover rehearsal with reconciled inputs.
- Impact: company may post incorrectly, misunderstand custody versus owned stock, or fail to recover/escalate even when software paths work.
- Remediation: assign owners, train operators/approvers, document daily reconciliation, correction, backup/restore and incident steps; obtain explicit business release acceptance.
- Blocking: **YES**, real company Go-Live.
- Acceptance: named staff pass role-based UAT and correction/recovery scenarios; actual operating procedures, support/escalation and independent finance opening/cutover sign-off are recorded without credentials.

## Register totals and ordering

**24 unique findings: P0 5 / P1 13 / P2 4 / P3 2.** This counts capability gaps and evidence blockers separately from reproduced vulnerabilities. No unknown target state is counted as a confirmed host defect. Current negative runtime outcomes and untested limits take precedence over historical PASS text.

Remediation order: financial integrity (001/002/003) → mandatory M10/M15 and workforce/reporting scope → full source/stock/finance lifecycle including applicable returns/serials/advances → security/dependency acceptance → reviewed immutable artifact and PostgreSQL CI → target/deployment/supervision/DR/capacity → company inputs/operator UAT → independent final decision. Nonblocking documentation/style work can be done alongside substantive fixes. This ordering authorizes no fixes or deployment during the audit.
