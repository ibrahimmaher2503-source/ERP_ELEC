# Performance and debugging tooling audit

> Follow-up: Debugbar 4.4.5 and Pulse 1.8.1 have now been installed with isolated, opt-in configuration and focused acceptance. See [native profiling results](ERP_NATIVE_PROFILING_REPORT.md). The inventory below records the earlier pre-install audit state.

Date: 2026-10-31. Scope: repository/package inspection and adoption recommendation. **No packages installed, monitoring migrations executed, application configuration changed, scripts deleted, browser measurements repeated, or business logic changed.** Commands below are future setup instructions, not evidence of installed tools.

## Decision and installed inventory

Use **Debugbar v4 for local request diagnosis**, **Pulse for restricted aggregate monitoring**, and **Playwright for browser performance**. Do not add Telescope, Clockwork, Nightwatch, another browser framework, or a replacement query profiler.

| Tool | Current installation | Measures / recommended role |
|---|---|---|
| Laravel / Livewire / Flux | 13.38.0 / 4.4.7 / 2.20.1; PHP 8.4.21 | Existing application stack. |
| Laravel logging / Monolog | Monolog 3.12.1; configured single/daily channels | Existing exception detail and application logs; retain. Business/security audit history is separate and must also remain. |
| Collision | 8.9.5, development dependency | Console exception rendering; retain, not an APM tool. |
| Playwright | 1.62.1, development dependency | Real navigation TTFB, DOMContentLoaded/load, visible primary content, Livewire UI stability/transitions, requests, bytes and browser failures. Retain. |
| Laravel Pulse | **Not installed** | Slow-request/query aggregates, exception frequency/location, resource trends. Proposed restricted monitoring. |
| Laravel Debugbar v4 | **Not installed** | Individual request SQL/counts/repetition, PHP timeline/memory, exceptions, AJAX/Livewire request diagnosis. Proposed local-only profiler. |
| Telescope / Clockwork / Sentry / Nightwatch | Not present in lockfile or installed package inventory | No additional installation proposed. |

Installed versions were checked in both `composer.lock` and `vendor/composer/installed.json`; frontend tooling in `package.json`. Reviewed `AGENTS.md`, PRD, architecture, handoff, providers, logging, PHPUnit settings and browser tooling. Existing dirty Composer files were preserved.

Both proposed package manifests declare Laravel 13 support; Pulse also declares Livewire 4 support. Debugbar v4 is **`fruitcake/laravel-debugbar`**, which replaces the older `barryvdh/laravel-debugbar` package. Do not install both. This is manifest compatibility, not a completed Composer resolution or runtime acceptance. [Pulse manifest](https://github.com/laravel/pulse/blob/1.x/composer.json), [Debugbar manifest](https://github.com/fruitcake/laravel-debugbar/blob/master/composer.json).

## What the ready-made tools replace

**Pulse:** suitable for endpoint trends, slow individual SQL and exception aggregates, not browser paint/readiness or a complete per-request trace. Its default slow-query threshold is 1,000 ms: thousands of individually fast permission queries can escape that card, so use Debugbar for repetition/count diagnosis. PostgreSQL is supported. Default storage ingest needs no Redis; existing Predis need not be expanded. [Laravel Pulse documentation](https://laravel.com/docs/13.x/pulse).

**Windows limitation:** the current Pulse server recorder uses `wmic` for Windows CPU/memory; `wmic` is unavailable on this workstation. Keep its Servers recorder off here unless validated, or use the standard recorder on a compatible staging host. Do not build custom Windows resource collectors merely to fill the dashboard. [Recorder source](https://github.com/laravel/pulse/blob/1.x/src/Recorders/Servers.php).

**Debugbar:** AJAX capture and the Livewire collector cover Livewire requests/components without custom SQL headers or fingerprint logs. Built-in stored-request CLI commands provide targeted diagnosis with little output. These are instrumented request timings and must never replace the uninstrumented Playwright baseline. [Collectors](https://laraveldebugbar.com/collectors/), [usage and CLI](https://laraveldebugbar.com/usage/).

## Security and measurement requirements before adoption

These are implementation requirements; this audit does not claim they have been configured or tested.

- **Debugbar:** install with `--dev`; exclude it from production using Composer `--no-dev`. Enable only in a private local profiling environment on loopback with synthetic PostgreSQL data. Keep `force_allow_enable` false. Disable request/session/auth/config/view-data/mail/model/log/message collectors unless a synthetic reproduction needs them. Keep stored data outside `public`, clear it after diagnosis and do not commit or paste it into AI context.
- Keep Debugbar storage browsing closed (`DEBUGBAR_OPEN_STORAGE=false`). If AJAX history in the toolbar is needed, replace this with a callback allowing only loopback plus an authenticated, active, verified, authorized developer; never use unrestricted `true` or rely on the default private-LAN allowance. CLI inspection can remain local. [Installation](https://laraveldebugbar.com/installation/), [storage configuration](https://github.com/fruitcake/laravel-debugbar/blob/master/config/debugbar.php).
- **Binding privacy:** `options.db.with_params=false` changes displayed SQL, but the collector still includes a `params` array. It is **not redaction**. Do not profile real customer/financial data with the query collector. Use synthetic reproductions rather than another home-grown sanitizing collector. [Query collector source](https://github.com/fruitcake/laravel-debugbar/blob/master/src/DataCollector/QueryCollector.php).
- **Pulse:** default recording off; allow recording only in explicitly approved environments. Protect `/pulse` with authentication, verification, existing active-account/session enforcement and an explicit `viewPulse` gate for authorized Administrator role members through Organization-owned authorization. Override the default gate **even locally**, where the package otherwise allows access without requiring a user. `PULSE_ENABLED=false` is not dashboard authorization. [Default gate](https://github.com/laravel/pulse/blob/1.x/src/PulseServiceProvider.php).
- Start Pulse with SlowRequests, SlowQueries and Exceptions only; Servers is conditional above. Disable user-usage/cache-key/outgoing-request recorders and user cards initially. SlowQueries uses SQL without bound values, but raw SQL literals may still be sensitive. Review/filter those entries and normalize Livewire snapshot paths containing record IDs before any non-synthetic rollout. Exception aggregation stores class/location rather than a full payload. Use short retention (one day for the pilot) and a restricted monitoring database connection for later staging. [SlowQueries](https://github.com/laravel/pulse/blob/1.x/src/Recorders/SlowQueries.php), [Livewire path handling](https://github.com/laravel/pulse/blob/1.x/src/Recorders/Concerns/LivewireRoutes.php), [Exceptions](https://github.com/laravel/pulse/blob/1.x/src/Recorders/Exceptions.php).
- **Benchmark isolation gap:** `phpunit.xml` already sets `PULSE_ENABLED=false`; the standalone Node browser bootstraps do not read PHPUnit XML. Both `tests/E2E/bootstrap.cjs` and `support/performance-bootstrap.cjs` build an allowlisted environment with `APP_DEBUG=false` but no explicit Pulse/Debugbar switches. Their environment construction can discard shell flags. Before installation, explicitly set **`PULSE_ENABLED=false` and `DEBUGBAR_ENABLED=false` inside those generated runtime environments**, and make profiling a separate process. Do not mix Debugbar/Pulse-enabled results into existing page-speed distributions.

## Exact commands and URLs

**Proposed adoption only:** first implement the restrictions above and keep both tools disabled while publishing. From the repository root:

```powershell
$env:PULSE_ENABLED = 'false'
$env:DEBUGBAR_ENABLED = 'false'
php .tools/composer.phar require --dev 'fruitcake/laravel-debugbar:^4.0'
php .tools/composer.phar require 'laravel/pulse:^1.0'
php artisan vendor:publish --provider='Fruitcake\LaravelDebugbar\ServiceProvider'
php artisan vendor:publish --provider='Laravel\Pulse\PulseServiceProvider'
```

**Pulse migration pilot:** use only the existing marker-owned disposable PostgreSQL cluster at `127.0.0.1:55461`, database `erp_elec_new_test`; never use development port 5443. Load its private runtime environment without printing credentials:

```powershell
$monitorQa = Get-Content .tmp/page-speed-audit/bootstrap.json -Raw | ConvertFrom-Json
$monitorQa.env.PSObject.Properties | ForEach-Object {
    [Environment]::SetEnvironmentVariable($_.Name, [string]$_.Value, 'Process')
}
$env:PULSE_ENABLED = 'false'
$env:DEBUGBAR_ENABLED = 'false'
$env:PULSE_DB_CONNECTION = 'pgsql'
& .tools/postgresql/pgsql/bin/psql.exe --no-password -h 127.0.0.1 -p 55461 -U $monitorQa.env.DB_USERNAME -d erp_elec_new_test -Atc "select current_database(), inet_server_port(), current_setting('data_directory');"
```

Before continuing, verify the bootstrap/`codex-owned.json` marker belongs to this checkout, its port/database match the values above, and the returned data directory resolves to this checkout's `.tmp/page-speed-audit/postgres-55461`. Abort on mismatch or unavailable cluster; do not substitute another listener or reset any database. Then run only the published Pulse migration, first as SQL preview:

```powershell
php artisan migrate --env=testing --database=pgsql --path=database/migrations/2023_06_07_000001_create_pulse_tables.php --pretend
php artisan migrate --env=testing --database=pgsql --path=database/migrations/2023_06_07_000001_create_pulse_tables.php
```

No blanket `migrate`, `migrate:fresh`, rollback, seeding, operational-database migration or production activation is part of this adoption. Debugbar's proposed file storage requires **no database migration**. Pulse's published migration creates its own monitoring tables. [Migration source](https://github.com/laravel/pulse/blob/1.x/database/migrations/2023_06_07_000001_create_pulse_tables.php).

After disposable validation and environment/user restrictions are configured:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
php artisan debugbar:find --issues
php artisan debugbar:get latest
php artisan debugbar:queries latest
php artisan debugbar:clear
php artisan pulse:check
php artisan pulse:restart
```

Run `pulse:check` in a separate terminal on a host with a validated Servers recorder, only when that recorder is enabled. No Redis ingest or `pulse:work` daemon is needed for the proposed storage-ingest pilot. Do not enable either profiler in the benchmark terminal.

- Pulse dashboard, **after installation/configuration**: `http://127.0.0.1:8000/pulse`.
- Debugbar: bottom toolbar on `http://127.0.0.1:8000/dashboard` and other authorized local ERP pages. **There is no separate Debugbar dashboard URL.** Its internal storage handler is not a public dashboard.
- Existing static browser-results dashboard: `docs/performance/ERP_PAGE_SPEED_DASHBOARD.html`.
- Neither new tool URL is currently installed/verified live.

## Custom tooling disposition

Current performance-specific surface: **13 files / 2,947 physical lines**, including fixtures. It duplicates bootstrap, SQL capture, recovery and reporting responsibilities; its existing results remain useful evidence.

| Existing code | Decision / prerequisite |
|---|---|
| `tests/E2E/page-speed-audit.cjs` (1,064 lines) | Keep route discovery, valid fixture parameter mapping, cold/warm desktop/mobile trials, navigation metrics, meaningful readiness, Livewire transition marks and raw evidence. Remove SQL-profiling/report-history responsibilities after native tooling is validated. |
| `tests/E2E/automation.cjs`, `playwright.config.cjs`, `tests/E2E/bootstrap.cjs` | Reuse existing Playwright installation, browser selection, loopback/ownership checks, isolated environment and failure-only artifacts. Functional journeys remain functional tests. Avoid a second generalized framework. |
| `support/performance-bootstrap.cjs` | Consolidate duplicated environment/storage/PostgreSQL setup into the existing owned bootstrap later. Preserve marker, actual server/database checks, separate DB and storage isolation; do not weaken guards to reuse another port. |
| `support/performance-router.php`, `page-speed-diagnostics.cjs`, `measurePage(..., profileQueries)`, `readQueryProfile()` | Retire custom DB listener, SQL fingerprints and `X-Audit-Request-ID` once Debugbar request diagnosis/Pulse monitoring pass restricted acceptance. Keep the isolated-storage mapping if a test router is still required. |
| `page-speed-resume-auth.cjs`, `page-speed-financial-tabs.cjs`, `page-speed-storage-recheck.cjs` | Historical repair helpers: archive/retire after their valid scenarios are integrated into one canonical runner. Do not rerun these as a standard pipeline. |
| `page-speed-guest-pages.cjs`, `page-speed-spa-transitions.cjs` | Keep browser measurement behavior; fold into canonical guest/auth/viewport cases rather than separate postpasses. |
| `page-speed-finalize.cjs`, `--rebuild-latest-reports` | Replace multiple history-dependent rebuild paths with one formatter over a run's real manifest/raw results. Remove embedded historical counts/findings and reconstructed placeholder route catalogs; preserve existing report archives. |
| `performance-critical-fixtures.php`, `performance-financial-fixtures.php`, `performance-auth-refresh.php` | Retain necessary owner-action fixture/account provisioning; consolidate with existing E2E fixture infrastructure instead of recreating business actions or granting authority by name. Future check-code edits remain Luna-authored under AGENTS.md. |

Existing commands, **not run by this audit**:

```powershell
npm run e2e:report
node tests/E2E/page-speed-audit.cjs --reuse-db --preflight-only
node tests/E2E/page-speed-audit.cjs --reuse-db
node tests/E2E/page-speed-audit.cjs --reuse-db --resume
```

The speed runner currently still performs its separate custom SQL diagnostic pass; it has no browser-only switch yet. Do not describe these commands as the simplified target architecture. `--preflight-only` can create fixtures/accounts; it is not read-only. A default fresh invocation can recreate the owned QA database; the commands above deliberately request reuse. `--resume` must retain `--reuse-db`. Historical postpasses/report generators are not a turnkey fresh-run pipeline.

**Adoption order:** add explicit benchmark-off switches and configure authorization/privacy defaults; install native tools disabled and validate Pulse migrations on disposable PostgreSQL; preserve one uninstrumented browser entry point; then retire duplicate profiling/recovery scripts. Update callers before deleting scripts. No tests, monitoring migrations, package resolution, installation or new performance campaign were run for this audit.
