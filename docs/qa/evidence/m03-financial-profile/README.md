# M03 financial profile acceptance

The profile checks use real M05/M06/M12/M13 actions on the marker-owned PostgreSQL E2E cluster. They never point at the development database.

1. Run `node tests/E2E/bootstrap.cjs` to prepare the isolated database on port 55460.
2. With `DB_PORT=55460`, `DB_DATABASE=erp_elec_new_test`, `DB_USERNAME=erp_e2e`, and an empty `DB_PASSWORD`, run `tests/Support/check-phase1-financial-journeys.php` with `PHASE1_COMMIT_RACE_FIXTURES=1` to create real synthetic browser fixtures.
3. Run `tests/Support/check-m03-party-financial-profile.php` with `E2E_CLUSTER_MARKER` and `E2E_CLUSTER_TOKEN` from `.tmp/e2e-automation/bootstrap.json` for read-only profile reconciliation.
4. Run `node tests/E2E/m03-party-financial-browser.cjs` for authenticated desktop and mobile browser acceptance.

Each browser run writes a sanitized result and screenshots into its `run-*` folder. Synthetic fixture identities and credentials remain in the ignored test runtime; reports contain no email addresses or passwords.

The action journey advances only the read clock by 100 days to exercise overdue aging without backdating or changing posted records. Browser and readback results use the actual current date, so an invoice due next month correctly shows EGP 0.00 overdue there.
