## Superseded capture

This 8 screenshot smoke batch is superseded and must not be used as acceptance evidence. It rendered outdated sales-order Blade markup from the shared private compiled-view cache even though it loaded the current CSS asset. The cache was cleared with the isolated performance-audit bootstrap, and the corrected route was re-captured in `2026-10-31T23-32-28-462Z/after` and visually approved.

Observed asset URLs in the stale batch: `/build/assets/app-XJUwhpo4.css` and `/build/assets/app-BvRk9kiK.js`. This establishes that the CSS/JS build was current while the compiled Blade output was stale.
