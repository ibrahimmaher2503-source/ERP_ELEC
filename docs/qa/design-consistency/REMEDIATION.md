# Design consistency remediation — 2026-11-01

The owner subsequently expanded this foundation batch into full UI architecture unification. Current scope, exact files, route adoption and acceptance are authoritative in `docs/design/ERP_UI_ARCHITECTURE.md`, `ERP_PAGE_ARCHETYPE_MATRIX.md`, `ERP_UI_MIGRATION_PROGRESS.md` and `ERP_UI_UNIFICATION_FINAL_REPORT.md`. The original limited scope below is retained as historical context.

Scope: shared presentation within the approved light Arabic RTL identity. The existing visual audit is the baseline; this is not a whole-ERP redesign or a new all-route performance campaign. No owner Action, financial/stock/approval calculation, route, migration, permission policy or dependency is part of this batch.

Reference: [tokens and component usage](../../design/UI_SYSTEM.md). Before/after evidence and exact verification results will be linked here after the final build.

## Cross-module adoption

| Surface | Shared adoption | Preserved behavior |
|---|---|---|
| All ERP shell pages | Semantic tokens; Flux accent; focus, disabled, headings, compact empty states | Existing controls, validators, owner permissions and actions |
| Sidebar | Existing permission-scoped link search and module groups; distinct workshop/maintenance icons; remove dead sales destination | Same guarded registered routes, no client-side route catalogue |
| M03 parties | Visible search/filter labels; semantic status colors | Existing catalogue bindings, bulk guards and contact data |
| M04 sales orders | Shared collapsible filters and responsive row cards | All ten fields, exact money display, sort, details/edit guards |
| M05 purchase orders | Shared collapsible filters and responsive row cards | All nine fields, exact decimal strings/currency, lifecycle and receiving text |
| M06 inventory | Shared collapsible filters | Existing warehouse/branch options and filter bindings; dense stock table retained |
| M12 AP/AR/aging | Shared collapsible filters and feedback; existing nine-field cards retained | Original/settled/credited/remaining amounts, dates, source and age |
| Permission denied | Compact Arabic 403 state with safe home navigation | Original HTTP denial and policies |
| Home/dashboard | Approved existing workspace preserved | No invented analytics or data projections |

## Resolved defect mapping

- `UI-TOKENS-001`: formal semantic roles and shared consumers; selected UI remains blue, success is reserved for outcomes.
- `UI-SHELL-001/002`, `UI-M17-002`: preserve the earlier removed disabled search/language controls and authorized navigation search; remove remaining dead sales destination and distinguish workshop icon.
- `UI-A11Y-001`: earlier inventory labels retained; visible labels extend party catalogue filters.
- Responsive consistency: one filter disclosure and one opt-in row-card table pattern used by real repeated modules; no financial field is hidden to fit mobile.
- Breadcrumb/current-location and long-title wrapping; compact shared empty states and uniform feedback styling.

Previously resolved party-profile overflow and nine-field aging cards are retained. The original matrix describes its historical captures; do not mistake historical observations for fresh failures.

## Outstanding issues / boundaries

- `UI-M17-001`: executive analytics still needs authoritative owner projections, scope, periods and refresh semantics. The usable home workspace is not a financial analytics substitute. See the dependency in the token reference.
- Adoption is deliberately opt-in for stacked tables. Editable stock/financial line grids and complex row/column spans keep their scroll behavior; every owner table has not been converted.
- Every route, modal, empty state, validation branch and restricted role has not been re-accepted. Preserve the original full-audit INCOMPLETE status and report only the representative evidence below.
- No operational posting or approval is submitted for UI acceptance. Exhaustive business/security suites and the original all-route benchmark are outside this presentation batch.

## Verification

Before: 40 captures across eight routes/five viewports in `2026-10-31T23-04-30-908Z` plus `23-07-12-188Z`; 18 strict readiness timing samples in `23-07-33-466Z`. Keep the older heading-only timing samples superseded.

Current candidate evidence is checkpointed in `2026-10-31T23-36-31-311Z/after/`; source/build boundaries and superseded stale-cache, expired-session and missing-CSS-utility screenshots remain recorded. The first 23-28-55 smoke is not current-candidate acceptance. The final full-migration report distinguishes automated checks, actual lead screenshot review, business regression and untested routes/states.

Vite/Blade compilation pass; final asset is `app-CBOIam_T.css`. Isolated PostgreSQL 55462 Laravel suite passes 57 tests/293 assertions and existing safe QA passes 21/21 scripts/875 explicit assertions. These are not a substitute for all-route visual or all-module financial lifecycle acceptance.

## Exact application files changed in this batch

- `resources/css/app.css`
- `resources/views/components/ui/filters.blade.php` (new)
- `resources/views/components/ui/responsive-table.blade.php` (new)
- `resources/views/components/ui/empty-state.blade.php`
- `resources/views/components/page/heading.blade.php`
- `resources/views/components/erp/status-badge.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/parties/catalog.blade.php`
- `resources/views/parties/partials/status.blade.php`
- `resources/views/sales/orders/index.blade.php`
- `resources/views/sales/orders/partials/feedback.blade.php`
- `resources/views/purchasing/orders.blade.php`
- `resources/views/inventory/overview.blade.php`
- `resources/views/receivables-payables/index.blade.php`
- `resources/views/errors/403.blade.php` (new)

Supporting delivery: `docs/design/UI_SYSTEM.md`, this report, `.ai/HANDOFF.md`, Luna-authored `tests/E2E/design-consistency-verify.cjs` and the timestamped evidence/gallery under this directory. Vite regenerates `public/build/manifest.json` and its asset files. Existing unrelated working-tree changes are not part of this batch.
