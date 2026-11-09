# UI improvement backlog

## Phase 1 — critical / usability

1. **UI-M03-001 — remove 390px party-detail overflow (P1).** Constrain the customer and supplier overview layout and verify 360px/390px with long names.
2. **UI-M17-001 — define the executive dashboard product surface (P1).** Add real, permission-scoped KPI/chart producers and a designed empty state; do not invent metrics.
3. **UI-A11Y-001 — label inventory search fields (P2).** Add visible labels or equivalent accessible names for `search` and `productSearch`.
4. **UI-M12-001 — redesign dense financial tables for mobile (P2).** Preserve identifiers and amounts while changing the mobile presentation.

## Phase 2 — refinement

5. **UI-M06-001 — remove mixed-language inventory navigation.** Localize English labels or explicitly mark technical identifiers.
6. **UI-SHELL-001 / UI-M17-002 — remove dead header controls.** Hide or replace planned search and English controls until they are functional.
7. **UI-SHELL-002 — improve module discovery.** Keep the right sidebar but add stronger grouping and a working navigation search when approved.
8. **UI-TOKENS-001 — document semantic color tokens.** Align blue primary, green success, orange warning and red error roles across modules.

## Phase 3 — polish

9. Reduce empty-state height on the dashboard while retaining a truthful explanation.
10. Differentiate workshop and field-maintenance iconography.
11. Collapse secondary mobile breadcrumb/tab chips where they push primary content below the fold.
12. Add loading, validation, permission-denied and success-state visual captures before implementation review.

## Verification gate

After each approved UI phase, rerun the same 1440×900, 1366×768, 768×1024, 390×844 and 360×800 captures, then repeat keyboard, focus, RTL and overflow checks. No code change is included in this audit.
