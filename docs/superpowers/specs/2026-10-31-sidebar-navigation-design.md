# Sidebar and Navigation Refresh

## Intent

Make the RTL ERP sidebar feel current, calm, and easy to scan during daily operations. The refresh changes visual hierarchy and navigation density while preserving the existing routes, permission checks, module ownership, and responsive behavior.

## Goals

- Replace the current multi-color treatment with one coherent electrical-green accent system.
- Make the active destination and active module group obvious without heavy borders or saturated fills.
- Give the brand area, module list, and account area distinct roles.
- Reduce the dated nested-list feel through consistent spacing, icon containers, and lighter separators.
- Keep keyboard focus, RTL order, mobile collapse, and authorization-driven item visibility intact.

## Non-goals

- No route, label, permission, role, or business behavior changes.
- No new search, command palette, or module destinations.
- No dark-mode or English-navigation redesign in this pass.

## Proposed visual direction

Use a light blue shell with one clear blue accent and quiet slate states:

- Sidebar surface: `#F8FAFC` with white raised rows.
- Ink: `#172B4D` for the brand and high-emphasis text.
- Accent: `#2563EB` for active controls, icons, and focus.
- Accent dark: `#1D4ED8` for hover and active text contrast.
- Accent tint: `#EAF2FF` for active rows and open groups.
- Muted: `#64748B` for secondary labels.
- Page surface: retain the existing light content surface so the navigation frames the workspace.

Keep the existing Noto Sans Arabic font. Use one 40px brand mark, 14px group labels, 13–14px destinations, and 10px vertical rhythm between groups. Avoid rainbow accents and decorative gradients.

## Layout and interaction

```text
┌──────────────────────────────┐
│  mark  ERP Electrical        │  brand block, quiet divider
│        سياق العمل            │
├──────────────────────────────┤
│  الرئيسية                    │  one clear home row
│  الوحدات                     │  small section label
│  ◉ التنظيم              ˅    │  open group uses tint + accent
│      الشركة                  │
│      الفروع                  │  child rows use one indent
│  ◉ المبيعات              ˅   │
│      أوامر البيع             │
│  ...                         │
│                              │
│  avatar  مدير النظام         │  anchored account row
└──────────────────────────────┘
```

The sidebar keeps the current Flux groups and semantic links. CSS supplies a consistent row height, icon chip, active rail on the logical start edge, and open-group background. Child destinations remain visible only when their current permission logic allows them. The account row remains anchored at the bottom. Mobile keeps the current drawer and collapse controls.

## Approaches considered

1. **Light green unified shell (recommended):** smallest change, matches the existing light ERP surfaces, and removes visual noise while retaining familiar navigation.
2. **Dark professional shell:** stronger contrast and a more dramatic identity, but would require broader shell and content contrast tuning and is a larger visual shift.
3. **Icon rail with flyouts:** fastest scanning for expert users, but introduces a new navigation model and creates mobile and keyboard complexity.

The recommended approach fits the reported problem and the existing layout contracts, so implementation is limited to the shared app sidebar and its CSS tokens.

## Review status

Approved direction: light blue unified shell, selected by the owner on 2026-10-31 after reviewing the dark variant. Implementation follows the existing Flux shell and permission-driven navigation.
