# Performance, capacity and current visual readiness

Audit: 2026-11-01. Capacity readiness: **NOT TESTED on production-like staging / BLOCKED for a capacity promise**. Whole-interface acceptance: **INCOMPLETE**. Local measurements and representative screenshots below are evidence with explicit limits.

**Source boundary:** the 90 screenshots were captured around00:56UTC; an unattributed CSS edit01:06:54UTC/public build01:07:27UTC followed. Local load was measured afterward. These observations cannot be combined as a single frozen-candidate acceptance/no-regression result. Current financial source hashes remained unchanged. The audit did not revert or originate the concurrent UI work; `evidence/production-readiness-20261101/CANDIDATE_DRIFT.md` records it. Later-final layout is NOT TESTED by the earlier captures.

## Historical baseline, not current certification

`docs/performance/ERP_PHASE2_FINAL_REPORT.md` remains **PHASE 2 INCOMPLETE — REMAINING DEFECTS**. Its historical successful local built-in-server/Edge/isolated-PostgreSQL campaign, fingerprint `fddffec637f3654b8a72e996bffe640a54f977dd7b715973c07c9a3d565fe493`, reports pooled warm desktop median/P95 **261.1/421.5ms** and mobile **272.1/469.6ms**,483 samples per viewport. Per-page P95 was withheld at n=3. Source/UI/assets have since changed; the audit did not repeat or overwrite that historical BEFORE campaign.

The current UI unification report records a small matched18-navigation sample, including aging desktop540→1178ms median and customer desktop679→893ms. That is an unresolved historical observation, not a newly reproduced regression or proof of a CSS cause. It must be diagnosed on a quiet server and current source before the performance no-regression gate can pass.

## Fresh local experiment

Luna-authored scripts under ignored `.tmp/production-readiness-browser-20261101/` use a dedicated marker-owned PostgreSQL 17.11 cluster at55464, database `erp_pr_browser_20261101`, private storage/config/views/sessions, and owned direct PHP loopback server18064. No development/shared benchmark database was reset or loaded. Actual installed Edge is used; no browser or monitoring package was installed.

Current evidence is under `evidence/production-readiness-20261101/browser/`:

- `browser-results.json`: exact route/viewport status, rendered title, RTL, page-level overflow, images/console observations, first Tab focus and screenshot names.
- `bounded-get-load.json`: bounded local authenticated read-only cohort results at5/20/50/100 virtual users. Read its recorded workload and error counts; these are not concurrent financial/stock mutations or target capacity.
- Any supplemental filter/resource/checkpoint receipt is independently scoped; it does not turn the 90 captures into full workflow tests.

Workload limitations are material: local single-process PHP server; minimal synthetic fixture graph, many empty lists/reports; three protected GETs per user; synthetic principals/sessions; no realistic large catalogs/documents, financial write/stock contention, export/report generation workload, upload stress, worker backlog or target network. Session/cache/limiter configuration is recorded by the harness; array cache cannot establish persistent login-throttle or production cache performance. Concurrent GET requests do not demonstrate100 active operators posting business transactions.

### Completed bounded local cohorts

Final receipt `browser/bounded-get-load.json`: **525 request attempts,516 HTTP200,9 timeouts**. Legitimate CSRF login and authenticated URL/body readback established sessions before measurement; later cohorts use distinct synthetic principals. The original slow UI-login setup was replaced by legitimate form/CSRF login in the harness; application authentication was not disabled. Completed cohort checkpoints were preserved rather than replayed.

| Virtual users | GET attempts | HTTP200 / timeout | Median / P95 (ms) | Elapsed seconds | Attempt throughput/s | PHP CPU seconds delta |
|---:|---:|---:|---:|---:|---:|---:|
|5|15|15 /0|920.7 /1060.8|2.88|5.20|2.09|
|20|60|60 /0|4075.3 /4261.0|12.34|4.86|9.02|
|50|150|150 /0|9369.8 /10135.1|29.13|5.15|21.22|
|100|300|291 /9|18860.2 /19714.3|58.23|5.15|42.34|

These medians/P95 include recorded request outcomes, including timeouts; throughput is **attempts**, not successful transactions. At100 users, timeout rate is **3%**; successful response throughput is approximately5.00/s. No formal response-time objective was supplied, so no capacity cohort is certified. Approximately5 attempts/s and queue growth describe this serialized development server, not the company's future hosting capacity or an isolated SQL bottleneck.

The PHP process's after-cohort working sets were75.42/75.46/75.60/78.79MB (decimal MB). These are sampled resident sets, not peaks or whole-system/browser RAM. Ungranted PostgreSQL lock counts were0 before/after each cohort; that does not rule out waits during the run and there were no financial writes. Full system CPU, peak memory, SQL durations/plans, Livewire action latency, write contention and large-data/export/upload/report/job workload remain NOT TESTED in this campaign.

## Fresh browser / visual sample (G7)

**90 screenshots:18 page cases ×5 viewports**,1440×900,1366×768,768×1024,390×844 and360×800. All 90 sampled navigation responses returned200, RTL=true, no document-level horizontal overflow, broken images or unexpected console/page errors in the receipt. Pages include13 implemented owner surfaces plus shared home, forms, customer details and Trial Balance; Tender is included as a capability inside Projects, not a new owner or M16.

Six real synthetic record checks (customer, project, project document **listing**, tender, draft customer invoice and draft workshop intake) returned **reader200 / outsider403**. These are existing fixture records, not invented-ID404 tests. They do not cover private binary document downloads, all roles, assigned technician authority, posted invoices or every Livewire mutation.

The lead directly opened and inspected these rendered files:

| Archetype / view | Actual screenshot reviewed | Observation |
|---|---|---|
| Directory / desktop | `M02-Products-1440x900.png` | Shared RTL heading/filter/table shell and honest empty catalog; no populated dense-row acceptance. |
| Form / mobile | `M03-Customer-create-form-390x844.png` | Stacked labeled fields, preview and bottom save/cancel visible in the full capture; no save was submitted. |
| Record detail / tablet | `M03-Customer-detail-768x1024.png` | Identity/tabs/facts/empty related sections render coherently. |
| Dashboard / mobile | `Shared-Home-360x800.png` | Actual authorized workspace/module links; long company name truncates. It is a workspace, not executive financial analytics. |
| Report / desktop | `M11-Trial-balance-report-1440x900.png` | Scoped report controls and honest empty balance view. No numeric report correctness is inferred from an empty screen. |
| Workflow / mobile | `M17-System-390x844.png` | Import steps render an explicit insufficient-template-authority/disabled-upload state for this fixture. This is unavailable-state rendering, not successful import acceptance. |

The initial generic input probe filled the **sidebar page search** with DEMO on several pages; it is not proof that each owner's own list filter works. Its captured sidebar no-results state is therefore expected. A visible first-Tab focus is a basic keyboard smoke check, not complete keyboard or native assistive-technology acceptance. All 90 automated checks are distinguished from the six images directly inspected by the lead.

Supplement `browser/customer-list-filter.json` and `customer-list-filter-390x844.png` targets the actual customer list input (`[data-erp-archetype="list"] input`): the no-match query removes the synthetic record and its exact demo-code query restores it. This is one verified owner filter interaction, not every filter in the 90 captures.

## Current source / design conclusions

The actual shared blue/white Arabic RTL shell, heading/breadcrumb, filter/card primitives and canonical page-family changes exist. `docs/design/ERP_UI_ARCHITECTURE.md`, `ERP_DESIGN_SYSTEM.md`, `ERP_PAGE_ARCHETYPE_MATRIX.md` and `ERP_UI_UNIFICATION_FINAL_REPORT.md` describe adoption, but the final report explicitly says acceptance incomplete. The original visual findings are not reintroduced as fresh bugs when later source/evidence addressed them.

The requested Modern SaaS Executive Dashboard is **not implemented as authoritative business analytics**. Current `resources/views/dashboard.blade.php` is a usable authorized workspace; its regression intentionally excludes invented financial totals. KPI/trend/cash-flow/service/project analytics require real scoped owner projections. PR-UI-002 records this capability gap, not an instruction to fabricate charts.

Remaining states include every route/variant, populated large tables, drawers/modals, validation/error/success/loading branches, all role/permission states, real physical touch devices, native assistive technology and the complete business lifecycle across viewports. Representative consistency does not complete G7.

## Capacity closure

PR-CAP-001 requires a concrete target-like environment, approved workload mix/data volumes and service objectives. Measure page TTFB/UI-ready, Livewire action latency, SQL duration/count/plans, export/search/upload/report/job timings, throughput/errors/timeouts, CPU/RAM/disk and lock contention at5/20/50/100 users. Include real conflicting posting/reservation/allocation/numbering operations and verify resulting ledger correctness. Current local results must remain separately labeled.

Findings/closure criteria: PR-CAP-001, PR-UI-001 and PR-UI-002 in [the register](PRODUCTION_READINESS_BLOCKERS.md). No source performance/UI fix was made during this audit.
