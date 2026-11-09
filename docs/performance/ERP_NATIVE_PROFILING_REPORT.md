# Native Laravel profiling — focused diagnosis

2026-10-31. Installed **fruitcake/laravel-debugbar 4.4.5** (require-dev) and **Laravel Pulse 1.8.1**. Composer installed six required packages, without updating existing packages. No business logic, permission rules, operational records or application UI were optimized.

Sanitized evidence: [ERP_NATIVE_PROFILING_RESULTS.json](ERP_NATIVE_PROFILING_RESULTS.json). Native query-duration units were converted from seconds to milliseconds; full native profiles retain parameters privately, while this export excludes parameters and profile identifiers.

## Measurements

Existing Playwright measured five representative pages on desktop, with one cold and three warm navigations each: **20/20 valid samples**, with Debugbar and Pulse disabled. A separate local Debugbar process captured five requests and one actual read-only Livewire search, using native `debugbar:get` / `debugbar:queries` output. Instrumented durations are diagnostic evidence, not browser-performance baselines.

| Page | Warm median visible-content time | Warm median TTFB | Native Debugbar SQL count |
|---|---:|---:|---:|
| Dashboard | 506 ms | 406 ms | 298 |
| User details | 2,432 ms | 2,359 ms | 3,724 |
| Maintenance work-order details | 2,218 ms | 2,053 ms | 1,475 |
| Maintenance list | 1,383 ms | 1,277 ms | 786 |
| Customers list | 822 ms | 632 ms | 341 |

The customer-search Livewire request returned HTTP 200, issued one update request and executed **43 SQL statements**. All five instrumented pages returned 200; the native issue scan reported zero failed SQL statements and zero exceptions. Full query profiles, bindings, credentials and authenticated browser states remain under ignored `.tmp`, not in this report.

The original 20 baseline samples were reused after correcting the Debugbar environment; they were not rerun. Debugbar v4 intentionally disables itself in `testing`, so native profiling uses **APP_ENV=local against the same isolated PostgreSQL test database**. `force_allow_enable` remains false.

## Why these pages are slow

1. **Repeated authorization queries dominate the query volume.** `PermissionAccess::allows`, `roleCapability` and `branchIds` repeatedly read account status, role membership, direct grants and branch/warehouse scopes. User details waits 2.359 seconds for the response before content appears at 2.432 seconds, locating the delay in the server request rather than after content delivery. See [PermissionAccess](../../app/Modules/Organization/Application/PermissionAccess.php).
2. **User details eagerly renders two complete permission sections.** Access and history tabs use Alpine `x-show`, which hides HTML after server rendering. Both mount `UserAccessSection`; even `historyOnly=true` still walks the entire permission catalog. Its warehouse branch also reads warehouse scopes inside the permission loop. See [detail tabs](../../resources/views/organization/user-details.blade.php) and [permission section](../../app/Modules/Organization/Presentation/UserAccessSection.php).
3. **Maintenance amplifies per-branch authorization.** `MaintenanceAccess::query` traverses allowed branches and repeatedly calls permission checks to classify technician-limited access; each authorization also verifies project branch membership. The measured 786/1,475 request query counts support inspecting this path first. See [MaintenanceAccess](../../app/Modules/FieldMaintenance/Application/MaintenanceAccess.php).

Native duplicate/N+1 flags indicate repeated SQL forms; they do not prove every repetition is an ORM eager-loading defect. OPcache is already active, including `opcache.enable_cli=On`; this run does not support blaming an absent opcode cache.

**First optimization candidates, not implemented:** bulk-resolve the current actor's role/grant/scope information once per request, invalidating that request snapshot after authorization mutations; defer hidden user tabs; avoid rebuilding the effective-permission matrix in history-only mode; reuse the resulting authorized branch classification in maintenance. Preserve status checks, scope semantics and revocation on subsequent requests. Measure the same five cases after any approved change.

## Pulse and isolation verification

- Native Pulse persisted the slow user-details request: five `slow_request` entry rows and 26 aggregate bucket rows were present. Bucket rows are not 26 distinct requests.
- Recorded types were only `slow_request` and `slow_query`, within the configured three-type allowlist. Exception collection is configured but was not deliberately exercised.
- Dashboard access: authorized security administrator **200**; guest **302** to login; verified no-grant synthetic user **403**; monitoring disabled **403**.
- Both packages default off. Guards restrict collection to loopback, the marker-owned PostgreSQL test target `127.0.0.1:55461/erp_elec_new_test`, and private `.tmp` storage. Debugbar additionally requires `local`; Pulse accepts local/testing and uses existing Organization authorization. No new permission keys were created.
- Debugbar file storage browsing is closed; only time/memory/query collectors are configured. SQL bindings are still private diagnostic data even with SQL parameter substitution disabled. No real financial/customer dataset was profiled.
- Only Pulse's monitoring migration ran, on the owned disposable PostgreSQL target. Its unchanged file lives in `database/monitoring/2026_10_31_180448_create_pulse_tables.php`, outside ordinary application migration discovery. No operational database migration ran.
- Both browser bootstraps now explicitly disable Pulse and Debugbar. Per-phase relative config-cache paths prevent profiler settings leaking into the uninstrumented baseline.

## Commands and dashboard access

Focused reproduction, using the existing owned QA database and synthetic accounts, without resetting/seeding it:

```powershell
node tests/E2E/native-performance-profile.cjs
```

The runner prints its private evidence path. Temporary loopback URLs are in the private phase-server logs; those servers close when the run finishes. Pulse is at **`<pulse-phase-base-url>/pulse`** while that phase runs. Debugbar is the toolbar on **`<debugbar-phase-base-url>/dashboard`**, with stored requests inspected by native CLI; its HTTP history handler remains closed. These are isolated profiling servers, not the operational `http://127.0.0.1:8000` session.

Native inspection commands must use the same private Debugbar runtime environment/config cache constructed by the runner:

```powershell
php artisan debugbar:find --issues
php artisan debugbar:get <request-id> --json
php artisan debugbar:queries <request-id> --json
```

Read the already-captured native profiles without any browser requests or migration:

```powershell
node tests/E2E/native-performance-profile.cjs --summarize-existing .tmp/page-speed-audit/native-profile/1793470561835-32812/debugbar --summary-file .tmp/page-speed-audit/native-profile/evidence-1793470561835-32812/summary.json
```

The explicit monitoring-only migration command, only in the validated disposable environment, is:

```powershell
php artisan migrate --env=testing --database=pgsql --path=database/monitoring/2026_10_31_180448_create_pulse_tables.php
```

Debugbar uses file storage and requires no database migration. Pulse uses its native database ingest; no Redis worker, custom SQL listener or Windows resource collector was added. The default user/server/cache cards are omitted. Existing historical speed reports and custom repair scripts were preserved; this focused run does not invoke their custom SQL profiling pass.

## Limits

This is a targeted local desktop diagnosis, not another all-route audit or production load test. No broad regression suite or performance optimization ran. Scoped Pint passed for monitoring configuration/provider changes. Composer also reports existing medium advisories for Livewire (`PKSA-xhv3-nr94-h8db`) and Symfony Mime (`PKSA-wtxr-p26d-nn42`); neither was updated by these installs. Their remediation is separate from this performance diagnosis.
