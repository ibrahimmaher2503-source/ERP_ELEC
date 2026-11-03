# ERP UI migration progress

2026-11-01. Candidate implementation is local and preserves the pre-existing dirty working tree. Business logic/permissions/financial rules are unchanged by this UI batch.

## Batches

| Batch | Delivered presentation | Verification |
|---|---|---|
| Foundation | Semantic tokens, Flux compatibility, headings, focus/disabled/status/feedback/empty-state rules | Build and Blade compilation passed |
| Registers | Visible search + collapsible secondary filters; Sales duplicate KPI removal; heading secondary actions; compact summaries/status navigation | Fresh Sales390 reference reviewed; cross-module run pending |
| Responsive records | Original field/value-preserving row cards for organization/products/parties/sales/purchasing/inventory/project/maintenance/workshop/treasury/accounting-report families | Desktop/card value comparisons and five-viewport captures pending |
| Details/forms/reports/dashboard | Existing owner headings, identities, sections and actions share shell/anatomy; complex editable grids remain scroll regions; settings heading reused | Route/state acceptance pending; no fake executive analytics |

## Source freshness incident

The 23-28-55 smoke loaded the final CSS but stale private compiled Blade views. It is SUPERSEDED and is not current-candidate acceptance. The private compiled view cache was cleared in the isolated environment; the 23-32-28 Sales390 receipt demonstrates the intended candidate. Historical screenshots and timing receipts are retained.

## Exact UI application/build files touched by this delivery

These are the files touched by this UI work, not a claim that every working-tree difference belongs to this task. Pre-existing module changes remain intact.

- `public/build/assets/app-BndKEvZV.css`
- `public/build/manifest.json`
- `resources/css/app.css`
- `resources/views/accounting/index.blade.php`
- `resources/views/accounting/reports.blade.php`
- `resources/views/accounting/setup.blade.php`
- `resources/views/components/erp/status-badge.blade.php`
- `resources/views/components/page/heading.blade.php`
- `resources/views/components/ui/empty-state.blade.php`
- `resources/views/components/ui/filters.blade.php`
- `resources/views/components/ui/responsive-table.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/data-operations/workflow.blade.php`
- `resources/views/errors/403.blade.php`
- `resources/views/inventory/document.blade.php`
- `resources/views/inventory/documents.blade.php`
- `resources/views/inventory/operations.blade.php`
- `resources/views/inventory/overview.blade.php`
- `resources/views/inventory/transfer-receiving.blade.php`
- `resources/views/inventory/warehouses.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/maintenance/index.blade.php`
- `resources/views/maintenance/request.blade.php`
- `resources/views/maintenance/work-order.blade.php`
- `resources/views/organization/branch-details.blade.php`
- `resources/views/organization/branches.blade.php`
- `resources/views/organization/company.blade.php`
- `resources/views/organization/role-details.blade.php`
- `resources/views/organization/roles.blade.php`
- `resources/views/organization/user-details.blade.php`
- `resources/views/organization/users.blade.php`
- `resources/views/pages/settings/layout.blade.php`
- `resources/views/partials/settings-heading.blade.php`
- `resources/views/parties/catalog.blade.php`
- `resources/views/parties/categories.blade.php`
- `resources/views/parties/details.blade.php`
- `resources/views/parties/form.blade.php`
- `resources/views/parties/partials/status.blade.php`
- `resources/views/products/catalog.blade.php`
- `resources/views/products/details.blade.php`
- `resources/views/products/masters.blade.php`
- `resources/views/products/prices.blade.php`
- `resources/views/products/product-form.blade.php`
- `resources/views/projects/budget.blade.php`
- `resources/views/projects/closure.blade.php`
- `resources/views/projects/dashboard.blade.php`
- `resources/views/projects/documents.blade.php`
- `resources/views/projects/financial.blade.php`
- `resources/views/projects/index.blade.php`
- `resources/views/projects/operations.blade.php`
- `resources/views/projects/reports.blade.php`
- `resources/views/projects/show.blade.php`
- `resources/views/purchasing/comparison.blade.php`
- `resources/views/purchasing/dashboard.blade.php`
- `resources/views/purchasing/offer.blade.php`
- `resources/views/purchasing/offers.blade.php`
- `resources/views/purchasing/order.blade.php`
- `resources/views/purchasing/orders.blade.php`
- `resources/views/purchasing/receipt.blade.php`
- `resources/views/purchasing/receipts.blade.php`
- `resources/views/purchasing/requisition.blade.php`
- `resources/views/purchasing/requisitions.blade.php`
- `resources/views/purchasing/rfq.blade.php`
- `resources/views/purchasing/rfqs.blade.php`
- `resources/views/receivables-payables/index.blade.php`
- `resources/views/receivables-payables/invoice-details.blade.php`
- `resources/views/sales/orders/details.blade.php`
- `resources/views/sales/orders/form.blade.php`
- `resources/views/sales/orders/index.blade.php`
- `resources/views/sales/orders/partials/feedback.blade.php`
- `resources/views/sales/orders/policy.blade.php`
- `resources/views/sales/quotations/acceptance.blade.php`
- `resources/views/sales/quotations/approval.blade.php`
- `resources/views/sales/quotations/conversion.blade.php`
- `resources/views/sales/quotations/details.blade.php`
- `resources/views/sales/quotations/form.blade.php`
- `resources/views/sales/quotations/index.blade.php`
- `resources/views/sales/quotations/policy.blade.php`
- `resources/views/tenders/index.blade.php`
- `resources/views/tenders/show.blade.php`
- `resources/views/treasury/index.blade.php`
- `resources/views/treasury/movement-details.blade.php`
- `resources/views/workshops/index.blade.php`
- `resources/views/workshops/intake.blade.php`
- `resources/views/workshops/job.blade.php`

- `lang/ar.json`

- `resources/views/components/erp/selection-toolbar.blade.php`

## Supporting artifacts

- `docs/design/ERP_UI_ARCHITECTURE.md`
- `docs/design/ERP_DESIGN_SYSTEM.md`
- `docs/design/ERP_PAGE_ARCHETYPE_MATRIX.md`
- `docs/design/ERP_UI_MIGRATION_PROGRESS.md`
- `docs/design/ERP_UI_BEFORE_AFTER_GALLERY.html`
- `docs/design/ERP_UI_UNIFICATION_FINAL_REPORT.md`
- `docs/design/UI_SYSTEM.md`
- `docs/qa/design-consistency/REMEDIATION.md`
- `.ai/HANDOFF.md`
- Luna-authored `tests/E2E/design-consistency-verify.cjs`, `tests/E2E/bootstrap-ui-acceptance.cjs` and current route/evidence receipts
- Luna-authored test changes in `tests/Feature/OptimizationRegressionTest.php` and `tests/Feature/FoundationTest.php`

## Acceptance boundaries

Inspect current rendered representatives in all five viewports; replace stale receipts; record exact layout/action/a11y failures; complete real Livewire interactions and matched uninstrumented readiness samples. A normal-page screenshot does not prove modal, validation, permission or business regression coverage. Intentionally gated routes are not silently activated.

## Confirmed replacement checks

- Product details: actual 250px overflow at 768px traced to implicit grid tracks. Explicit `grid-cols-1` required a Vite rebuild; final CBOIam_T asset includes the rule and all five viewports pass. Earlier failed frames remain superseded.
- Branch create visibility: create permission is independent of company-view permission. The dedicated regression test passes.
- PostgreSQL 55462: all 57 Laravel tests pass (293 assertions); existing safe QA 21/21 scripts passes (875 assertions). Earlier dashboard-copy assertion failure is preserved privately and replaced with stronger no-authority workspace assertions.
- Scoped Pint passes with CLI OPcache disabled for the formatter to avoid a pre-existing cross-project PHAR cache collision; application runtime configuration is unchanged.

Final status: ERP UI UNIFICATION COMPLETE for implemented page families and documented normal-state/representative acceptance. Active gallery: 164 case/states × 5 viewports = 820 frames; 37 interaction sessions, zero accepted failures. Locale/raw fixture/source boundaries and broader business acceptance limits are in the final report. Last asset is app-BndKEvZV.css (462.96KB/51.05KB gzip).
