# Final authenticated UI route audit — 2026-10-31

## Coverage and method

This is a read-only browser audit of the current working tree. Chromium logged in through the actual `/login` flow as a synthetic, verified `Administrator` account in a disposable PostgreSQL clone. That account had explicit scopes for the clone's 17 active branches and 31 active warehouses. The clone was copied from the separate read-only PostgreSQL source on port 55448 and migrated locally through migration 197. The clone database ran on port 55484; the primary HTTP server ran on 127.0.0.1:9885, the tab sweep on 9858, and the latest targeted retest on 9887. Port 55475, the development database, and the operational shared database were not used or modified.

The primary crawl timestamp is 2026-10-31 14:12:58.984Z and used the then-current `app-B1CmHBgX.css` manifest asset. After `resources/css/app.css` and `public/build/manifest.json` changed, the Customer/Supplier/Tender follow-up ran at 14:27:47.848Z using `app-D3crliJD.css` and `app-BvRk9kiK.js`; the direct tab sweep ran at 14:24:02.565Z. The full 144-route crawl was not repeated after that asset update.

The route inventory contained 175 authenticated GET endpoints. The crawler visited all 144 authenticated HTML page routes, including owner list, dashboard, create, detail, and settings pages. The remaining GET endpoints are document, PDF, and export handlers, which are recorded in the [complete route inventory](route-inventory.json) but are not HTML screens. Each page was rendered at 1440×900, 1366×768, and 390×844. The browser used `ar-EG`, `Africa/Cairo`, and the built assets in `public/build`. It checked HTTP status, shell/main and heading presence, RTL direction, horizontal overflow, first keyboard focus, disabled/hidden action counts, JavaScript page errors, console failures, and asset responses. No action forms were submitted.

Unique primary-route outcomes: 124 HTTP 200; 18 HTTP 404 (15 not tested because source records are absent, and 3 release-gated); 1 HTTP 500 defect; 1 HTTP 422 expected state guard with generic error UX. The separate Customer/Supplier mobile issue is HTTP 200 with horizontal overflow, not an HTTP route exception.

**Do not treat this audit as a 100% pass.** The route-by-route result is [audit-results.json](run-9403D976D9/audit-results.json), with exact paths, status and screenshot filename at each viewport. That run captured 441 screenshots. Detail tab states were then opened as direct read-only GETs and separately captured in [tab-sweep/results.json](tab-sweep/results.json) (18 tab URLs, 54 screenshots). The post-fix Tender partial retest is in [targeted-postfix/targeted-results.json](targeted-postfix/targeted-results.json) (five routes at three viewports, 15 screenshots).

The source clone had no existing `user_roles` memberships, so the isolated fixture created one verified test account with an explicit `Administrator` role row and the active branch/warehouse scopes needed for the crawl. The browser then logged in through the actual login flow and the route checks used application authorization. No role membership, scope, company record, or other owner data was changed on either source database. No operational business records, postings, authentication history, or security history were fabricated. Existing source records were used for Customer 1, Supplier 1, Sales Orders 1–2, and Maintenance Work Order 76. No tender, quotation, purchasing, workshop intake/job, inventory document, maintenance request, or data-operation record was added to make a detail route render.

## Findings

### 1. Projects dashboard returns HTTP 500

`GET /projects/dashboard` returns HTTP 500 at all three viewports. The server log records `Flux component [icon.قيد التنفيذ] does not exist.` In [dashboard.blade.php](../../../../resources/views/projects/dashboard.blade.php), the `in_progress` stat card passes the Arabic status text `قيد التنفيذ` as both its `icon` and `tone`; [stat-card.blade.php](../../../../resources/views/components/erp/stat-card.blade.php) renders `icon` as a Flux icon name.

Screenshots: [1440×900](run-9403D976D9/082-projects-dashboard-a86e9fd-1440x900.png), [1366×768](run-9403D976D9/082-projects-dashboard-a86e9fd-1366x768.png), [390×844](run-9403D976D9/082-projects-dashboard-a86e9fd-390x844.png).

### 2. Customer and Supplier detail overview overflow on mobile

`GET /customers/1` and `GET /suppliers/1` render HTTP 200 at all sizes and remain RTL, but at 390×844 both `documentElement.scrollWidth` and `body.scrollWidth` are 408px, 18px wider than the viewport. The DOM probe found the off-screen `<ui-toast-group>` box at x=24–408px (384px wide); the mobile screenshots also show the detail heading and tabs clipped at the right edge. Both routes measure 390px at the two desktop viewports. The 54 direct tab checks show the same 18px overflow only on the Customer and Supplier overview tab at mobile; their other tabs and all Maintenance Work Order tabs fit.

Screenshots: [Customer overview mobile](run-9403D976D9/015-customers-show-fb8d93a-390x844.png), [Supplier overview mobile](run-9403D976D9/123-suppliers-show-9029d4e-390x844.png). Per-element DOM measurements are in [targeted-results.json](targeted-postfix/targeted-results.json).

### 3. Approved Sales Order edit path returns a generic 422 page

`GET /sales-orders/1/edit` returns HTTP 422 at all three viewports with Symfony's generic “422 Unprocessable Content” error page. The inspected source order is already approved (version 3); Sales Orders 1 and 2 are both approved, so the non-editable-state guard is expected. This is not evidence of an order mutation failure. The user-facing error is generic rather than explaining why editing is unavailable.

Screenshot: [1440×900](run-9403D976D9/115-sales-orders-edit-0c49d47-1440x900.png).

### 4. Detail routes without source records were not testable

Fifteen detail URLs returned 404 because the corresponding source tables in the cloned database contained no rows: `/data-operations/1`, `/inventory/documents/receipt/1`, `/maintenance/requests/1`, `/purchasing/offers/1`, `/purchasing/requisitions/1`, `/purchasing/rfqs/1`, `/quotations/1`, `/quotations/1/acceptance`, `/quotations/1/approval`, `/quotations/1/convert`, `/quotations/1/edit`, `/quotations/1/pdf`, `/tenders/1`, `/workshops/intakes/1`, and `/workshops/jobs/1`. These are **not tested detail screens**, not passed screens or reported defects. No synthetic business records were created to fill the empty tables.

Three more routes, `/purchasing/reports`, `/purchasing/returns`, and `/sales-returns`, returned 404 under the current `release.v2_enabled=false` gate. Treat these as release-gated screens, not implementation failures.

## Tender partial retest after the current source fix

`/tenders`, `/tenders/dashboard`, `/tenders/reports`, and `/tenders/create` returned HTTP 200, rendered RTL, and had no horizontal overflow at all three viewports. The authenticated create screen is captured at [1440×900](targeted-postfix/-tenders-create-1440x900.png), [1366×768](targeted-postfix/-tenders-create-1366x768.png), and [390×844](targeted-postfix/-tenders-create-390x844.png). `/tenders/1` returned 404 in all sizes because the cloned source has no tender row. No form was submitted, so no tender business data was created.

All 18 Customer, Supplier, and existing Maintenance Work Order tab URLs returned HTTP 200 at all three viewport sizes. The Maintenance Work Order details use actual record 76 and include details, assignments, visits, parts, labor, costs, completion, billing, documents, and history. See the tab-sweep JSON for every exact path and screenshot.

## Browser errors and accessibility checks

There were no uncaught JavaScript `pageerror` events and no failed Vite CSS/JavaScript/font asset responses. The 63 raw browser error records in `audit-results.json` are 60 failed-request console entries caused by the expected/not-testable HTTP 404, the guarded 422, or the Projects dashboard 500 repeated at three sizes, plus the dashboard's three HTTP 500 responses. They are not 63 distinct JavaScript or asset failures.

All 124 route pages that returned HTTP 200 were RTL at every viewport. The raw RTL-failure counter of 60 consists only of default LTR error documents for the 18 404 routes, one 422 route, and the one 500 route, each captured at three sizes. There were no redirects to login. First keyboard focus was sampled on each primary route; no business actions or submit controls were activated.

## Complete artifacts

- [Primary route results](run-9403D976D9/audit-results.json)
- [Primary run summary](run-9403D976D9/audit-summary.txt)
- [Customer/Supplier mobile DOM probe and Tender retest](targeted-postfix/targeted-results.json)
- [Customer/Supplier/Maintenance tab route results](tab-sweep/results.json)
- [Vite and font server log](run-9403D976D9/server.log.redacted.txt)
- [Isolated application error log](../../../../.tmp/final-ui-audit-20261031/storage/logs/laravel.log)

The other run folders (`run-364B82994E`, `run-59F15F5C18`, `run-A8A9797F4C`, `run-DD28D8F93F`, and `run-E241FC817A`) contain harness-startup diagnostics only; `run-9403D976D9` is the completed primary route audit.
