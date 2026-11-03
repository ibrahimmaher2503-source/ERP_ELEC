# ERP UI unification acceptance

**ERP UI UNIFICATION COMPLETE**

Completion scope: implemented page-family migration and the normal-state/representative interaction acceptance below. It is not a claim of production activation or exhaustive financial/state/assistive-technology certification.

2026-11-01 local checkpoint. Implementation and acceptance are deliberately separate. The migration is local; no operational data, business Action, permission rule, financial/stock calculation, route, migration or dependency is changed by this UI work.

## Delivered

- One semantic token source and Flux control system; party/supplier navy/teal accent overrides removed.
- Five canonical page archetypes, mapping all 145 protected named GET pages and retaining 166 historical route/variant cases.
- Shared heading/breadcrumb, authorized primary/secondary action placement, compact mobile navigation and summaries, visible search plus collapsible secondary filters.
- Sales Orders duplicate KPI cards removed; status counts/filtering/export retained; first record appears in the initial 390px viewport. Desktop retains its dense table.
- Field-preserving responsive records adopted across organization, products, customers/suppliers, sales/quotations, purchasing, inventory documents, projects, maintenance, workshops, treasury and accounting report families. Original amounts, dates, identifiers and Actions remain.
- Existing owner details/forms/dashboards reuse the common heading/surfaces/states. Editable or spanning financial/stock grids keep all columns in named keyboard-accessible scroll regions.
- Product details' actual tablet/mobile overflow fixed at the grid track; final built utility is present and all five viewport replacement captures pass.
- Branch create visibility remains independent of company-view authority, proven by a dedicated regression test. Mobile party table header names remain accessible when header buttons are moved to mobile tools.

Exact application/build files and supporting test changes: [migration progress](ERP_UI_MIGRATION_PROGRESS.md). Component contracts: [architecture](ERP_UI_ARCHITECTURE.md), [design system](ERP_DESIGN_SYSTEM.md), [token reference](UI_SYSTEM.md). Page-by-page adoption/acceptance: [matrix](ERP_PAGE_ARCHETYPE_MATRIX.md). [Before/after gallery](ERP_UI_BEFORE_AFTER_GALLERY.html).

## Verification completed

| Gate | Actual result | Evidence |
|---|---|---|
| Vite | PASS; 22 modules, final CSS 462.96KB / 51.05KB gzip | `public/build/manifest.json`, `app-BndKEvZV.css` |
| Blade compilation | PASS | `php artisan view:cache` |
| Scoped whitespace | PASS | `git diff --check -- resources/css/app.css resources/views` |
| Laravel | 57 tests PASS, 293 assertions, final recorded run 31.95s | private `.tmp/ui-acceptance/phpunit.log` |
| Existing safe QA | 21/21 scripts PASS, 875 explicit assertions | private `.tmp/ui-acceptance/safe-qa.log` |
| Scoped Pint | PASS with formatter CLI OPcache disabled | FoundationTest and OptimizationRegressionTest |
| Product details overflow replacement | PASS at 1440/1366/768/390/360 | `eff88bb6-CBOIam_T` screenshots in the main receipt |
| Current route availability | Project dashboard / password confirmation 200; three V2 gates 404 | `docs/qa/design-consistency/2026-10-31T23-34-48-598Z/after/route-availability-probes.json` |

Tests ran on a newly created marker-owned PostgreSQL 55462, exact `erp_elec_new_test`. The development database and existing 55461 browser fixtures were preserved. The existing test infrastructure's 55460 path remains supported; no destructive reset of that cluster was used. Earlier failed test/visual receipts are retained, with explicit replacements.

Current visual/interaction receipts are under `docs/qa/design-consistency/2026-10-31T23-36-31-311Z/after/`. Capture resumes preserve unchanged pages and tag source/build boundaries; no complete screenshot sweep or historical BEFORE timing campaign is silently repeated. Automated checks and actual human screenshot review must not be conflated.

The lead directly reviewed rendered examples of all five archetypes, including Sales Orders and Quotations mobile registers, customer creation, home workspace, workshop intake details, accounting income statement, aging cards and the repaired product tablet detail. Remaining pages are not called visually accepted solely because HTTP/overflow assertions pass.

## Complete page coverage and final browser performance

The active deduplicated index contains **164 page/state cases × five viewports = 820 screenshots**, covering **142 protected named pages plus two public authentication forms**. The registry has 145 protected names; the other three are intentionally V2-gated and return expected 404. The 166 historical cases plus newly eligible password confirmation are reconciled as 164 accessible cases and three gates. Two original fixture/default entries are represented by real protected states: own security settings after genuine password confirmation, and an actual owner-created operation preview. See `route-case-coverage.json` in the main receipt.

All latest accepted normal-page checks pass. Viewports: 1440×900, 1366×768, 768×1024, 390×844, 360×800. Checks cover HTTP, actual content readiness for non-default Livewire cases, RTL, labels, page overflow, broken images, browser failures and permitted navigation. Captured pages are not automatically given individual visual design approval: the matrix explicitly separates complete browser checks from human archetype/reference review.

**37 integrated interaction sessions** pass, including filter collapse/reopen/value preservation/reset, exact Sales 10/Purchasing 9 fields, nine aging values, all Product/Customer/Supplier catalogue fields (11/9/12), catalogue selection/cancel and More actions, actual product pagination/search/sort reset, SPA detail/back, navigation search/Escape/drawer, and inventory modal cancel. The two earlier nav receipts remain separately source-stamped. The 55462 ordinary actor has zero roles/grants/scopes; real login and Fortify confirmation opened security at all five widths, products returned 403 and another actor's receipt 404. The owner has only three M03 customer permissions, no roles/scopes, and used `OperationService::previewBulk`: planned Cairo→Giza, persisted Cairo, no execution.

Screenshot batches preserve original source/build identities. Unchanged pages retain prior renders; affected pages were recaptured. Source fingerprints alone exclude the JSON locale file, so localized special screens/final timings also record `lang/ar.json` SHA-256 `91b6d8d4f5f299376b205b4399e3aa70ea92eb8b0502d3949f28d389e0b522c3`. Earlier localization hashes are unknown, not assigned retrospectively. Stale compiled-view, expired-session, wrong-selector and earlier failed overflow frames remain superseded rather than accepted. Repeated special fixtures are deduplicated to actors 65/66; earlier actor metadata is historical and is not used to identify the current images.

A final quiet-server run collected **18/18 healthy uninstrumented samples**, one warmup then three runs per route/viewport, with actual workspace/profile/aging content and load-event completion. It does not replace the historical 166-case campaign. The prior noisy-host 18 samples remain preserved, not deleted or selected by fastest time. Final receipt: `docs/qa/design-consistency/2026-11-01T01-28-37-638Z/after/{evidence.json,timing-summary.json}`. Debugbar/Pulse were disabled.

| Route / viewport | Before UI-ready median | Final UI-ready median | Before / final TTFB median |
|---|---:|---:|---:|
| Customer detail / 1440 | 679ms | 622ms | 363.9 / 311ms |
| Customer detail / 390 | 819ms | 834ms | 443.4 / 382ms |
| Home / 1440 | 688ms | 596ms | 378.6 / 265ms |
| Home / 390 | 652ms | 643ms | 312.1 / 309ms |
| Aging / 1440 | 540ms | 556ms | 245 / 293ms |
| Aging / 390 | 685ms | 619ms | 358.7 / 325ms |

Across the nine warm samples per viewport, UI median is **629→613ms desktop**, **685→678ms mobile**; TTFB median **342.1→292.7ms** and **358.7→329.1ms**. No large UI-readiness regression was observed in this final control set; the two positive route deltas are 15/16ms. This small sample does not establish statistical significance or all-page performance. **P95 is withheld** at n=3 per route and n=9 per viewport. The final CSS gzip increase is approximately 1.40KB over the original UI reference; no frontend dependency or ordinary-page chart bundle was added.

A separate, instrumented native Debugbar aging request observed **13 SQL queries / 124.07ms**, slowest 97.91ms, no repeated fingerprints, no exceptions, 8.5MB peak and 657 template entries. Its roughly 945.64ms instrumented request is diagnostic, not a replacement for the browser timings. The dense Blade/component tree deserves future evidence-led review; these numbers do not justify speculative indexes or unsafe authorization caching. Raw Debugbar data remains private under `.tmp/page-speed-audit/native-debugbar-aging-profile/`.

## Outstanding acceptance / dependencies

- Individual visual sign-off of every page/state is not claimed. Normal-state captures are complete; the full gallery and archetype references are reviewable. All previously confirmed page overflow/density/action-visibility defects in this migration have replacement evidence.
- Operation ownership and pagination failures were fixture/harness issues and have real replacement proofs; original failed receipts remain historical. Broader browser upload/export/approval combinations remain a separate functional coverage concern.
- Quiet-server/native profiling follow-up is complete for the UI controls. The entire separate Phase 2 optimization/full re-benchmark program is not complete; see `docs/performance/ERP_PHASE2_FINAL_REPORT.md`.
- Exhaustive financial posting/FIFO/reconciliation/tender award and all permission/state combinations are not proven by the 57-test/21-script scope. Preserve the older accepted business journeys as historical evidence, not fresh acceptance.
- The home workspace still needs authoritative owner projections for executive financial analytics. No fictional KPIs, balances, trends or charts were inserted.
- Native assistive technology/physical touch devices and every modal/validation/empty/loading branch remain broader than this representative acceptance. Horizontal summary/tab rails can receive a stronger visible scroll cue in a later polish pass; the clipped edge is intentional and all values remain available by scrolling.

## Exact reproduction commands

```powershell
npm run build
php artisan view:cache
php -d opcache.enable_cli=0 vendor/bin/pint --test tests/Feature/FoundationTest.php tests/Feature/OptimizationRegressionTest.php
node tests/E2E/bootstrap-ui-acceptance.cjs --prepare --migrate --test --safe-qa
```

The bootstrap refuses unowned/mismatched PostgreSQL targets. Do not point PHPUnit at the development DB or reset the 55461 browser fixture graph. Existing raw screenshots/timings, superseded receipts and private failure logs remain available for comparison.

## Final catalogue and localization defects resolved

Party summaries now use the same compact RTL strip; native Flux mobile More retains all authorized import/export/clear choices; selection/ordinal/logo share a compact card header without reordering DOM or dropping data. Customer filter heading repetition is reduced on mobile. Tender register/dashboard/report headings and active navigation are distinct. Twenty-nine missing framework account-security/authentication strings are translated centrally in `lang/ar.json`; no authentication rule changed. The lead opened and reviewed the actual fresh rendered catalogue and form replacements, not just their CSS assertions.
