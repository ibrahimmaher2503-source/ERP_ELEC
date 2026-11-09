# Phase 2 interaction acceptance

Current replacement evidence is being collected with the Luna-authored existing UI runner on marker-owned PostgreSQL 55461. Original failed receipts remain in `ERP_PAGE_SPEED_AFTER.json` and `ERP_LIVEWIRE_INTERACTION_BENCHMARK.json`.

| Category | Current status |
|---|---|
| Mobile Sales search and secondary filter placement | Actual candidate screenshot reviewed at 390px after private Blade cache repair; interaction latency not inferred from the screenshot |
| Cross-module collapse/value preservation/reset | PASS in 12 sessions: four route families × 768/390/360; actual Livewire updates and clear/reset observed |
| Card values / financial fields / sort / details navigation | PASS: Sales 10 and Purchasing 9 exact desktop/mobile fields, aging 9 semantic values, actual sort and SPA detail/back |
| Product pagination | PASS replacement on desktop/mobile: visible enabled control, real request, changed rows, search/clear/sort reset; original timeout retained |
| Actor-owned operation details / reload / denial | PASS real owner-action preview at five widths/reload, unchanged source customer and no execution; other ordinary actor returned 404 at five widths. All on new owned 55462. |
| Upload / private preview / download / denied access | Pending dedicated safe product-media acceptance |
| Validation / save draft / edit / approval / bulk / import / export | Historical separate 55460 receipts are retained; not silently counted as current Phase 2 full coverage |

Current read-only UI interaction evidence lives in `docs/qa/design-consistency/2026-10-31T23-36-31-311Z/after/`: `filter-disclosure-interactions.json`, `responsive-data-checks.json`, `spa-navigation.json`, `navigation-interactions.json`, `secondary-action-interactions.json`, `product-pagination-interactions.json`, `inventory-modal-cancel.json` and `operation-preview-owner-check.json`. The inventory dialog was opened and cancelled with its underlying row unchanged. No stock or financial posting was submitted.

Search/filter timing must end after visible data stabilizes, with actual Livewire v4 endpoint observations. The old fixed `/livewire/update` matcher and fuzzy global Arabic search-label selector are harness defects. The shared runner now scopes exact labels and matches the installed version's endpoint shape. Successful observations must include changed UI state; network idle alone is insufficient.
