# Synthetic Electrical ERP visual screen inventory

Audit date: 2026-10-31 (Africa/Cairo). This is an audit-only snapshot; no Blade, CSS, route, business, or data changes were made.

## Coverage

| Measure | Result |
|---|---:|
| Current eligible page cases from route registry | 140 |
| Current registry page names without a valid isolated fixture URL | 5 |
| Representative screens selected | 30 |
| Screenshots captured | 50 |
| Viewports | 1440×900, 1366×768, 768×1024, 390×844, 360×800 |
| HTTP 200 captures | 50/50 |
| RTL captures | 50/50 |
| Sidebar and main landmark present | 50/50 |
| Horizontal overflow | 0/50 |
| Broken images / failed requests / console errors | 0/50 |
| Visible unlabeled fields | 2 |

The current route registry is preserved in [evidence.json](evidence.json). The 140 page cases are inventoried from the current registry and fixture manifest; only 30 were selected for fresh screenshot capture to keep the gallery reviewable. The remaining cases are explicitly **NOT SCREENSHOT-INSPECTED** in this pass. Prior release screenshots are retained separately and were not silently counted as fresh evidence.

## Fresh representative capture set

The curated set covers M01, M02, M03, M04, M05, M06, M07 and Tenders, M08, M09, M11, M12, M13 and M17. Desktop 1440×900 covers all 30 selected screens; laptop, tablet and both mobile widths cover dashboard, appearance, customer creation and aging representatives. The offline gallery contains all 50 captures.

## State coverage

The fresh capture exercised normal populated/empty-looking screens only. Permission-denied, validation-error, destructive, modal-open, loading and success feedback states were not exercised because no safe existing fixture workflow was selected. They are marked untested rather than inferred from HTTP status.

## Evidence and environment

- Browser: Microsoft Edge through the existing Playwright dependency.
- Locale: `ar-EG`; timezone: `Africa/Cairo`; RTL expected.
- Database: marker-owned PostgreSQL `erp_elec_new_test` on port 55461.
- Debugbar and Pulse disabled for browser capture.
- Screenshots: [offline gallery](index.html) and `screenshots/`.
- Runner: [visual-audit.cjs](../../../tests/E2E/visual-audit.cjs), Luna-authored under the repository testing rule.
