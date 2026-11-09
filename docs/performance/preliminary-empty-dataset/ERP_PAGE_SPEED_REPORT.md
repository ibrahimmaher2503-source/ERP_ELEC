# ERP Page Speed Report

Generated: 2026-10-31T16:13:22.786Z

## Scope and conditions

- Named GET routes discovered: **210**
- Candidate authenticated routes / expanded cases: **147 / 165**
- GET routes excluded by the explicit rules below: **65**
- Route/viewport summaries measured: **456**
- Raw samples: **1080**
- Pages/samples not measured: **132**
- Rendered timings retained: **948 healthy + 0 degraded**; degraded asset HTTP errors do not erase valid page timings.
- Samples with HTTP error responses (4xx/5xx, including assets): **32** (32 responses). These are recorded separately from JavaScript and transport failures.
- Browser: Microsoft Edge via Playwright; serial navigation; no production target.
- Server: local PHP built-in server; no network throttling configured.
- Source fingerprint SHA-256: start null; end 4ec6a13e9c6e1e8b09e1258a64ce999f1329a0c6c5c5210f5d34d356cd97cb02; unchanged: null.
- Database: isolated PostgreSQL erp_page_speed_test on 127.0.0.1:55461.
- Viewports: desktop 1440×900; mobile 390×844.
- Warm runs: one unreported cache warm-up plus 3 measured repetitions per route/viewport; one separate cold browser-cache sample where a route resolved.
- SPA route transitions attempted: 36; passed: 36. Each reachable wire:navigate link is sampled three times; unavailable links are reported explicitly.
- Cold means an empty browser context/cache. Server/runtime caches stay active; this is not a process/OS cold-start benchmark.
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
- password.request — forgot-password: authentication, transport, download, export, PDF, or file response route
- inventory.export — inventory/export/{entity}: authentication, transport, download, export, PDF, or file response route
- (unnamed) — livewire-e72dc428/css/{component}.css: unnamed GET route
- (unnamed) — livewire-e72dc428/css/{component}.global.css: unnamed GET route
- (unnamed) — livewire-e72dc428/js/{component}.js: unnamed GET route
- (unnamed) — livewire-e72dc428/livewire.csp.min.js.map: unnamed GET route
- (unnamed) — livewire-e72dc428/livewire.min.js: unnamed GET route
- (unnamed) — livewire-e72dc428/livewire.min.js.map: unnamed GET route
- livewire.preview-file — livewire-e72dc428/preview-file/{filename}: authentication, transport, download, export, PDF, or file response route
- login — login: authentication, transport, download, export, PDF, or file response route
- maintenance.documents — maintenance/documents/{document}: authentication, transport, download, export, PDF, or file response route
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
- password.confirm — user/confirm-password: authentication, transport, download, export, PDF, or file response route
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
| desktop_cold | 114 | 690 / 1072.5 | 587.85 / 950.6 | 968.65 / 1756.4 | 970.75 / 1760.4 | 9 / 9 | 1544251 / 1622763 |
| desktop_warm | 342 | 626.05 / 976.9 | 548.5 / 885.5 | 813.1 / 1304 | 815.9 / 1308.8 | 9 / 10 | 1149239.5 / 1226130 |
| mobile_cold | 114 | 614.05 / 1346 | 518.95 / 1212.4 | 857.4 / 1724.9 | 860 / 1757.3 | 9 / 9 | 1544251 / 1622819 |
| mobile_warm | 342 | 590.1 / 1178.3 | 523.75 / 1054.8 | 753.5 / 1541.8 | 757.7 / 1543.8 | 9 / 10 | 1149216 / 1226186 |

## Fastest 10 pages

- Per-page P95 is withheld when fewer than 20 samples exist; cold and warm samples are reported separately. Aggregate P95 is shown only when that viewport/cache group has at least 20 samples.
| Page (route case) | Module | Desktop UI median (ms) | Mobile UI median (ms) | Rank max (ms) | Desktop TTFB (ms) | Mobile TTFB (ms) | Bottleneck signal | Optimization priority |
|---|---|---:|---:|---:|---:|---:|---|---|
| password.request (default) | M01 Organization | 297 | 290 | 297 | 68.4 | 79.5 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| login (default) | M01 Organization | 341 | 323 | 341 | 95.5 | 68.7 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| operations.import (suppliers) | M17 System | 490.2 | 475.4 | 490.2 | 414.2 | 418.7 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| purchasing.rfqs.index (default) | M05 Purchasing | 497.5 | 484.8 | 497.5 | 439.7 | 422.2 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| purchasing.requisitions.index (default) | M05 Purchasing | 434 | 503.4 | 503.4 | 368.7 | 426.8 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| purchasing.orders.index (default) | M05 Purchasing | 518.3 | 481.7 | 518.3 | 468.2 | 412.7 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| inventory.counts (default) | M06 Inventory | 526.8 | 523.3 | 526.8 | 457.2 | 476.6 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| financial.credit-notes (default) | M12 ReceivablesPayables | 534.1 | 498.1 | 534.1 | 462.7 | 454.8 | request fan-out or Livewire/assets | Inspect Livewire update count and asset request fan-out. |
| purchasing.requisitions.create (default) | M05 Purchasing | 547 | 505.9 | 547 | 508.7 | 429.1 | no outlier signal in measured baseline | No outlier-based optimization target identified. |
| financial.supplier-invoices (default) | M12 ReceivablesPayables | 507.3 | 547.7 | 547.7 | 459 | 501.3 | no outlier signal in measured baseline | No outlier-based optimization target identified. |

## Slowest 20 pages

| Page (route case) | Module | Desktop UI median (ms) | Mobile UI median (ms) | Rank max (ms) | Desktop TTFB (ms) | Mobile TTFB (ms) | Bottleneck signal | Optimization priority |
|---|---|---:|---:|---:|---:|---:|---|---|
| organization.users.show (default) | M01 Organization | 2768.6 | 2095.2 | 2768.6 | 2656.1 | 2012.3 | server-side response/rendering | No outlier-based optimization target identified. |
| suppliers.categories (default) | M03 Parties | 668.6 | 1725.9 | 1725.9 | 575.3 | 1637.8 | server-side response/rendering | No outlier-based optimization target identified. |
| workshops.settings (default) | M09 WorkshopRepairs | 500.1 | 1385.9 | 1385.9 | 436.3 | 1260.6 | server-side response/rendering | No outlier-based optimization target identified. |
| workshops.jobs (default) | M09 WorkshopRepairs | 1138.5 | 1308.6 | 1308.6 | 1032.3 | 1219.7 | server-side response/rendering | No outlier-based optimization target identified. |
| suppliers.index (default) | M03 Parties | 714.5 | 1256.9 | 1256.9 | 626.5 | 1187.3 | server-side response/rendering | No outlier-based optimization target identified. |
| workshops.reports (default) | M09 WorkshopRepairs | 495.6 | 1182.9 | 1182.9 | 411.6 | 1054.8 | server-side response/rendering | No outlier-based optimization target identified. |
| suppliers.create (default) | M03 Parties | 542.7 | 1101.1 | 1101.1 | 487 | 966.2 | server-side response/rendering | No outlier-based optimization target identified. |
| organization.roles.show (default) | M01 Organization | 1091.5 | 929.5 | 1091.5 | 1002.4 | 769.5 | server-side response/rendering | No outlier-based optimization target identified. |
| workshops.intakes.create (default) | M09 WorkshopRepairs | 1060.2 | 819.3 | 1060.2 | 985 | 732.4 | server-side response/rendering | No outlier-based optimization target identified. |
| maintenance.dashboard (default) | M08 FieldMaintenance | 564.7 | 1040.2 | 1040.2 | 510.6 | 952 | server-side response/rendering | No outlier-based optimization target identified. |
| purchasing.dashboard (default) | M05 Purchasing | 976.9 | 638.7 | 976.9 | 860.4 | 554.5 | server-side response/rendering | No outlier-based optimization target identified. |
| organization.roles (default) | M01 Organization | 963.8 | 796.6 | 963.8 | 860.7 | 736.7 | server-side response/rendering | No outlier-based optimization target identified. |
| profile.edit (default) | M01 Organization | 634.6 | 942.3 | 942.3 | 567.6 | 865.9 | server-side response/rendering | No outlier-based optimization target identified. |
| customers.edit (default) | M03 Parties | 904.5 | 532 | 904.5 | 820.7 | 491.3 | server-side response/rendering | No outlier-based optimization target identified. |
| tenders.dashboard (default) | M07 Projects (Tenders) | 549.8 | 892.5 | 892.5 | 474.9 | 785.3 | server-side response/rendering | No outlier-based optimization target identified. |
| treasury.collections (default) | M13 Treasury | 522.8 | 851.4 | 851.4 | 448.7 | 746.6 | server-side response/rendering | No outlier-based optimization target identified. |
| accounting.setup (default) | M11 Accounting | 843.2 | 647.8 | 843.2 | 758.3 | 572.8 | server-side response/rendering | No outlier-based optimization target identified. |
| treasury.payments (default) | M13 Treasury | 515.3 | 839.4 | 839.4 | 477.6 | 739 | server-side response/rendering | No outlier-based optimization target identified. |
| accounting.trial-balance (default) | M11 Accounting | 826.4 | 535.6 | 826.4 | 733.8 | 458 | server-side response/rendering | No outlier-based optimization target identified. |
| suppliers.import (default) | M03 Parties | 499.6 | 821.9 | 821.9 | 429.9 | 752.8 | server-side response/rendering | No outlier-based optimization target identified. |

## Pages not measured

- `operations.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `operations.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `financial.invoices.show` (customer, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `financial.invoices.show` (customer, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `financial.invoices.show` (supplier, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `financial.invoices.show` (supplier, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (opening, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (opening, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (receipt, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (receipt, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (issue, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (issue, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (transfer, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (transfer, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (adjustment, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (adjustment, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (count, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `inventory.document.show` (count, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `maintenance.requests.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `maintenance.requests.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `maintenance.work-orders.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `maintenance.work-orders.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `maintenance.report` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `maintenance.report` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `products.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `products.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `products.edit` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `products.edit` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `products.prices` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `products.prices` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.budget` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.budget` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.closure` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.closure` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.documents` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.documents` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.financial` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.financial` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.operations` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.operations` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.tasks` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `projects.tasks` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.offers.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.offers.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.orders.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.orders.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.receipts.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.receipts.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.requisitions.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.requisitions.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.rfqs.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `purchasing.rfqs.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.acceptance` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.acceptance` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.approval` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.approval` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.convert` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.convert` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.edit` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `quotations.edit` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.edit` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.edit` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (review, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (review, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (amendment, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (amendment, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (delivery, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (delivery, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (invoices, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (invoices, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (closure, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `sales-orders.screen` (closure, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `suppliers.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `suppliers.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `suppliers.edit` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `suppliers.edit` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `tenders.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `tenders.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `tenders.show` (financial, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `tenders.show` (financial, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `treasury.movements.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `treasury.movements.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `workshops.intakes.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `workshops.intakes.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
- `workshops.jobs.show` (unresolved-parameter, desktop/unresolved) — No valid isolated fixture ID for required route parameter
- `workshops.jobs.show` (unresolved-parameter, mobile/unresolved) — No valid isolated fixture ID for required route parameter
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
- `security.edit` (default, desktop/cold) — Password-confirmation flow not completed by harness
- `security.edit` (default, desktop/warm) — Password-confirmation flow not completed by harness
- `security.edit` (default, desktop/warm) — Password-confirmation flow not completed by harness
- `security.edit` (default, desktop/warm) — Password-confirmation flow not completed by harness
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
- `security.edit` (default, mobile/cold) — Password-confirmation flow not completed by harness
- `security.edit` (default, mobile/warm) — Password-confirmation flow not completed by harness
- `security.edit` (default, mobile/warm) — Password-confirmation flow not completed by harness
- `security.edit` (default, mobile/warm) — Password-confirmation flow not completed by harness

## HTTP error responses

- projects.dashboard (default, desktop/cold): 500 GET http://127.0.0.1:57180/projects/dashboard
- projects.dashboard (default, desktop/warm): 500 GET http://127.0.0.1:57180/projects/dashboard
- projects.dashboard (default, desktop/warm): 500 GET http://127.0.0.1:57180/projects/dashboard
- projects.dashboard (default, desktop/warm): 500 GET http://127.0.0.1:57180/projects/dashboard
- purchasing.reports (default, desktop/cold): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.reports (default, desktop/warm): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.reports (default, desktop/warm): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.reports (default, desktop/warm): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.returns (default, desktop/cold): 404 GET http://127.0.0.1:57180/purchasing/returns
- purchasing.returns (default, desktop/warm): 404 GET http://127.0.0.1:57180/purchasing/returns
- purchasing.returns (default, desktop/warm): 404 GET http://127.0.0.1:57180/purchasing/returns
- purchasing.returns (default, desktop/warm): 404 GET http://127.0.0.1:57180/purchasing/returns
- sales-returns.index (default, desktop/cold): 404 GET http://127.0.0.1:57180/sales-returns
- sales-returns.index (default, desktop/warm): 404 GET http://127.0.0.1:57180/sales-returns
- sales-returns.index (default, desktop/warm): 404 GET http://127.0.0.1:57180/sales-returns
- sales-returns.index (default, desktop/warm): 404 GET http://127.0.0.1:57180/sales-returns
- projects.dashboard (default, mobile/cold): 500 GET http://127.0.0.1:57180/projects/dashboard
- projects.dashboard (default, mobile/warm): 500 GET http://127.0.0.1:57180/projects/dashboard
- projects.dashboard (default, mobile/warm): 500 GET http://127.0.0.1:57180/projects/dashboard
- projects.dashboard (default, mobile/warm): 500 GET http://127.0.0.1:57180/projects/dashboard
- purchasing.reports (default, mobile/cold): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.reports (default, mobile/warm): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.reports (default, mobile/warm): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.reports (default, mobile/warm): 404 GET http://127.0.0.1:57180/purchasing/reports
- purchasing.returns (default, mobile/cold): 404 GET http://127.0.0.1:57180/purchasing/returns
- purchasing.returns (default, mobile/warm): 404 GET http://127.0.0.1:57180/purchasing/returns
- purchasing.returns (default, mobile/warm): 404 GET http://127.0.0.1:57180/purchasing/returns
- purchasing.returns (default, mobile/warm): 404 GET http://127.0.0.1:57180/purchasing/returns
- sales-returns.index (default, mobile/cold): 404 GET http://127.0.0.1:57180/sales-returns
- sales-returns.index (default, mobile/warm): 404 GET http://127.0.0.1:57180/sales-returns
- sales-returns.index (default, mobile/warm): 404 GET http://127.0.0.1:57180/sales-returns
- sales-returns.index (default, mobile/warm): 404 GET http://127.0.0.1:57180/sales-returns

## Query profiling

- Diagnostic request database time: **0 ms** across 20 requests (one per selected slow page).
- Repeated auth SQL: 0 executions / 0 ms (0% of measured DB time).
- Repeated non-auth/data SQL: 0 executions / 0 ms (0% of measured DB time).
- Repeated data SQL is a candidate for N+1 investigation, not proof of an N+1 defect. Per-page slow and repeated fingerprints remain in `query_profile` in the raw JSON.

| Page | Viewport | DB time (ms), diagnostic run | Repeated auth SQL | Repeated data SQL |
|---|---|---:|---:|---:|
| organization.users.show (default) | desktop | 0 | 0 | 0 |
| suppliers.categories (default) | desktop | 0 | 0 | 0 |
| workshops.settings (default) | desktop | 0 | 0 | 0 |
| workshops.jobs (default) | desktop | 0 | 0 | 0 |
| suppliers.index (default) | desktop | 0 | 0 | 0 |
| workshops.reports (default) | desktop | 0 | 0 | 0 |
| suppliers.create (default) | desktop | 0 | 0 | 0 |
| organization.roles.show (default) | desktop | 0 | 0 | 0 |
| workshops.intakes.create (default) | desktop | 0 | 0 | 0 |
| maintenance.dashboard (default) | desktop | 0 | 0 | 0 |

## Bottlenecks and optimization priorities

- **Measured signal:** the slowest page cases are ranked by the slower of desktop/mobile warm median UI-ready times; their per-case priority identifies the first inspection target.
- **Measured signal:** routes with high request counts or transferred bytes should be checked for repeated Livewire updates, unbundled assets, and oversized table payloads.
- **Next step:** run Laravel query tracing or Pulse on the slowest five routes in the same isolated environment, then repeat this audit for before/after comparison.
- **No source optimization was performed in this measurement pass.**

## Artifacts

- `ERP_PAGE_SPEED_RESULTS.json` — summaries plus raw samples.
- `ERP_PAGE_SPEED_RAW.json` — raw per-navigation measurements.
- runs/mvwjuszf-zp8b09/ — preserved raw page samples and separate SQL diagnostic samples for this run.
- `ERP_SLOW_PAGES.csv` — sortable raw measurement table.
- `ERP_PAGE_SPEED_DASHBOARD.html` — static dashboard for the full route set.
