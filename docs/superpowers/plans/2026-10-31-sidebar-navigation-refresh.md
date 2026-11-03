# Sidebar Navigation Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the dated multi-color sidebar with a light blue, single-accent navigation shell that remains permission-aware, RTL-safe, and responsive.

**Architecture:** Keep Flux sidebar/group/item components and all existing Blade conditions intact. Move the visual system into shared `.erp-sidebar` and `.erp-nav` CSS tokens, with small semantic class updates in the app shell for the brand and account areas. No routes, Actions, permissions, or module queries change.

**Tech Stack:** Laravel 13, Blade, Livewire 4, Flux UI free components, Tailwind 4, Vite, Noto Sans Arabic.

**Spec:** `docs/superpowers/specs/2026-10-31-sidebar-navigation-design.md`

## Global Constraints

- Preserve all existing permission checks, route names, and module ownership boundaries.
- Preserve RTL ordering, keyboard focus visibility, mobile collapse, and authorization-driven item visibility.
- Keep the existing Noto Sans Arabic font and light content workspace.
- Do not add routes, destinations, search, command-palette behavior, or new dependencies.
- Do not write or run automated test/check code in this pass; verify through the local browser and front-end build only.

## Review Focus

- **Active route:** the current destination must use the blue accent, tint, and logical start rail without inheriting the old purple/green variants. Verify on `/dashboard` and one nested module route.
- **Open group:** an expanded module must have a quiet raised surface and readable child links without every child looking selected. Verify on the open Sales group.
- **Permission-driven omissions:** hidden or disabled destinations must retain their current conditions and readable disabled styling. Verify from the Administrator dashboard state.
- **RTL and keyboard:** the active rail and focus outline must appear on the logical start edge and remain visible against the dark surface. Verify with keyboard focus and RTL viewport.
- **Mobile drawer:** the sidebar collapse/toggle must retain sufficient contrast and the same hierarchy at the mobile breakpoint. Verify at the existing 390px viewport.

### Task 1: Apply the light blue sidebar token system

**Files:**
- Modify: `resources/css/app.css:48-268`

**Interfaces:**
- Consumes: existing `.erp-shell`, `.erp-sidebar`, `.erp-nav`, Flux sidebar data attributes, and current module-specific classes.
- Produces: one light blue palette (`#F8FAFC`, `#FFFFFF`, `#2563EB`, `#1D4ED8`, `#EAF2FF`, `#172B4D`, `#64748B`) applied consistently to brand, groups, rows, current state, disabled state, and focus state.

- [ ] **Step 1: Replace the light sidebar base rules**

  Update `.erp-sidebar` to use the light blue base and white raised surfaces, remove the per-section color variables, and set the correct border and text colors.

- [ ] **Step 2: Unify group and item states**

  Use one accent for icons, hover, open groups, and current items. Keep current row height and indentation, but add a restrained rounded row treatment, a logical start accent rail, and readable muted child text.

- [ ] **Step 3: Recheck focus and disabled states**

  Set a blue focus outline with sufficient contrast and preserve `aria-disabled` cursor/opacity behavior without dimming active content.

- [ ] **Step 4: Remove obsolete color overrides**

  Delete or neutralize `.erp-nav-home` and `.erp-nav-products` palette overrides so no module retains purple or green accents.

### Task 2: Align the Blade shell markup with the new visual hierarchy

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php:6-22`

**Interfaces:**
- Consumes: current Flux sidebar/header/collapse components and authenticated user menu.
- Produces: semantic brand and shell classes that let the light sidebar style its logo, title, subtitle, divider, collapse control, and account area without changing navigation behavior.

- [ ] **Step 1: Add semantic brand classes and light-safe utility classes**

  Replace hard-coded light brand colors with the sidebar semantic classes while retaining the existing link destination, logo SVG, title, subtitle, and focus behavior.

- [ ] **Step 2: Keep navigation conditions unchanged**

  Do not alter any `@if`, route, permission, or `:expanded` expression; only the presentation classes may change.

### Task 3: Verify the shell in the browser and build

**Files:**
- Verify: `resources/css/app.css`
- Verify: `resources/views/layouts/app/sidebar.blade.php`

**Interfaces:**
- Consumes: the running local ERP dashboard and the existing Vite build.
- Produces: visual evidence that the dashboard sidebar is dark blue, active navigation is legible, and mobile/keyboard states remain usable.

- [ ] **Step 1: Run the front-end build**

  Run `npm run build` from `C:\projects\ERP_ELEC_NEW` and confirm Vite completes successfully.

- [ ] **Step 2: Refresh the authenticated dashboard**

  Inspect `/dashboard` in the open browser at the existing desktop viewport. Confirm the sidebar is light blue, the dashboard row is active, and the open Sales group is readable.

- [ ] **Step 3: Verify RTL keyboard and mobile states**

  Check focus visibility with keyboard navigation and inspect the existing 390px mobile layout. Confirm the drawer toggle, collapse behavior, and logical active rail remain visible.
