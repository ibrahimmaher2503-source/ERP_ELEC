# ERP Page Speed Report

Generated: 2026-10-31T17:47:29.993Z

## Scope and conditions

- GET routes discovered: **210** (194 named; 16 unnamed).
- Eligible page routes (including guest pages) / expanded cases: **146 / 166**
- Cases with 3 measured warm samples on both viewports: **162/166**.
- GET routes excluded by the explicit rules below: **64**
- Route/viewport summaries measured: **648**
- Raw samples: **1685**
- Pages/samples not measured: **32**
- Rendered timings retained: **1308 healthy + 24 degraded**; degraded asset HTTP errors do not erase valid page timings.
- Samples with HTTP error responses (4xx/5xx, including assets): **56**; 115 error responses recorded (lower bound because per-sample response lists are capped). These are separate from JavaScript and transport failures.
- Browser: Microsoft Edge via Playwright; serial navigation; no production target.
- Server: local PHP built-in server; no network throttling configured.
- Source fingerprint SHA-256: start 4ec6a13e9c6e1e8b09e1258a64ce999f1329a0c6c5c5210f5d34d356cd97cb02; end 4ec6a13e9c6e1e8b09e1258a64ce999f1329a0c6c5c5210f5d34d356cd97cb02; unchanged: true.
- Database: isolated PostgreSQL erp_elec_new_test on 127.0.0.1:55461.
- Viewports: desktop 1440×900; mobile 390×844.
- Runs per resolved route/viewport: one cold navigation in a fresh browser context, followed immediately by 3 measured warm navigations in that context. The cold navigation primes the browser cache for warm trials.
- SPA route transitions attempted: 36; passed: 36; route cases without a dashboard link, not attempted: 321. Reachable shell links are sampled three times per viewport; unavailable links are reported separately from page timings.
- Cold means an empty browser context/cache. Server/runtime caches stay active; this is not a process/OS cold-start benchmark.
- Transferred bytes are the sum of Playwright responseBodySize values across responses; response headers are excluded.
- Livewire readiness uses explicit DOM readiness and loading-state checks; network idle was not used.

### Route exclusions

- well-known.passkeys — .well-known/passkey-endpoints: authentication, transport, download, export, PDF, or file response route
- home — /: route has no authenticated-page middleware
- accounting.journals.pdf — accounting/journals/{journal}/pdf: authentication, transport, download, export, PDF, or file response route
- accounting.reports.export — accounting/reports/{report}/{format}: authentication, transport, download, export, PDF, or file response route
- customers.documents — customers/{customer}/documents/{document}: authentication, transport, download, export, PDF, or file response route
- verification.notice — email/verify: authentication, transport, download, export, PDF, or file response route
- verification.verify — email/verify/{id}/{hash}: authentication, transport, download, export, PDF, or file response route
- financial.documents — financial/documents/{type}/{record}: PDF/binary file response; explicitly excluded from page performance coverage
- (unnamed) — flux/editor.css: unnamed GET route
- (unnamed) — flux/editor.js: unnamed GET route
- (unnamed) — flux/editor.min.js: unnamed GET route
- __flux.flag — flux/flags/{country}: authentication, transport, download, export, PDF, or file response route
- (unnamed) — flux/flux.js: unnamed GET route
- (unnamed) — flux/flux.min.js: unnamed GET route
- (unnamed) — flux/phone-utils.js: unnamed GET route
- (unnamed) — flux/phone.js: unnamed GET route
- (unnamed) — flux/phone.min.js: unnamed GET route
- inventory.export — inventory/export/{entity}: authentication, transport, download, export, PDF, or file response route
- (unnamed) — livewire-0b809833/css/{component}.css: unnamed GET route
- (unnamed) — livewire-0b809833/css/{component}.global.css: unnamed GET route
- (unnamed) — livewire-0b809833/js/{component}.js: unnamed GET route
- (unnamed) — livewire-0b809833/livewire.csp.min.js.map: unnamed GET route
- (unnamed) — livewire-0b809833/livewire.min.js: unnamed GET route
- (unnamed) — livewire-0b809833/livewire.min.js.map: unnamed GET route
- livewire.preview-file — livewire-0b809833/preview-file/{filename}: authentication, transport, download, export, PDF, or file response route
- login — login: authentication, transport, download, export, PDF, or file response route
- maintenance.documents — maintenance/documents/{document}: authentication, transport, download, export, PDF, or file response route
- maintenance.report — maintenance/work-orders/{workOrder}/reports/{completion}: authentication, transport, download, export, PDF, or file response route
- organization.company.logo — organization/company/logo: authentication, transport, download, export, PDF, or file response route
- passkey.confirm-options — passkeys/confirm/options: authentication, transport, download, export, PDF, or file response route
- passkey.login-options — passkeys/login/options: authentication, transport, download, export, PDF, or file response route
- products.media — products/media/{media}: authentication, transport, download, export, PDF, or file response route
- products.media.download — products/media/{media}/download: authentication, transport, download, export, PDF, or file response route
- projects.export — projects/export: authentication, transport, download, export, PDF, or file response route
- projects.download — projects/{project}/documents/{revision}: authentication, transport, download, export, PDF, or file response route
- projects.financial.export — projects/{project}/financial/{format}: authentication, transport, download, export, PDF, or file response route
- purchasing.export — purchasing/export/{entity}: authentication, transport, download, export, PDF, or file response route
- purchasing.offer-file — purchasing/offer-files/{file}: authentication, transport, download, export, PDF, or file response route
- purchasing.orders.pdf — purchasing/orders/{order}/pdf: authentication, transport, download, export, PDF, or file response route
- quotations.acceptance-evidence — quotations/{quotation}/acceptance-evidence: authentication, transport, download, export, PDF, or file response route
- quotations.documents — quotations/{quotation}/documents/{document}: authentication, transport, download, export, PDF, or file response route
- quotations.official-pdf — quotations/{quotation}/official-pdf: authentication, transport, download, export, PDF, or file response route
- quotations.pdf — quotations/{quotation}/pdf: authentication, transport, download, export, PDF, or file response route
- password.reset — reset-password/{token}: authentication, transport, download, export, PDF, or file response route
- sales-orders.delivery-pdf — sales-orders/{order}/deliveries/{instruction}/pdf: PDF, export, download, official document, or report response controller
- sales-orders.pdf — sales-orders/{order}/pdf: authentication, transport, download, export, PDF, or file response route
- (unnamed) — settings: unnamed GET route
- storage.local — storage/{path}: authentication, transport, download, export, PDF, or file response route
- suppliers.documents — suppliers/{supplier}/documents/{document}: authentication, transport, download, export, PDF, or file response route
- tenders.document — tenders/documents/{document}: authentication, transport, download, export, PDF, or file response route
- tenders.export — tenders/exports/{format}: authentication, transport, download, export, PDF, or file response route
- tenders.bid — tenders/{tender}/bids/{financial}/pdf: PDF/binary file response; explicitly excluded from page performance coverage
- treasury.movements.pdf — treasury/movements/{movement}/pdf: authentication, transport, download, export, PDF, or file response route
- two-factor.login — two-factor-challenge: authentication, transport, download, export, PDF, or file response route
- (unnamed) — up: unnamed GET route
- password.confirmation — user/confirmed-password-status: authentication, transport, download, export, PDF, or file response route
- passkey.registration-options — user/passkeys/options: authentication, transport, download, export, PDF, or file response route
- two-factor.qr-code — user/two-factor-qr-code: authentication, transport, download, export, PDF, or file response route
- two-factor.recovery-codes — user/two-factor-recovery-codes: authentication, transport, download, export, PDF, or file response route
- two-factor.secret-key — user/two-factor-secret-key: authentication, transport, download, export, PDF, or file response route
- workshops.documents — workshops/documents/{document}: authentication, transport, download, export, PDF, or file response route
- workshops.exports — workshops/exports/{format}: authentication, transport, download, export, PDF, or file response route
- workshops.reports.download — workshops/jobs/{job}/report: authentication, transport, download, export, PDF, or file response route
- workshops.official — workshops/jobs/{job}/{type}/{record}/pdf: authentication, transport, download, export, PDF, or file response route

## Measurement summary

| Viewport / cache | Samples | UI median / P95 | TTFB median / P95 | DCL median / P95 | Load median / P95 | Requests median / P95 | Bytes median / P95 |
|---|---:|---:|---:|---:|---:|---:|---:|
| desktop_cold | 162 | 743.05 / 1391.3 | 624.1 / 1288.8 | 1019.95 / 2081.5 | 1047.15 / 2084.8 | 9 / 9 | 1548165 / 1695844 |
| desktop_warm | 486 | 696.5 / 1424.8 | 612.7 / 1318.2 | 867.35 / 1788.5 | 891.15 / 1790.1 | 9 / 9 | 1151532 / 1299211 |
| mobile_cold | 162 | 867.85 / 1821 | 740 / 1699.6 | 1178.85 / 2457.6 | 1221 / 2497.8 | 9 / 9 | 1548165 / 1695844 |
| mobile_warm | 486 | 801.2 / 1872.1 | 717.4 / 1738.6 | 965.95 / 2154.5 | 1006.2 / 2212.7 | 9 / 9 | 1151532 / 1299211 |

## Fastest 10 pages

- Per-page P95 is withheld when fewer than 20 samples exist; cold and warm samples are reported separately. Aggregate P95 is shown only when that viewport/cache group has at least 20 samples.
| Page (route case) | Module | Desktop UI median (ms) | Mobile UI median (ms) | Rank max (ms) | Desktop TTFB (ms) | Mobile TTFB (ms) | Bottleneck signal | Optimization priority |
|---|---|---:|---:|---:|---:|---:|---|---|
| password.request (default) | M01 Organization | 270 | 239 | 270 | 53.3 | 45.4 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| login (default) | M01 Organization | 275 | 246 | 275 | 57.1 | 52.4 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| purchasing.requisitions.show (default) | M05 Purchasing | 509.1 | 535.3 | 535.3 | 430.9 | 450.1 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| inventory.adjustments (default) | M06 Inventory | 556.2 | 540.7 | 556.2 | 474.6 | 462.9 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| accounting.periods (default) | M11 Accounting | 559 | 546.7 | 559 | 474.8 | 496.4 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| operations.show (default) | M17 System | 568.3 | 503 | 568.3 | 489.5 | 435.5 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| treasury.movements.show (default) | M13 Treasury | 528.9 | 568.4 | 568.4 | 457 | 504.8 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| dashboard (default) | M17 System | 528 | 575.2 | 575.2 | 452.8 | 519.5 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| profile.edit (default) | M01 Organization | 468.9 | 584 | 584 | 394.2 | 539.2 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| workshops.dashboard (default) | M09 WorkshopRepairs | 516.4 | 593.6 | 593.6 | 449.9 | 513.8 | no outlier signal in measured baseline | No outlier-based optimization target identified. |

## Slowest 20 pages

| Page (route case) | Module | Desktop UI median (ms) | Mobile UI median (ms) | Rank max (ms) | Desktop TTFB (ms) | Mobile TTFB (ms) | Bottleneck signal | Optimization priority |
|---|---|---:|---:|---:|---:|---:|---|---|
| organization.users.show (default) | M01 Organization | 3283.2 | 2991.4 | 3283.2 | 3167.8 | 2908.4 | database query time is a major TTFB share (separate diagnostic sample) | Inspect repeated data SQL and slow queries for this route before changing code. |
| maintenance.work-orders.show (default) | M08 FieldMaintenance | 2646.3 | 2943.2 | 2943.2 | 2548 | 2862.2 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| maintenance.index (default) | M08 FieldMaintenance | 1066 | 2593.2 | 2593.2 | 973.1 | 2483.6 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| sales-orders.create (default) | M04 Sales | 719.3 | 2351.1 | 2351.1 | 612.7 | 2143.3 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| sales-orders.screen (amendment) | M04 Sales | 550.6 | 2348.1 | 2348.1 | 484.8 | 2187.9 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| inventory.document.create (opening) | M06 Inventory | 832.4 | 2162.7 | 2162.7 | 745.3 | 2057 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| inventory.document.create (receipt) | M06 Inventory | 619.1 | 2146.4 | 2146.4 | 567.9 | 2079.9 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| purchasing.receipts.create (default) | M05 Purchasing | 525.4 | 1872.2 | 1872.2 | 447.8 | 1782.5 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| projects.index (default) | M07 Projects | 735.7 | 1872.1 | 1872.1 | 647.6 | 1738.6 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| customers.index (default) | M03 Parties | 1869.5 | 1295.8 | 1869.5 | 1732.8 | 1204.3 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| sales-orders.edit (default) | M04 Sales | 689.1 | 1805.1 | 1805.1 | 587.1 | 1663.4 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| organization.branches (default) | M01 Organization | 1730 | 780.6 | 1730 | 1617.7 | 700.3 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| maintenance.requests (default) | M08 FieldMaintenance | 1621.7 | 1369.3 | 1621.7 | 1508 | 1301.9 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| inventory.warehouses (default) | M06 Inventory | 687.5 | 1526.7 | 1526.7 | 602.6 | 1351 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| projects.tasks (default) | M07 Projects | 704.1 | 1512.1 | 1512.1 | 652.2 | 1419.3 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| inventory.document.create (adjustment) | M06 Inventory | 1468 | 1152.5 | 1468 | 1369 | 1041.4 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| projects.financial (default) | M07 Projects | 591.1 | 1437.1 | 1437.1 | 522.4 | 1335.6 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| organization.roles (default) | M01 Organization | 1434.6 | 928.1 | 1434.6 | 1262.6 | 834.3 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| maintenance.schedule (default) | M08 FieldMaintenance | 1394.4 | 1055.1 | 1394.4 | 1252.8 | 975.4 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |
| maintenance.reports (default) | M08 FieldMaintenance | 1387.7 | 1043.4 | 1387.7 | 1306.6 | 988 | server-side response/rendering | Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards. |

## Pages not measured

- `projects.dashboard` (default, desktop/cold) — HTTP 500; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, desktop/warm) — HTTP 500; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, desktop/warm) — HTTP 500; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, desktop/warm) — HTTP 500; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, desktop/cold) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, desktop/cold) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, desktop/cold) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, desktop/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, mobile/cold) — HTTP 500; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, mobile/warm) — HTTP 500; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, mobile/warm) — HTTP 500; page readiness is not measured for non-200 responses.
- `projects.dashboard` (default, mobile/warm) — HTTP 500; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, mobile/cold) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.reports` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, mobile/cold) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `purchasing.returns` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, mobile/cold) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.
- `sales-returns.index` (default, mobile/warm) — HTTP 404; page readiness is not measured for non-200 responses.

## HTTP error responses

- products.index (default, desktop/cold): 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5; 404 GET http://127.0.0.1:51093/products/media/7; 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/11
- products.index (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/13; 404 GET http://127.0.0.1:51093/products/media/15; 404 GET http://127.0.0.1:51093/products/media/17; 404 GET http://127.0.0.1:51093/products/media/19; 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5
- products.index (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/13; 404 GET http://127.0.0.1:51093/products/media/15; 404 GET http://127.0.0.1:51093/products/media/17; 404 GET http://127.0.0.1:51093/products/media/19; 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5
- products.index (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/7; 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/13; 404 GET http://127.0.0.1:51093/products/media/15; 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/7; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5; 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/13
- products.show (default, desktop/cold): 404 GET http://127.0.0.1:51093/products/media/1
- products.show (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.show (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.show (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, desktop/cold): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, desktop/warm): 404 GET http://127.0.0.1:51093/products/media/1
- projects.dashboard (default, desktop/cold): 500 GET http://127.0.0.1:51093/projects/dashboard
- projects.dashboard (default, desktop/warm): 500 GET http://127.0.0.1:51093/projects/dashboard
- projects.dashboard (default, desktop/warm): 500 GET http://127.0.0.1:51093/projects/dashboard
- projects.dashboard (default, desktop/warm): 500 GET http://127.0.0.1:51093/projects/dashboard
- purchasing.reports (default, desktop/cold): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.reports (default, desktop/warm): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.reports (default, desktop/warm): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.reports (default, desktop/warm): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.returns (default, desktop/cold): 404 GET http://127.0.0.1:51093/purchasing/returns
- purchasing.returns (default, desktop/warm): 404 GET http://127.0.0.1:51093/purchasing/returns
- purchasing.returns (default, desktop/warm): 404 GET http://127.0.0.1:51093/purchasing/returns
- purchasing.returns (default, desktop/warm): 404 GET http://127.0.0.1:51093/purchasing/returns
- sales-returns.index (default, desktop/cold): 404 GET http://127.0.0.1:51093/sales-returns
- sales-returns.index (default, desktop/warm): 404 GET http://127.0.0.1:51093/sales-returns
- sales-returns.index (default, desktop/warm): 404 GET http://127.0.0.1:51093/sales-returns
- sales-returns.index (default, desktop/warm): 404 GET http://127.0.0.1:51093/sales-returns
- products.index (default, mobile/cold): 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5; 404 GET http://127.0.0.1:51093/products/media/7; 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/13; 404 GET http://127.0.0.1:51093/products/media/15
- products.index (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/17; 404 GET http://127.0.0.1:51093/products/media/19; 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5
- products.index (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/13; 404 GET http://127.0.0.1:51093/products/media/15; 404 GET http://127.0.0.1:51093/products/media/17; 404 GET http://127.0.0.1:51093/products/media/19; 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5
- products.index (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/13; 404 GET http://127.0.0.1:51093/products/media/15; 404 GET http://127.0.0.1:51093/products/media/17; 404 GET http://127.0.0.1:51093/products/media/1; 404 GET http://127.0.0.1:51093/products/media/3; 404 GET http://127.0.0.1:51093/products/media/5; 404 GET http://127.0.0.1:51093/products/media/7; 404 GET http://127.0.0.1:51093/products/media/11; 404 GET http://127.0.0.1:51093/products/media/9; 404 GET http://127.0.0.1:51093/products/media/13
- products.show (default, mobile/cold): 404 GET http://127.0.0.1:51093/products/media/1
- products.show (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.show (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.show (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, mobile/cold): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/1
- products.edit (default, mobile/warm): 404 GET http://127.0.0.1:51093/products/media/1
- projects.dashboard (default, mobile/cold): 500 GET http://127.0.0.1:51093/projects/dashboard
- projects.dashboard (default, mobile/warm): 500 GET http://127.0.0.1:51093/projects/dashboard
- projects.dashboard (default, mobile/warm): 500 GET http://127.0.0.1:51093/projects/dashboard
- projects.dashboard (default, mobile/warm): 500 GET http://127.0.0.1:51093/projects/dashboard
- purchasing.reports (default, mobile/cold): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.reports (default, mobile/warm): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.reports (default, mobile/warm): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.reports (default, mobile/warm): 404 GET http://127.0.0.1:51093/purchasing/reports
- purchasing.returns (default, mobile/cold): 404 GET http://127.0.0.1:51093/purchasing/returns
- purchasing.returns (default, mobile/warm): 404 GET http://127.0.0.1:51093/purchasing/returns
- purchasing.returns (default, mobile/warm): 404 GET http://127.0.0.1:51093/purchasing/returns
- purchasing.returns (default, mobile/warm): 404 GET http://127.0.0.1:51093/purchasing/returns
- sales-returns.index (default, mobile/cold): 404 GET http://127.0.0.1:51093/sales-returns
- sales-returns.index (default, mobile/warm): 404 GET http://127.0.0.1:51093/sales-returns
- sales-returns.index (default, mobile/warm): 404 GET http://127.0.0.1:51093/sales-returns
- sales-returns.index (default, mobile/warm): 404 GET http://127.0.0.1:51093/sales-returns

## Query profiling

- Diagnostic request database time: **8618.3 ms** across 20 requests (one per selected slow page).
- Repeated auth SQL: 12468 executions / 6573.77 ms (76.28% of measured DB time).
- Repeated non-auth/data SQL: 1080 executions / 660.47 ms (7.66% of measured DB time).
- Repeated data SQL is a candidate for N+1 investigation, not proof of an N+1 defect. Per-page slow and repeated fingerprints remain in `query_profile` in the raw JSON.

| Page | Viewport | DB time (ms), diagnostic run | Repeated auth SQL | Repeated data SQL |
|---|---|---:|---:|---:|
| organization.users.show (default) | desktop | 2037.91 | 3401 | 309 |
| maintenance.work-orders.show (default) | desktop | 831.25 | 1364 | 44 |
| maintenance.requests (default) | desktop | 701.84 | 1171 | 43 |
| maintenance.reports (default) | desktop | 481.78 | 732 | 42 |
| maintenance.schedule (default) | desktop | 472.8 | 732 | 42 |
| maintenance.index (default) | desktop | 465.96 | 732 | 42 |
| organization.branches (default) | desktop | 438.64 | 331 | 38 |
| sales-orders.screen (amendment) | desktop | 286.17 | 354 | 51 |
| projects.financial (default) | desktop | 282.8 | 351 | 47 |
| projects.tasks (default) | desktop | 280.88 | 350 | 39 |

## Bottlenecks and optimization priorities

- **Measured signal:** the slowest page cases are ranked by the slower of desktop/mobile warm median UI-ready times; their per-case priority identifies the first inspection target.
- **Measured signal:** routes with high request counts or transferred bytes should be checked for repeated Livewire updates, unbundled assets, and oversized table payloads.
- **Observed query hotspot:** `organization.users.show` used 3,724 SQL statements / 2,037.91 ms in the diagnostic request. The page mounts `UserAccessSection` twice under hidden tabs (`resources/views/organization/user-details.blade.php`); that component walks the permission catalog and evaluates branch/warehouse scopes even in history-only mode (`app/Modules/Organization/Presentation/UserAccessSection.php`). Consider lazy-mounting hidden tabs and building one per-request permission/scope snapshot while retaining authorization checks. This is a source-supported optimization candidate; no code was changed.
- **Query mix:** repeated auth SQL accounted for about 76% of profiled database time; repeated non-auth/data SQL about 7.7%. Repeated statements identify candidates for inspection and do not alone prove an N+1 defect. SQL profiling for the selected 20 pages is complete; Pulse is not installed.
- **No source optimization was performed in this measurement pass.**

## Artifacts

- `ERP_PAGE_SPEED_RESULTS.json` — summaries plus raw samples.
- `ERP_PAGE_SPEED_RAW.json` — raw per-navigation measurements.
- runs/mvwmlhzx-nba600/ — preserved raw page samples and separate SQL diagnostic samples for this run.
- `ERP_SLOW_PAGES.csv` — ranked per-route-case summary with desktop/mobile medians, failure count, bottleneck, and suggested optimization. Individual navigation samples are in `ERP_PAGE_SPEED_RAW.csv` and JSON.
- `ERP_PAGE_SPEED_DASHBOARD.html` — static dashboard for the full route set.

## Final coverage and reliability notes

- GET route inventory: 210 total (194 named, 16 unnamed). Eligible page routes: 146 including the 2 measured guest pages; expanded route cases: 166.
- Cases with 3 measured warm samples on both desktop and mobile: 162/166.
- Primary full-page timing samples not measured: 32; incomplete cases and exact reasons are in JSON.
- Invalid auth-context records excluded: 4 navigation attempts redirected from guest-only pages, plus 1 unattempted transition placeholder. Anonymous guest samples are used instead. Raw records are preserved separately.
- SPA transitions: 36/36 passed across reachable shell links; 321 route/viewport cases had no direct dashboard link and were not attempted. These are not full-page failures.
- Full-document page samples: 1296 measured (1272 healthy, 24 degraded); 32 full-navigation samples had no valid timing. SPA is separate: 36/36 transitions passed.
- HTTP error responses recorded: at least 115 (lower bound; per-sample HTTP error lists are capped), including at least 83 asset and 32 document responses. The exact count of affected samples is separately reported.
- Product media error diagnosis: the initial PHP router did not expose `LARAVEL_STORAGE_PATH` through Laravel’s `$_ENV`/`$_SERVER`, so it served from the repository storage root and produced pre-fix media 404s. The test router now maps the isolated fixture storage root. A targeted fresh-auth probe then reached the files but synthetic demo filenames containing `/` triggered Symfony HeaderUtils’ “filename and fallback cannot contain the "/" and "\" characters” error. This is data-dependent on those synthetic names; it does not establish that ordinary uploaded product media fails. Main page UI timings remain valid and product media failures are reported as degraded asset health; no app code or fixture names were changed.
- Runtime: APP_ENV=testing; APP_DEBUG=false; CACHE_STORE=array; SESSION_DRIVER=file; QUEUE_CONNECTION=sync; PHP 8.4.21; Microsoft Edge 156.0.4314.50.
- The `projects.dashboard` page returned HTTP 500: Flux component `icon.قيد التنفيذ` does not exist, traced to the `in_progress` stat-card icon mapping in `resources/views/projects/dashboard.blade.php`.
- `security.edit` was entered after the harness completed Fortify’s ordinary password-confirmation form with its synthetic account; no 2FA or reset workflow was used.
- Source fingerprint SHA-256: start 4ec6a13e9c6e1e8b09e1258a64ce999f1329a0c6c5c5210f5d34d356cd97cb02; end 4ec6a13e9c6e1e8b09e1258a64ce999f1329a0c6c5c5210f5d34d356cd97cb02; unchanged: true.
- No application optimization was applied. Timings use a local PHP server, no network throttling, synthetic data, and warm PostgreSQL/PHP runtime; cold means browser cache/context only, not PHP/process/PostgreSQL/OS cold start. Transferred bytes sum Playwright responseBodySize (headers excluded).
