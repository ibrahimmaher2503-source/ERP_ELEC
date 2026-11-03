# V1 release readiness and execution record — 2026-10-27

> Phase 0 continuation, 2026-10-27: this file is the preserved pre-stabilization audit receipt. Current incremental changes, remaining blockers and acceptance are recorded in [PHASE0_STABILIZATION_REPORT.md](PHASE0_STABILIZATION_REPORT.md). Its historical absence and V1/V2 labels are not current implementation claims. M07 Projects, M08 Field Maintenance, full M11 Accounting, M12 ReceivablesPayables and M15 Attendance remain owner-required delivery scope; their expansion follows stabilization. No release readiness is implied.

**Final gate: NOT READY.** This is a current execution record, not an acceptance inherited from earlier status messages. See [current status](V1_CURRENT_STATUS_REPORT.md), [all-module coverage](V1_MODULE_COVERAGE_MATRIX.md), [journeys/dependencies](V1_CRITICAL_JOURNEYS.md) and [minimum remaining work](V1_REMAINING_WORK.md).

## Safety and environment

- Workspace `C:\projects\ERP_ELEC_NEW`; PHP8.4.21; PostgreSQL17.11 x64 on local port5443 for the existing separate test database; PHPUnit12.5.40.
- Tests/migrations/fixtures targeted **only `erp_elec_new_test`**, with `APP_ENV=testing`, pgsql, no DB_URL override. `tests/TestCase.php`/phpunit.xml explicitly pin the test DB. Existing local-development check scripts were excluded by the safe runner.
- PHPUnit RefreshDatabase applied current pending test migrations; current test schema contains **111 public base tables and 53 migration records**. Development migrations were not run by this audit.
- New financial check code was authored by a Luna sub-agent as AGENTS.md requires, reviewed and executed by the lead; it lives only in TEMP. Existing application source was not fixed to make tests pass.
- Existing browser helpers use clearly synthetic test identities and persistent test-only master/draft rows. The final financial probe rolls back all of its data and redirects private/mPDF/view storage into TEMP. Disposable restore used its own new cluster, not the development instance.
- Read-only development inspection showed posting disabled, Go-Live unset, no tax validation/mappings/openings/posted receipts/journals/FIFO. This is not a production inspection or a failed acceptance caused by missing owner data.
- Credentials were read only by processes that needed connections and were not copied into reports, logs or commands' printed output. `.env` and `.env.testing` are gitignored; `git ls-files` returns only `.env.example` among those three files. This does not constitute a full secret-history scan.

## Current command results

Numbers below are command-specific, not additive coverage or whole-module percentages. Repeated diagnostic invocations are not counted as additional accepted requirements.

| Command / invocation | Actual result | Evidence and precise scope |
| --- | --- | --- |
| `php vendor/bin/phpunit --do-not-cache-result` | **PASS**, exit0, **37 tests / 94 assertions**, 18.541s | Current installed suite; auth/settings/foundation/local-user coverage. Not Sales/Purchasing/Inventory full acceptance. |
| `php .tools/run-safe-qa-checks.php` | **17 PASS / 4 FAIL / 0 incomplete** of21 scripts; **687 explicit PASS assertions**, 09:22:22–09:31:22UTC | [Current JSON](../qa/evidence/safe-check-run-20261027T092222Z.json) records actual script statuses/completion detection, not merely exit codes. |
| `.tools/check-m05-recovery-edges.php`, test environment, BCRYPT_ROUNDS4 | **PASS**, exit0; `RECOVERY_EDGE_CHECKS=53`, `CAMPAIGN_COMPLETE=YES` | Real requisition/RFQ/offer/PO validation, inactive-source revalidation, scope/private file/XLSX/factory recovery edges. Outer rollback/private fixture cleanup. Does not itself prove FIFO/GRNI. |
| `.tools/check-m05-m06-roundtrip.php`, test environment, BCRYPT_ROUNDS4 | **INCOMPLETE/FAIL**; 30 PASS lines before exception | Draft/edit/approval/partial receipt/idempotency slices pass. Stops at legacy Inventory opening because financial posting is inactive. Exit0 and outer cleanup marker do not make full lifecycle successful. |
| `.tools/check-m06-inventory-concurrency.php` | **PASS** bounded contention scenario | Two real PostgreSQL workers allocate 8+2 from10, available0. Uses directly seeded synthetic stock and a stub SalesOrdersApi. It is not a real approved Sales journey or valued-posting race. |
| `.tools/check-m06-issue-post-concurrency.php` | **FAIL/incomplete**, despite exit0 | Both workers hit financial opening/activation guard; expected single movement/replay outcome is not reached. Current FIFO/GL posting concurrency remains unproved. |
| `php %TEMP%/financial-audit-probe.php`, BCRYPT_ROUNDS4, final reviewed script | **PASS**, exit0; **13/13 named checkpoints**, `all_pass=true` | Real actions, not direct approved/posted fixture states. Synthetic detailed opening/issue and real approved/issued PO→posted partial/final receipts; tax/PDF/retry/rollback/refs/constraints. Trace and exact scalar results below. These are named checkpoints, not PHPUnit assertion counts. |
| `php artisan route:list --json` | **PASS**, runtime bootstrap/route registration | Business routes, setup and PDF controllers registered; V2 middleware visible. Route registration is not business acceptance. |
| `php .tools/composer.phar check-platform-reqs --no-dev` | **PASS**, exit0 | Installed runtime requirements including bcmath, pdo_pgsql, GD and mPDF prerequisites pass. No clean production install or dependency-vulnerability audit claimed. |
| `php vendor/bin/pint --test` | **FAIL**, exit1 | Existing application/factory/migration/route formatting violations; no formatting fixes applied. Lint failure does not prove a financial calculation defect. |
| `npm run build -- --outDir <unique TEMP directory>` | **PASS**, exit0;22 transformed modules,13.98s | Output `C:\Users\N\AppData\Local\Temp\erp-v1-audit-build-e0f1e2cc25f04f3e8bb90a253bdb3b04`; public/build preserved. Existing unresolved Noto font URL warnings remain; visual/font acceptance not inferred. |
| `node tests/E2E/sales-orders.cjs` | **FAIL**, exit1 | Current run11713F4EAD7E,2 recorded checks,3 screenshots. Save click intercepted by unstyled large sidebar after static module requests return HTML MIME. No draft save or business completion pass. |
| `node tests/E2E/round2-masters.cjs` | **FAIL**, exit1 | Current run84C0F8EFCBB9,17 completed browser checks,14 screenshots, zero recorded browser JS/HTTP errors. Final strict persistence helper fails and emits non-JSON exception text. Observed earlier interactions remain bounded evidence; campaign not accepted. |
| Test DB `pg_dump -Fc --no-owner --no-acl`, new cluster `initdb`, `createdb`, `pg_restore --exit-on-error --no-owner --no-acl` | **PASS** current relational restore, restore exit0 | New isolated PG17 cluster on127.0.0.1:25443, DB `erp_elec_audit_restore_test`, matching selected source counts. Cluster stopped afterward. Full application/private-file recovery not accepted. |

The first masters invocation produced an empty evidence directory `E015F63D9137` before useful results. Only the later complete failure artifact `84C0F8EFCBB9` is used for observed interaction counts. No empty directory is a pass or a fabricated screenshot.

### Safe-check campaign: failures are not silently waived

| Failed current script | Actual observed reason | Classification |
| --- | --- | --- |
| `check-m01-contracts.php` | After19 PASS lines, expects warehouse lookup to fail while Inventory is unavailable. Inventory lookup now exists; exception text says no contract error | Stale contract expectation; not demonstrated unauthorized access. Script exits0 but runner correctly marks FAIL. |
| `check-m01-rbac.php` | After5 PASS lines, expects only the earlier M01/M02 permission catalog subset | Stale baseline; later legitimate registered keys are not by themselves an RBAC leak. Script exits0 but runner marks FAIL. |
| `check-m04-sales-orders.php` | After39 PASS lines, expects Sales Returns screen200; current V2 gate returns404 | Stale V2 expectation. Does not justify removing V1 gate. |
| `check-sales-order-factories.php` | After3 PASS lines, anonymous OrganizationApi mock lacks four newly required methods | Outdated check double; exit255. Factory scenario not accepted. |

Passing script assertion counts in that campaign: avatars21; module routes27; M01 company/branch29; users26; Administrator18; role propagation130; demo factories completion marker with zero counted PASS lines; Products66; data operations51; HTTP operation routes12; Egypt demo21; customers56; supplier factories66; primary edit8; readonly role7; quotations76; regressions7. The aggregate687 includes PASS lines before failed scripts too; it is not 687 accepted business requirements.

### New financial probe: final execution evidence

Final script SHA-256 `6BD5AB2A031DB7172A7B08C515BA1DD7A7B84E3AB2E98346B4629904B65F5049`; source and trace paths:

```text
C:\Users\N\AppData\Local\Temp\financial-audit-probe.php
C:\Users\N\AppData\Local\Temp\financial-audit-probe-stdout.json
```

The final trace was written around12:47 Cairo on2026-10-27. The source verifies driver and `current_database()` before writes, opens an outer transaction, uses nested savepoints, and always rolls back. It only fills missing legal fixture fields for the exact synthetic `E2E-SO-COMPANY` identity, within that rollback. It never activates a real company or inserts approved commercial/posting states directly. It forces deferred constraints before reporting acceptance.

| Named checkpoint | Final result / captured values |
| --- | --- |
| Synthetic actors and masters | PASS;3 explicit synthetic actors, real branch/warehouse actions/scopes and master factories. |
| Actual setup / dated rules | PASS;14 required mappings, synthetic Go-Live2024-06-01, two explicit synthetic percentage rules and actual validation action. These are not production values. |
| Detailed opening and internal FIFO issue | PASS; actual balanced opening GL, AP10.00, cash10.00, bank5.00, FIFO2 units/value20.00; preparer self-approval denied; actual issue1.5 cost15.00 with two-line DR=CR15.00, remaining.5; constraints immediate. Entire detailed branch rolled back independently before receipt case. |
| No-balances declaration and activation | PASS; explicit evidenced empty declaration, different approver, actual root activation, zero fabricated opening journals. |
| Exclusive tax arithmetic | PASS;3×.335→net1.01/tax.14/total1.15. |
| Inclusive discount / effective exemption | PASS;2×1.15 minus eligible.10→net1.93/tax.27/total2.20; generic rule applies before synthetic2099 effective date, product exemption yields zero on date; checkpoint rolls back configuration changes. |
| PO issue, PDF, immutability, retry, override rejection | PASS; PO17→PO-2024-000001; document675edec9-dbf5-4d29-a761-31d0e422b84a;47,702 bytes; `%PDF-`; stored/retrieved SHA256 `f8ef1b2dddc0278faffe93162fa5476ee556dfe008d0536f50d8f372bc64f9fb`; UPDATE and DELETE rejected by actual immutable trigger; same issue stable; reported tax999 denied. |
| Unauthorized receipt actor | PASS; no-grant actor denied403; pending receipt and movement counts unchanged. |
| Mid-flight injected failure | PASS; throws at real Purchasing `receipt.posted` event after effects; movements/layers/journals remain0, documents remain1, receipt pending. No financial guard disabled. |
| Partial receipt post/ref/retry | PASS; receipt7 journal12, movement12, layer10; value.34, one movement, journal DR=CR.34; actual line/movement/layer/source references match; repeated post stable. |
| Final receipt/exhaustion | PASS; receipt8 journal13, movement13, layer11, value.67; total1.01; two layers total1.01; actual on-hand3.00000000; final source references match. |
| Pre-Go-Live | PASS;2024-05-31 ordinary posting denied. |
| Deferred constraints immediate | PASS after final real receipt effects. |

An earlier execution had a missing synthetic `balance_view` grant; author corrected that fixture, not application code. Final13-checkpoint evidence above supersedes the intermediate trace without counting its successes again. No HTTP/PDF-controller authorization or concurrent posting result is inferred from this direct-action probe.

## Browser evidence and UI limitations

Current artifacts:

- [Sales result](../../tests/E2E/artifacts/11713F4EAD7E/result.json), [failed screen](../../tests/E2E/artifacts/11713F4EAD7E/failure-state.png), plus login and wide-list screenshots. Five module-MIME browser errors are recorded. Its built-in PHP server uses public/index.php as router instead of the existing static-file router, explaining the missing styling/interception in this harness. Application visual regression is not established by that run.
- [Master result](../qa/round2/evidence/masters/84C0F8EFCBB9/result.json), [product mobile](../qa/round2/evidence/masters/84C0F8EFCBB9/products-create-mobile.png), [customer desktop](../qa/round2/evidence/masters/84C0F8EFCBB9/customers-create-desktop.png), [user details](../qa/round2/evidence/masters/84C0F8EFCBB9/user-details-desktop.png). Correct static router; Arabic RTL, styled master forms, current desktop/mobile captures. No screenshot was fabricated.
- Master run observed product/customer/supplier invalid forms and actual create/reopen/repeated edit/reload; company edits/restoration; branch create/edit; user provision/edit without automatic business grants. It then fails the final helper with `Unexpected token 'I' ... In prepare ... is not valid JSON`. The helper can emit a strict persisted-data mismatch exception rather than JSON. Exact persistence mismatch remains unresolved; earlier interactions are not a substitute for final DB acceptance.
- Temporary master command log: `%TEMP%\erp-v1-audit-masters.log`. Existing helpers have machine-specific Playwright and browser paths; reproducibility is limited. No new browser test code was authored by the lead.

Historical quotation after-fix, purchasing/inventory draft/export and older masters artifacts were inspected as context only. They do not establish current issue/post/delivery, full keyboard/accessibility/loading states or financial setup acceptance. Current route/static-source inspection is not a browser pass.

## Backup/restore: executed, bounded proof

The configured test role can dump but cannot create a database: first `createdb` returned `permission denied to create database`. No extra production/development authority was granted. The auditor continued by initializing a **new disposable local PostgreSQL cluster** with a separate audit role, loopback-only listener on25443 and test-only database; the application cluster/configuration was unchanged.

Dump artifact:

```text
C:\Users\N\AppData\Local\Temp\erp-v1-backup-audit-60df890103d5419ea37cfb1c07aa10dc\isolated-test.dump
SHA256 8E2D3ACC421DA776BB38338F02A15E685C1A6B06310DBEC720B99C3772EF2A8D
```

Restore used `--exit-on-error --no-owner --no-acl`, exit0. New cluster data/logs remain in the same TEMP directory for review; `pg_ctl ... -m fast -w stop` succeeded. No existing DB was dropped or overwritten.

| Query | Source test DB | Restored isolated DB |
| --- | --- | --- |
| Public base tables |111|111|
| Migration rows |53|53|
| Users |5|5|
| Products |2|2|
| Customers |2|2|
| Suppliers |1|1|
| Sales Orders |10|10|
| Official documents |0|0|
| Posted journals |0|0|

This proves current schema and selected nonempty relational records can be dumped/restored. It does **not** prove exact equality of every table, role/ACL recovery, file attachments, populated official-document/PDF or financial dataset restoration, application reattachment/login after restore, backup scheduling/retention/encryption or recovery-time objectives. Financial probe rows were rolled back and therefore not present in this dump. Historical report claims of a different restore were not accepted as current runtime proof.

## Security and operations readiness

| Area | Current finding | Gate |
| --- | --- | --- |
| Login/session | Fortify login with active-account checks; verified business routes; session_version middleware invalidation; current auth suite passes; current master browser login succeeds | Bounded PASS; full session/security browser matrix still P1. |
| Registration | Public registration disabled in Fortify config; managed users used | SOURCE VERIFIED plus existing auth checks; no fabricated registrations. |
| Administrator | Explicit assigned role receives all registered current/future keys; synchronization action and audit additions exist; matrix read-only. Display name is not membership. Other roles default-deny | Current defaults/propagation checks PASS. Maintain scopes, active/verified status and independent approval invariants. |
| Action-level permissions | Owning actions/API consumers authorize, not merely screen navigation. New receipt actor without grants denied. New PDF controllers check source view/branch before retrieving private bytes | Broad bounded PASS; new financial HTTP negative matrix NOT TESTED. |
| Branch/warehouse isolation | Owner queries filter scopes; Inventory mutations check warehouse permission/scope and both transfer endpoints; exports use scoped allowlists | Source plus existing negatives verified; actual full financial transfer/Sales negative matrix missing. No bypass demonstrated here. |
| Audit/history | Security owner local audit and System business audit; append-only snapshots; user history tabs and actual actor events; no destructive posted edits | SOURCE VERIFIED and bounded existing history tests; complete new financial event acceptance missing. |
| Financial immutability/rounding | NUMERIC/decimal strings/BCMath; two-place balanced postings; source-control restrictions and immutable triggers | Current opening/receipt/issue/PDF positive tests PASS. Unsupported sources fail closed. |
| Tax legality | Configured treatment and validation reference required; no universal runtime VAT default; actual company treatment pending | Activation prerequisite. No external legal/tax compliance certification claimed, no need to block isolated development. |
| Errors | Validation/version/readiness messages keep unsupported workflows closed; root financial failure rollback verified | PASS bounded rollback; several legacy checks mask exceptions as exit0. Correct harness reporting before acceptance. |
| Settings/secrets | Test/dev DB separation explicit, environment files ignored, no credentials written to reports | SOURCE VERIFIED; no full secret-history/deployment-hardening audit claimed. |
| Migration safety | Current schema built by test suite; new migrations add financially controlled tables/triggers; history-preserving down methods are not a tested rollback plan | Fresh test-schema PASS; populated upgrade rehearsal NOT TESTED. Do not migrate development/production as audit work. |
| Health | `/up` framework liveness route registered | SOURCE VERIFIED; DB/private storage/worker readiness under actual deployment NOT TESTED. |
| Build/install | Installed production platform requirements and isolated Vite build pass; Pint fails; Composer setup exists | Not a clean deploy/install rehearsal. Generic setup key generation is not a safe production upgrade/key-retention procedure. |
| Backup | Current relational restore succeeds in disposable cluster | Partial PASS; complete DB/private-file/application recovery and operator runbook P1. |
| Deployment docs/candidate | Ship and source notes exist, five audit reports now provided; root README/operator installation manual absent; working tree already dirty | Release candidate packaging, supported commands, migrations, secrets/key continuity, storage rights, workers, health and rollback/recovery checklist incomplete. No remote publication authorized. |

## Final release gates

| Gate | Result |
| --- | --- |
| Frozen V1/V2 boundary | SOURCE VERIFIED. |
| M01–M03 core | Bounded RUNTIME/BROWSER evidence; not exhaustive acceptance. |
| Real Sales journey | **BLOCKED** by missing commercial/approval/delivery implementation. |
| Real Purchasing journey | **PARTIAL**: core positive backend PASS; projections/browser/concurrency incomplete. |
| Real Inventory journey | **PARTIAL**: controlled opening/internal issue PASS; valued transfer/positive adjustment blocked. |
| Financial activation/core | Bounded synthetic RUNTIME PASS; customer activation intentionally disabled. |
| Current regression/lint | **FAIL** as a release aggregate: four stale checks, incomplete legacy campaigns, Pint failure. |
| Browser release acceptance | **FAIL** overall; no complete current three-journey pass. |
| Actual valued-source concurrency | **NOT TESTED** successfully for current real producers. |
| Complete recovery/installation | **PARTIAL** relational restore PASS; remaining operator/file/financial recovery unaccepted. |
| Production activation | **DISABLED**, correctly awaiting real data/configuration/independent approval; not a development blocker. |
| Delivery readiness choice | **NOT READY**. |

An internal developer demonstration of master data or the tested financial backend is possible; that is not readiness of the frozen V1 business pilot. Close the concrete [P0/P1 items](V1_REMAINING_WORK.md), retain V2 deferrals, and require current evidence before changing this decision.
