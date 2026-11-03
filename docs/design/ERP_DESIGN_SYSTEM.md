# ERP design system

The token source is **`resources/css/app.css`, `.erp-shell`**. The semantic map and component usage contract are maintained in [UI_SYSTEM.md](UI_SYSTEM.md); this file is the architecture entry point, not a second token definition.

Use the installed Flux controls. Blue means primary action/selection, green a successful outcome, amber attention, red danger, and gray neutral state. Retain status text and original owner meanings. Customer/supplier pages inherit the same accent; the old navy/teal party accent overrides are removed.

| Need | Reuse |
|---|---|
| Page heading/breadcrumb/actions | `x-page.heading`; `actions` primary slot and optional `secondaryActions` slot |
| Search and filters | `x-ui.filters` with stable `id`, original `target`, optional `search` slot outside the disclosure |
| Dense table/mobile cards | `x-ui.responsive-table`; original table, labeled cells and explicit table roles |
| Panels/form sections/record summaries | Existing `x-ui.panel`, `x-erp.section-card`, owner identity partials |
| Status/count summary | Existing Flux badges and `x-erp.stat-card`; `.erp-kpi-summary` only for actual summaries |
| Module/status tabs | Existing guarded links/buttons; `.erp-section-nav` / `.erp-tabs` |
| Empty/loading/validation/feedback | Existing `x-ui.empty-state`, Flux validation, truthful `wire:loading`, semantic feedback classes |
| Pagination/confirmation/modals | Existing Laravel paginator and Flux modal/menu/confirmation controls |

Do not create competing table engines, modal systems, form generators, chart bundles or navigation registries. Do not hide financial detail to achieve density. Mobile summary strips keep their real values; duplicated Sales Orders totals are represented once in status navigation.

All inputs need a visible programmatically associated label. Preserve an accessible name for icon-only actions, visible keyboard focus and real disabled states. Keep collapse state local to the presentation component; collapsing must not reset Livewire values. Secondary actions must close through Escape/outside interaction and retain server authorization.

Dashboard analytics still needs authoritative owner projections defining period, branch/permission scope, exact values, comparison semantics and refresh time. No fabricated KPI or chart is delivered.
