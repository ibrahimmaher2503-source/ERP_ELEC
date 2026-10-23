# Sales Orders browser E2E evidence

Status: **NOT RUN** — the browser workflow is authored and its static checks pass. It is waiting for an exclusive window on the separate PostgreSQL test database.

## Safety and scope

The runner uses Microsoft Edge through the installed Playwright package, serves the Laravel test app only at `127.0.0.1:8001`, and refuses to continue if that port is already occupied. Its fixture helper independently requires PostgreSQL database `erp_elec_new_test`. It does not migrate, reset, truncate, or delete order records. It uses reserved synthetic branch, customer, product, unit, and account identities, then creates two synthetic users with random per-run login passwords and narrowly scoped direct grants (including branch-scoped Sales Order permissions). Passwords are sent to the fixture helper over stdin and are never written to evidence. Draft-only pagination fixtures are used. Cleanup removes only the two test users' database sessions. The workflow creates pending, withdrawn, and rejected review records through real application actions; it never fabricates a role membership, approval authority, approval decision, stock movement, invoice, or financial posting.

This evidence covers the implemented Sales Orders browser journeys only. It does not verify all M01–M04 behavior or replace the focused Laravel and integration checks.

## Scenario status

| Scenario | Browser coverage | Result |
| --- | --- | --- |
| E2E-SO-01 | Guest access redirects to login; Arabic document direction and language | NOT RUN |
| E2E-SO-02 | Required fields reject an empty draft before persistence | NOT RUN |
| E2E-SO-03 | Creator selects authorized branch/customer/catalog item, saves a direct draft, and persists decimal strings | NOT RUN |
| E2E-SO-04 | Main, review, amendment, delivery, invoice, and closure tabs render; unavailable business actions remain disabled | NOT RUN |
| E2E-SO-05 | Authorized list pagination, search, status/branch/date filters, and filter reset | NOT RUN |
| E2E-SO-06 | Authorized filtered Excel export produces an XLSX download; reviewer has no export action | NOT RUN |
| E2E-SO-07 | Draft edit requires a manual save; dirty navigation prompts; saved changes survive reload | NOT RUN |
| E2E-SO-08 | Requester submits, withdraws, resubmits, and cannot decide their own request | NOT RUN |
| E2E-SO-09 | Separate branch-authorized reviewer cannot create or submit and can reject with a reason | NOT RUN |
| E2E-SO-10 | Creator cancels an unexecuted order with a reason; PostgreSQL verifies final status and review history | NOT RUN |
| E2E-SO-11 | RTL layout at 1672×941 and 1440×1000; mobile 390×844 overflow checks | NOT RUN |

## Visual evidence

Eight approved Sales Orders screenshots are available under `C:\Users\N\Downloads`, covering the list, direct-order editor, details, formal amendments, approval, delivery note, invoice requests, and closure/returns. The runner captures the list at 1672×941 to match the list reference, captures the direct draft editor and all current details tabs, and checks mobile 390×844 overflow. It exercises only implemented behavior. The formal amendment screen currently explains that versioned amendments require another contract; delivery creation, invoice access, closure, and return actions remain disabled or dependency-blocked. The runner does not count those screenshots as successful workflows. Visual comparison will be recorded separately from functional assertions after the browser run.

The attached Sales Orders references are at `C:\Users\N\Downloads\ChatGPT Image Oct 21, 2026, 09_19_18 AM-1.png` through `C:\Users\N\Downloads\ChatGPT Image Oct 21, 2026, 09_19_29 AM-8.png`. Only the first reference has a same-screen comparison target in this implemented slice (the list). The other references depict capabilities that are currently absent or blocked, so they can only be compared for shared shell and the visible blocked-state boundary.

## Run results

No database or browser result is recorded yet. After the exclusive test-database window is available, run from the repository root:

```powershell
node tests/E2E/sales-orders.cjs
```

The runner writes `result.json`, screenshots, and the authorized filtered `.xlsx` file under `tests/E2E/artifacts/<run-id>/`. Record the actual result, test counts, errors, and artifact path here after execution; never infer a pass from source presence or static validation.
