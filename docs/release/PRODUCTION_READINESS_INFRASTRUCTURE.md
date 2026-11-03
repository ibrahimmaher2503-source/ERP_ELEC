# Production infrastructure, deployment and operations audit

Audit: 2026-11-01 (Africa/Cairo). Overall target readiness: **BLOCKED**. This is an audit of the current local candidate; no production deployment or activation was attempted.

## Target evidence boundary

No target hostname, server inventory, approved environment or ERP-to-host binding was established from this checkout. This does **not** establish that the owner owns no server. Available hosting tooling does not identify an ERP target merely by being connected. No unrelated hosted application was scanned or changed.

`compose.yaml:1` defines PostgreSQL 17 and optional Redis 7, bound to loopback. It supplies no application/web image, TLS termination, worker/scheduler supervisor, backup service or monitoring deployment. The local PHP server is a development runtime. Historical deployment checklists were read as investigation leads, never as proof of a working target.

| Gate / item | Evidence-based status | Observation / required acceptance |
|---|---|---|
| G8 host, plan, OS, CPU/RAM/disk | BLOCKED | No ERP target binding or target inventory; verify on the selected host. |
| Target PHP/extensions/PostgreSQL | BLOCKED | Installed local PHP 8.4.21 and bundled PostgreSQL 17.11 do not prove target compatibility. Required `bcmath`, `pdo_pgsql`, `intl`, `mbstring`, XML, GD, ZIP and PDF dependencies must be validated in the target image. |
| Web server / document root | NOT TESTED | Configure `public` as document root and prevent access to source, environment, vendor and private files. No production Nginx/Apache/PHP-FPM configuration is present in the audited deployment files. |
| HTTPS/TLS/domain/DNS/proxy | BLOCKED | No target endpoint; certificate validity, redirect, proxy trust, host filtering, HSTS and security headers unmeasured. |
| Firewall / SSH / deployment identity | BLOCKED | No target access model or network policy inspected. |
| Runtime secrets / least privilege | NOT TESTED | `.env.example` is deliberately local, with debug enabled and log mail. It must not be deployed verbatim. No secret values were requested or included in the audit. |
| Session/cache backends | NOT TESTED on target | Template uses encrypted database sessions and file cache; Secure cookie is environment-controlled (`config/session.php:172`). Redis is optional. Multi-instance sessions/locks require a shared, verified backend. |
| Queue workers | NOT TESTED | `System/Application/DataOperations/ProcessOperation.php:13` queues work on **data-operations**, with three tries and a 120s timeout. A default-queue-only worker will not consume this queue. Verify the actual supervised queue list, timeout, retry-after and failed-job alerts. Required stock/GL effects remain synchronous. |
| Scheduler | VERIFIED PASS (source registration only) | `routes/console.php:7`: per-minute data-operation cleanup with overlap protection. Target invocation, locking and failure alerts remain NOT TESTED. |
| Private storage | VERIFIED PASS (source placement) | `config/filesystems.php:35` uses `storage/app/private`; owner download authorization exists. Target filesystem permissions, capacity, backup and direct-URL denial remain NOT TESTED. |
| Time / timezone | VERIFIED PASS (source policy only) | UTC application persistence and Africa/Cairo business presentation; target clock synchronization and database timezone remain NOT TESTED. |
| Health checks | VERIFIED PASS (source only) | `bootstrap/app.php:16` registers `/up`. No demonstrated target DB/queue/storage dependency health check or external alert. |
| Log retention | NOT TESTED on target | Template uses `single`; daily logging exists with 14-file default but is not the selected local template. No production retention/access policy verified. |
| G11 metrics / alerts / escalation | BLOCKED | No proven 5xx, queue/scheduler, backup-failure, database, disk or TLS-expiry alert delivery, on-call owner or escalation drill. See PR-OPS-001. |

## Deployment automation (G9)

| Step | Status | Current evidence / limitation |
|---|---|---|
| Immutable release selection | BLOCKED | `main` HEAD `28cca25ef242bbcdcfcd29a976f8e3a552768a87` plus 556 pre-existing dirty/untracked entries; HEAD is not the candidate. PR-REL-001. |
| Composer production install | NOT TESTED | Existing vendor was inspected. No fresh target `composer install --no-dev --prefer-dist --optimize-autoloader` rehearsal or target extension check was completed. |
| Frontend production build | VERIFIED PASS locally | `evidence/production-readiness-20261101/vite-build.txt`: `npm run build -- --outDir .tmp/production-readiness-20261101/vite-build`, exit 0, 22 modules. Public deployed assets were not overwritten. |
| Config / route / view caches | See database/runtime receipt | Only isolated-cache output is permissible in this audit. A local compile does not establish target bootability, HTTPS or mail. |
| Forward migrations | See database report | New disposable PostgreSQL clean migration is separate from a populated upgrade and target migration. Many financial migrations intentionally refuse `down()`. |
| Queue restart / scheduler activation | NOT TESTED | No real service manager or deployment hook demonstrated. |
| Atomic release / storage links / permissions | NOT TESTED | No complete target deployment script or release-directory strategy established. |
| Failed deployment and recovery | BLOCKED | No disposable target with the intended production stack; no actual failed-release rollback rehearsal. |
| Post-deploy health / smoke acceptance | BLOCKED | No selected target or deployed exact artifact. |

CI is not a deployment pipeline. `.github/workflows/tests.yml:20` selects PHP 8.3/Node 22 and runs `composer setup`; `composer.json` setup immediately migrates using the copied local `.env.example` PostgreSQL endpoint. The workflow declares no PostgreSQL service, database initialization or `.env.testing` provisioning. PR-CI-001 is a source-verified missing prerequisite, not a claim that a remote Actions run was observed failing. `npm install` in setup also differs from a locked `npm ci` install.

## Monitoring posture

`config/debugbar.php:5` and `config/pulse.php:8` deliberately restrict profiling to isolated loopback benchmark contexts. `MonitoringServiceProvider` also requires active/verified security-admin authority to view Pulse. These are useful disclosure controls; they do not provide production observability. The deployed debug=false setting, application/log access, external alert delivery and retention remain unverified. No monitoring package was installed.

Blocking findings: PR-INF-001, PR-REL-001, PR-CI-001, PR-OPS-001, PR-DR-001, PR-CFG-001. Canonical finding fields and closure tests are in [the finding register](PRODUCTION_READINESS_BLOCKERS.md).

Production acceptance should follow the [Laravel deployment guidance](https://laravel.com/framework/docs/deployment): verify the real server requirements, public document root, production debug setting, optimized caches and health checks on the selected artifact. This audit did not provision that environment.
