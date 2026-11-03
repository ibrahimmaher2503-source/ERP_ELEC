# ERP UI architecture

Candidate migration, 2026-11-01. Approved identity: Arabic RTL, white right sidebar, pale-blue/white surfaces, royal-blue primary action, bordered cards, compact desktop tables. The visual audit and supplied references remain authoritative. This is presentation within the existing Blade/Livewire/Flux application, not a replacement frontend or business framework.

## Five canonical archetypes

| Archetype | Required anatomy | Existing implementation |
|---|---|---|
| List/register | Breadcrumb, heading, guarded primary action, secondary actions, optional compact status/summary, visible search, secondary filters, results, records, original pagination | `x-page.heading`, optional `secondaryActions`, `x-ui.filters`, `x-ui.responsive-table`, existing Flux/paginator |
| Details | Record heading/identity, status, contextual actions, grouped summary, predictable tabs, related data/history | Existing owner detail header/identity partials and panels; shared heading/surface/focus rules |
| Create/edit | Heading/context, owner-defined sections and labels, mobile single-column fields, original validation, save/cancel, deliberate line-item grid | Existing Flux forms, panels and owner Actions; no new form builder or sticky submit layer |
| Dashboard | Context/filter, real compact summaries, meaningful queues, real visualizations, truthful empty state | Existing owner projections; home remains an authorized workspace pending executive projection contracts |
| Report/analytics | Report heading, period/scope controls, original metric semantics, exports, dense desktop table and readable mobile records | Shared report filters; accounting/project reports reuse full-field cards where their actual headers permit |

Hybrid screens keep their primary archetype and existing subordinate form/history sections. An archetype attribute is a presentation hook, not proof of acceptance. See the route-by-route matrix.

## List density contract

Search remains outside the mobile filter body. At 360/390px the secondary controls collapse, status/module navigation scrolls horizontally, and the primary action stays visible. Secondary heading actions can use the single `secondaryActions` disclosure; its trigger is not rendered when authorization leaves the slot empty. The same underlying buttons and guards are retained. Desktop displays those controls inline.

Sales Orders has one set of status counts, in its existing four filter chips. The four duplicate KPI cards are removed. Export remains in the heading's secondary actions. Every row's ten fields, exact amounts, sorting and guarded detail/edit actions remain. Related registers use the same filter/card primitives rather than a Sales-specific replacement list.

## Table contract

Read-only register rows use the existing table on desktop and labeled row cards at widths up to 768px. Every source cell has a label; field expressions, identifiers, currency and money strings are unchanged. Header selection/sort controls have mobile equivalents using the same Actions. Selection-derived values must be defined before both mobile and desktop controls render.

Editable line items, spanning comparison tables and financial total grids retain all columns inside named, keyboard-focusable horizontal regions. Do not turn those into unlabeled stacked cells or hide columns. These are deliberate exceptions requiring individual visual acceptance, not automatically accepted conversions.

## Safety and adoption

- Sidebar links/search remain generated from authorized server-rendered destinations. No route catalogue or permission decision moves into JavaScript.
- Presentation code does not query another owner, calculate financial values, grant authority, change status, submit approval or post stock/financial effects.
- Existing loading, validation, dirty-form warnings, confirmation dialogs, private downloads and historical data remain owned by their current components.
- No new dependency, business migration, route, stock/accounting calculation or approval rule is introduced by this UI migration.
- M10/M15 are included only if implemented in the actual route registry; an imported specification is not evidence of a working screen.

## Acceptance

Inspect the actual rendered candidate at 1440×900, 1366×768, 768×1024, 390×844 and 360×800. A successful HTTP response or zero overflow does not establish good information hierarchy. Raw candidate receipts, screenshots, limitations and current acceptance status are linked in `ERP_UI_UNIFICATION_FINAL_REPORT.md`. Historical captures must remain distinguishable from current candidate renders. Never accept stale compiled Blade output merely because it loads the newest CSS.
