# Local E2E automation

The automation uses the installed Playwright Test Runner from `playwright/test`.
It does not add Dusk, Pest, another browser framework, or a browser service.
All new test code is Luna-authored and reviewed by the lead, as required by
`AGENTS.md`.

## Commands

Run from the repository root:

| Command | Purpose |
| --- | --- |
| `npm run e2e:smoke` | Fast login, navigation, implemented module pages, draft save, and browser error checks. |
| `npm run e2e:critical` | Implemented critical journeys with actual owner Actions and PostgreSQL readback. |
| `npm run e2e:changed` | Select affected coverage using source fingerprints and dependency mappings. |
| `npm run e2e:full` | The complete maintained E2E selection, including desktop/mobile and negative checks. |
| `npm run e2e:report` | Print the latest compact JSON result without rerunning tests. |

Measured coverage, failures and remaining gaps are recorded in
[E2E_EXECUTION_REPORT.md](E2E_EXECUTION_REPORT.md). A command name does not imply
that every ERP business rule has automated coverage.

## Isolation and prerequisites

Use the existing PHP/Composer and Node installations, installed dependencies,
built Vite assets, the local PostgreSQL tools under
`.tools/postgresql/pgsql/bin`, and an installed Playwright browser. No company
administrator credentials are required for E2E runs.

The runner owns its PostgreSQL instance and test data under the Git-ignored
`.tmp/e2e-automation` directory. It must verify the instance ownership and actual
database connection before preparing fixtures or resetting its databases. It
does not reset development, shared testing port `5443`, production, or earlier
acceptance databases. Its browser server, application caches, sessions, files
and authentication state are isolated from the app used by the owner.

Synthetic prerequisite records use existing factories and authorized owner
Actions. Test permissions are explicit fixture setup. Successful stock, FIFO,
GL, invoices and completion evidence must come from the real owning Actions;
inserting a fake ledger result is not an acceptance technique.

## Reports and failure investigation

Normal output is a compact JSON summary with status, counts, duration, coverage
gaps, failed test names, relevant errors and failure artifact paths. Passing
assertion logs are not sent to Codex. Credentials, authentication state, server
logs and browser traces remain in ignored local storage.

On failure, inspect the summary first, then only the affected test, source and
failure trace or screenshot. Preserve failing evidence before rerunning.
Reproduce the affected test and its prerequisites before broadening the run.
Run the full maintained selection before release; review its coverage gaps and
business reconciliation results as well as its exit status.

## Scope boundaries

M10 Service Contracts, Tender Management and M15 Attendance currently have no
implemented application owners/routes in this checkout. Their named journeys
are coverage gaps, not passing tests. M09 Workshop Repairs is implemented but
does not yet have a final Phase 5 acceptance report. Prior standalone browser
harnesses and historical receipts remain preserved and are not relabeled as
fresh native-runner results.
