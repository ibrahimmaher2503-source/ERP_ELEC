# Shared ERP UI system

Approved baseline: Arabic RTL, white right sidebar, pale-blue/white surfaces, royal-blue primary actions, subtle borders and compact desktop tables. Authority: `../qa/visual-audit/DESIGN_CONSISTENCY_MATRIX.md`, `VISUAL_ISSUES.md` and their screenshots. This reference describes presentation, never business state or authorization.

## Semantic tokens

The single source is `.erp-shell` in `resources/css/app.css`. Tokens are scoped to the ERP shell so authentication and vendor styles remain independent.

| Role | Tokens | Usage |
|---|---|---|
| Main action / selection | `--erp-primary`, `--erp-primary-strong`, `--erp-tint` | Royal blue, hover/active blue, pale-blue selected surface. Selection never means business success. |
| Surface | `--erp-surface`, `--erp-canvas`, `--erp-border` | White cards/sidebar, pale-blue canvas, subtle borders. |
| Text | `--erp-ink`, `--erp-muted` | Headings/content and secondary text. |
| Success | `--erp-success`, `--erp-success-bg`, `--erp-success-border` | Confirmed successful outcomes only, with text. |
| Warning | `--erp-warning`, `--erp-warning-bg`, `--erp-warning-border` | Pending/review/attention states, with text. |
| Danger | `--erp-danger`, `--erp-danger-bg`, `--erp-danger-border` | Errors/rejection/destructive confirmation, with text. |
| Neutral | `--erp-neutral`, `--erp-neutral-bg`, `--erp-neutral-border` | Draft/archive/unavailable states; never fake completion. |
| Geometry | `--erp-radius`, `--erp-control-radius`, `--erp-focus-ring` | Cards, controls and visible keyboard focus. |

Flux remains the control system. Its scoped `--color-accent` / `--color-accent-content` use the primary tokens. Do not rely on a `data-variant` DOM attribute: the installed Flux button consumes its `variant` prop internally.

## Component usage

- Use `<x-page.heading>` for the heading/breadcrumb/action row. Long breadcrumbs wrap; the current location is text, not a redundant link. Do not put unguarded business destinations in a breadcrumb. Keep the primary action in `actions`; `secondaryActions` is inline on desktop and a disclosure on mobile. Authorization must remain inside each slot; never wrap an unrelated create action in another action's permission condition. An empty secondary slot has no trigger.
- Use `<x-ui.panel>` and `<x-erp.section-card>` for surfaces. Prefer existing form `space-y-4` / `gap-3` structure. Keep primary submit at the end of its form; retain Flux loading and server validation.
- Use Flux `variant="primary"` for the main action, default/`outline` for secondary, `ghost` for low-emphasis actions, `danger` for destructive actions. Keep existing confirmation and permission guards. Never use green for a selected tab.
- Use `<x-ui.filters id="owner-list-filters" target="search,status">` around existing filters. Put the existing search input in `search` so it stays visible while secondary filters collapse. Desktop remains expanded. At widths up to 768px the visible button toggles the controls and announces its expanded state. Values remain bound while collapsed; the result count remains outside. Each instance needs a stable unique ID. `target` lists only the existing filter actions for the loading message.
- Use `<x-ui.responsive-table label="قائمة أوامر الشراء">` around read-only list tables with `data-label` on **every** non-empty body cell. At widths up to 768px each row becomes one card; desktop keeps the same dense table. Keep all fields, links, exact decimal strings, currency and status text. Give table/rowgroup/row/header/cell explicit roles when adopting the stacked pattern. Do not adopt on editable line-item grids, totals grids or tables with row/column spans; retain an accessible scroll region there.
- AP/AR aging already has purpose-built cards preserving all nine fields. Reuse that pattern for its matching owner data; do not duplicate financial projections to fit another table component.
- Use existing `<x-ui.empty-state>` with a concise reason. Guidance may suggest changing filters/scope; create buttons require the original create permission. Do not promise a posting or approval that has not happened.
- Use Flux callouts with `class="erp-feedback--success" role="status"` or `class="erp-feedback--danger" role="alert"`. Validation remains attached to the original controls through Flux. Loading is truthful request activity, never a successful outcome.
- Use `.erp-status--success`, `--warning`, `--danger`, `--neutral` on existing status markup where the owning module defines that meaning. Keep human-readable status text; color alone is insufficient. Do not infer a posted/approved state from a generic boolean.
- Inputs need visible Flux `label` plus the existing binding and validation. Icon-only actions still need an accessible name. Disabled controls remain visibly disabled and cannot be presented as navigation destinations.

## Navigation and dashboard boundaries

Sidebar search reads only server-rendered, permission-checked anchors generated by the registered route helpers. No all-route registry is serialized to the client, and a search match never grants access. Existing owner policies, account/branch/warehouse checks stay authoritative. Module groups keep their current access conditions.

The home workspace is not an executive financial dashboard. The approved Modern SaaS analytics direction still requires permission-scoped, owner-supplied projections: period/comparison definition, refresh time, branch scope, exact financial values and the distinction between draft/posted/settled records. KPI totals, trends, cash-flow and operational charts remain unresolved until those contracts/producers exist. Do not derive them in shared Blade layouts or fabricate demo metrics.
