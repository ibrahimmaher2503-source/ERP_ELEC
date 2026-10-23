# Local verification — 2026-10-21

## Realistic factories / local demo data

- Luna authored eight new factories (Company/Branch scalar raw definitions and six Products Eloquent factories), disabledDemo UserFactory state, RealisticDemoSeeder and .tools/check-demo-factories.php. Products models expose normal HasFactory/UseFactory APIs. Root DatabaseSeeder composes the guarded local/testing seeder; no migrations required.
- Lead inspected and ran the isolated exact PostgreSQL erp_elec_new_test check: passed. Covers existing-record fingerprints, idempotent second run, unchanged security matrices/grants/infrastructure tables, disabled/unverified users with unknown random passwords, category hierarchy, dimensional conversions, private generated PNG existence and paths, actual factory()->create product/conversion/media graphs. Transaction rolled back and only generated fixture files cleaned.
- Scoped Pint --test passed. Local development command `php artisan db:seed --class=RealisticDemoSeeder` ran twice successfully. Actual demo totals: 5 branches,12 users,8 categories,6 brands,8 units,30 products,3 conversions,30 illustrative media. Owner company/account/history preserved; actual inserts generate anonymous demo.created events only.
- Required future-module factories/seeding rule added to AGENTS.md, architecture and pipeline. No fake sessions, passkeys, login history, queue jobs, financial/stock postings or operational grants were seeded. Full suites/screenshots not run.

M01 Stage03: IMPLEMENTED — QA DEFERRED.

- `php artisan migrate --force` and `php artisan migrate --env=testing --force`: user state migration applied successfully to separate PostgreSQL development/test databases. Normalized-email index installed without merging existing data.
- `php artisan route:list --path=organization/users --except-vendor`: list/detail routes resolve.
- PHP syntax checks over 38 relevant application/bootstrap/route/migration files: passed.
- `php -d phar.cache_list= -d opcache.enable_cli=0 vendor/bin/pint --test ...` scoped to changed PHP: passed. CLI INI overrides avoid this machine's stale PHAR preload path; no vendor edits.
- `npm run build`: passed. Vite leaves `/fonts/*.woff2` URLs as runtime static assets under public/fonts.
- Luna-authored `php .tools/check-m01-users.php`: exit 0, 21 PASS checks, PostgreSQL `erp_elec_new_test` only, transaction rolled back and fixture absence verified. Covers authorized create/edit/reset/status, current scope/self/disabled/cross-module denial, duplicate email, stale version, sessions, hash/audit redaction, Livewire rendering/mutations, Fortify inactive credentials and inactive-session middleware. Local receipt: `.local/m01-users-smoke.txt` (ignored).
- Existing `php .tools/check-m01.php`: passed company/branch authorization/mutations, HTTP rendering, private logo and rollback checks after shared auth changes.
- Manual browser review: authenticated Users list at desktop 1280×1000 and default 714×764; details route opened; actual local counters/empty roles shown. Temporary viewport restored. No real account was created/edited/disabled/reset in browser. Displayed business data was preserved.

Not performed: full suites, Playwright, automated screenshot regression, measured pixel parity, hosted/production acceptance. Stage04 was subsequently authorized and implemented as recorded below; warehouse access still waits for Inventory.

## M01 Stage04 — IMPLEMENTED, QA DEFERRED

- PostgreSQL migration `2026_10_21_050000_create_dynamic_rbac` applied successfully to development and isolated testing databases. Seeds the exact 14 owner-approved fixed roles and 14 implemented M01 permission keys. No financial/warehouse permissions or broad default matrix.
- Local bootstrap adds only explicit security configuration authority to the existing verified administrator, with an audit record. Existing business records/direct grants preserved.
- `php artisan route:list --path=organization --except-vendor`: 10 Organization routes, including roles, catalog, role details and independent access scopes.
- Luna-authored `php .tools/check-m01-rbac.php`: 36 PASS, exit 0; isolated PostgreSQL `erp_elec_new_test`, transaction rollback and fixture absence verified. Covers default deny, exact branch scopes, company scope, immediate role/direct revocation, protected key, self-mutation denial, read-only role write rejection, stale matrix rejection, protected inactive security accounts, last verified security administrator invariant and self-service email rollback, audit redaction, Livewire screen rendering/matrix/assignment/scope mutations. Allowed personal email changes clear verification and revoke old reset tokens/sessions; blocked last-admin changes preserve those records atomically.
- Bounded regressions after shared authorization integration: Luna-authored users check 26 PASS and company/branch check 29 PASS. Each transaction rolled back in the isolated testing database. No full suite.
- Scoped Pint `--test` for Organization, bootstrap command, routes and RBAC migration passed. CLI PHAR/opcache overrides used for the machine's stale preload setting.
- Independent source review found the personal email-change path lacked the session/reset-token cleanup already present in managed changes. Corrected using the existing RevokeUserSessions action and verified by the bounded checks above.
- `npm run build`: passed. Public font URLs remain runtime static assets as before. Blade cache cleared.
- Manual authenticated browser review: fixed roles list, Accountant matrix and module-grouped catalog opened; desktop 1280×1000 and default 714×764 reviewed. No real role, assignment, account or scope was changed through the browser. Screenshot: `.local/screenshots/m01-rbac-desktop.png` (local evidence).

Full QA, Playwright, screenshot suites, measured pixel parity and hosted/production acceptance remain deferred. Warehouse authority and sensitive commercial operations remain unavailable until their producers/policies are implemented.

## Roles reference visual refinement

- Reused the role details component for the embedded matrix; added real summary cards, compact eight-row pagination, adjacent desktop table/matrix, green switches, scope/lock badges, local permission search and Arabic pagination labels. Existing fixed-role and security rules preserved.
- Luna extended the bounded RBAC check: 38 PASS lines plus Livewire assertions, exit 0, isolated PostgreSQL test transaction rolled back. New coverage verifies selecting a role, embedded render, draft-only toggle without database write, explicit save/event dispatch, and locked/read-only toggle denial.
- Updated Vite build and scoped Pint checks passed. Manual authenticated browser review at 1536×1024 and 390×844; role selection and permission search worked. No real grants or assignments were saved. Desktop screenshot: `.local/screenshots/m01-rbac-reference-desktop.png`.
- IMPLEMENTED — QA DEFERRED. This is visual refinement, not a claim of exact pixel parity or full QA acceptance. No database migration was needed for the refinement.

## M01 Stage05 — Unified access integration

IMPLEMENTED — QA DEFERRED for roles, branches, direct-grant revocation, effective summary and relevant audit integrated into User Details. Warehouse grant integration remains BLOCKED on the M06 owning lookup/security contract.

- Reused existing PostgreSQL tables and audited Actions; no migration or business-data deletion. Removed the standalone access route/component/view after checking all source callers; role membership controls now live in User Details, while role matrices remain in Roles & Permissions.
- Luna-authored RBAC smoke: 48 PASS lines plus Livewire assertions, exit 0, isolated PostgreSQL transaction rolled back. Covers integrated role/branch grant/revoke persistence and immediate effect/audits, cross-user direct-grant forgery denial, self/non-security denial, inactive account/branch grant rejection, removed route, new-account redirect without automatic grants, inactive/unverified effective summaries, and target-view revocation on already-mounted components without mutation/audit side effects.
- Luna-authored users regression: 26 PASS, isolated PostgreSQL transaction rolled back. No routine full suite or Playwright.
- Independent source review identified and verified the fix for stale child access after losing target-view permission. The embedded component now rechecks both security authority and current target-view permission on reads/mutations.
- Scoped Pint passed; Vite build passed; Blade cache cleared. Organization route inventory now has 9 routes and no access page. Manual authenticated browser review confirmed the unified section at User Details; no real roles/grants were changed. Screenshot: `.local/screenshots/m01-unified-user-access.png`.
- Authoritative arrangement recorded in EXECUTION_PIPELINE.md, docs/m01-ui.md, docs/m01-rbac.md, docs/m01-users.md, docs/04-roles-permissions.md, AGENTS.md and handoff. Full QA and production acceptance remain deferred.

## M01 Stage06 / Stage07 local integration checkpoint

Stage06 supported audit/security functionality: IMPLEMENTED — QA DEFERRED. Stage07 consolidation contracts and cross-slice local checkpoint recorded; warehouse integration remains blocked on M06. Entire M01 is not QA ACCEPTED.

- Migration060 applied successfully to development and isolated PostgreSQL testing databases. Adds nullable recorded outcomes, anonymous System actors and user-history indexes without backfilling or removing append-only history.
- Luna-authored RBAC smoke: 62 PASS plus Livewire assertions, exit 0. Covers anonymous failed-login metadata without email/password/IP storage, exactly one successful-login event plus last_login, real reset-link dispatch metadata, personal password-change completion/session/reset-token revocation/safe single audit, legacy null outcome/actor and immutability, safe normalized snapshots, scoped/current security-history authorization, four-tab render and complete Alpine attribute, and existing grants/invariants.
- Scoped users regression: 26 PASS; company/branch regression: 29 PASS. All three ran sequentially in isolated PostgreSQL and rolled back fixtures.
- Independent source security review found no blocking authorization leak, duplicate event path or secret snapshot. Corrected the minor personal-gender event label. Manual AX-only browser review confirmed security history, permission history, access tab, deep links and RTL arrow-key tab navigation. Found and fixed a malformed HTML quote in the Alpine selector before delivery. No real account/security mutation through browser.
- Vite build and scoped Pint passed; Blade cache cleared. Existing 9 Organization routes retained; no standalone audit or access page added. No screenshots, routine full suites or Playwright were run for this stage, as instructed.
- Existing commercial, cost/payroll, approval and M06 warehouse boundaries remain fail-closed. Login/reset activity begins with actual capture; missing old events and historical effective memberships are not fabricated.

## M01 Stage07 final applicable integration

IMPLEMENTED — QA DEFERRED. Warehouse integration is separately BLOCKED pending M06.

- Recovered actual source/routes/schema: companies singleton constraint (id=1), company branch FK, owner-local persistence/status guards, user session enforcement, dynamic matrix/scopes and both append-only audit streams remain active. No migration or real-data reset was needed.
- Implemented container-bound OrganizationApi with method-specific readonly Request/Result classes under Application/Contracts, scalar projections, current actor/scope checks and safe local ContractException/ErrorCode. Warehouse listing fails closed with IncompleteSetup503; no foreign warehouse/model queries or fake successful grants.
- Luna-authored `.tools/check-m01-contracts.php`: 21 PASS, covering service resolution, readonly/scalar results, safe field projection, exact branch scope/hidden records, revocation/disabled/unverified denial, constructor/query validation, singleton association and blocked warehouse contract. Fixture transaction rolled back in isolated PostgreSQL; existing test-company fixtures are preserved with insertOrIgnore.
- Sequential focused regression: company/branches 29 PASS, users 26 PASS, RBAC/audit 62 PASS, each isolated testing transaction rolled back. No routine full suites/Playwright/screenshots.
- Laravel route inventory: existing 9 Organization routes; no standalone audit/access page. Development and test migration status: all applied. Vite build passed; scoped Pint formatting completed. Authenticated AX-only runtime review confirmed consolidated authorized sidebar and existing User Details tabs. No real mutation in browser.
- Shared shell/headings/cards/filters/tables/forms/status/confirmation/tabs/timeline reused. Authorized company/branch navigation added; unused duplicate sidebar permission entry removed while catalog route remains available. Next Products Stage01 task prepared without M02 implementation.

## M02 Products S01–S07 — IMPLEMENTED — QA DEFERRED, 2026-10-21

Follow-up permission propagation repair: actual development user_roles was empty, despite saved Administrator matrix. Owner-requested linkage of the existing administrator account was recorded with immutable local_cli.owner_requested_role_binding audit. Authenticated browser opened /products and four Products sidebar links without relogin/403. Role/account empty-membership warnings added. Lead reviewed and ran Luna-authored .tools/check-role-permission-propagation.php: final135 PASS across all14 fixed roles, assignment then new grant, current OrganizationApi and catalog/sidebar render, immediate permission/membership revocation, exact scope, no implicit Admin bypass, self-assignment denial, protected security key/read-only role preservation and warning rendering. Separate PostgreSQL test transaction rolled back. Blade cache and Vite build passed; full QA remains deferred.

- Owner authorized the complete catalog request and removed mandatory Product tax profiles and tax-based lifecycle/readiness restrictions. Historical Stage01 preparation is superseded; no tax/Inventory/commercial ledger is implemented.
- Migrations070–073 applied successfully to development and separate PostgreSQL erp_elec_new_test: category/brand/unit masters, products, explicit exact conversions, private media metadata, canonical identifier constraints, brand-name deduplication and optional validated purchase/sale units. No owner data reset, catalog sample seed or automatic role grants.
- Lead reviewed and ran Luna-authored .tools/check-m02.php: **66 PASS**, separate PostgreSQL transaction rolled back and disposable private files cleaned. Covers current default-deny grants, real persistence, hierarchy cycles, canonical SKU/barcode ownership, version conflicts, typed readonly lookup DTOs, bounded selectors, BCMath conversion/dimension/no-rounding, stable identity/units, separate notes/status/media authorization, safe Products audit/history, four Livewire screens and specification-field error mapping, MIME/size/path/download/removal safety. A valid first file plus invalid second image proves product/media/audits/files all roll back together through saveWithMedia.
- Lead reviewed the narrow existing M01 catalog assertion update and reran .tools/check-m01-rbac.php: **62 PASS**, all fixtures rolled back. The catalog now checks its M01 subset plus approved M02 company-scoped keys; security, role self-escalation, last administrator, revocation, audit and user integration checks remain active.
- Laravel boot and nine Products route resolution passed. Blade view cache passed. Final scoped Pint --test passed. Final Vite build passed (existing static-font runtime warnings unchanged). No PHP/Livewire rendering failures remain in scoped checks.
- Independent Luna read-only review found a fresh-install duplicate permission issue between historical050/current catalog and070. Resolved by070 updateOrInsert (no grant changes); no other blocking owner/security/upload issue reported. A full clean installation, comprehensive QA, Playwright, actual responsive browser interaction and screenshot comparison were not run; template rendering is not visual acceptance.
- SKU/base unit/kind/tracking and saved conversion/trade-unit definitions remain immutable through generic editing. Pricing/cost/Inventory integrations remain unavailable, never fake. Import/export screens are not implemented by this request. No remote or production activation.
