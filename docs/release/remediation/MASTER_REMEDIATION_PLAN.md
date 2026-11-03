# Master production remediation — 2026-11-01

Owner instruction: attachment `dda91acf-ab97-4802-9579-31e4974cc2a9/Pasted text.txt`. Implementation and isolated acceptance are authorized; production deployment, invented company policy/data and unrelated commits are not. Original audit reports and FAIL receipts remain historical evidence.

## Candidate and preservation

Branch `main`, base commit `28cca25ef242bbcdcfcd29a976f8e3a552768a87`. This commit is not the working release. The private checkpoint is `.tmp/master-remediation-20261101/checkpoint/`: exact expanded dirty/untracked inventory, binary tracked patch, untracked archive and SHA-256 source inventory. These private artifacts must never enter Git. They preserve the existing work rather than resetting or committing it. The original audit used a collapsed status count of 556; this checkpoint records individual files, so its count is not comparable.

Physical owners at entry: Accounting, FieldMaintenance, Inventory, Organization, Parties, Products, Projects (including Tenders), Purchasing, ReceivablesPayables, Sales, System, Treasury and WorkshopRepairs. M10/M15 are absent. Current migration source ends at 198; actual database state is recorded separately by read-only inspection. Returns remain disabled; Projects/Tenders enabled. No release flag is an acceptance certificate.

Existing audit evidence: `../evidence/production-readiness-20261101/`. Three financial P0 reproductions genuinely failed; the full PHPUnit result was 41 passed/16 fixed-port guard failures, not a green suite. Bounded Projects/Maintenance/Tender and restore results retain their original limits. The unattributed CSS/build drift requires a new source/build boundary.

## Execution gates

| Gate | Work and exit condition | Initial state |
|---|---|---|
|0|Preserve candidate, inspect owners/contracts/evidence, pin source and runtime|VERIFIED PASS for baseline capture; exact release artifact still Gate8|
|1|Full-company close authorization/serialization/evidence; common historical cutoff; protected control journals. Original three reproductions plus affected positive/negative/concurrency regressions must pass before feature acceptance.|Focused financial acceptance PASS; full-system/portable CI rerun remains open. See FINANCIAL_P0_CLOSEOUT.md|
|2|M10 owner, lifecycle, entitlements, M07/M08/M09/M12 integration, documents, factories/idempotent demo and acceptance|NOT TESTED|
|3|M15 using real M01 employee authority; shifts/punches/corrections/leave/overtime/locked timesheets, exports and acceptance|NOT TESTED|
|4|Explicit M14/M16 release proposal; no fabricated scope approval or payroll policy|Owner approved deferring independent M14/M16 on2026-11-01; owner reports/dashboard remain included. See RELEASE_SCOPE_PROPOSAL.md|
|5|Physical returns, valued serial lifecycle, advance/refund/correction and external statement matching with owning source integrity|NOT TESTED|
|6|Compatible security patches and complete stateful ordinary-role/scoped/disabled/revoked/object denial matrix|NOT TESTED|
|7|Real scoped dashboard projections and consistent responsive RTL page families; five viewports and all required states|NOT TESTED|
|8|Classify actual candidate, exclude secrets/runtime, build pinned reproducible manifest without unrelated commits|NOT TESTED|
|9|PostgreSQL clean-runner CI, locked dependencies, extensions, build/audit/tests; clean and controlled previous-schema upgrade|NOT TESTED|
|10|Preserve existing optimization, profile supported operations; prepare realistic 5/20/50/100-user staging harness|NOT TESTED; target acceptance requires selected staging|
|11|Target deployment/supervision/queues/scheduler/health/monitoring and coordinated encrypted offsite backup/restore artifacts|NOT TESTED; target evidence requires selected infrastructure|
|12|Validated company inputs/imports/reconciliation and role-based UAT/training/cutover/incident procedures|NOT TESTED; real inputs and operator sign-off external|
|13|Correct current documentation, retain historical receipts, scoped Pint excluding generated files/applied migration style changes|NOT TESTED|
|14|One final financial/operational/security/browser/technical/restore acceptance on the fingerprinted candidate|NOT TESTED|
|15|Machine-readable closure matrix for all 24 findings, exact evidence and final release decision|IN PROGRESS; no finding closed yet|

The lead owns production business/security changes and integration. Luna authors all new test/check code as required by AGENTS.md. No uncontrolled concurrent application writes. Every gate records the initial failure, fix, exact command, positive/negative result, residual dependency and candidate hashes. A written implementation alone cannot become VERIFIED FIXED.

External boundaries: actual M14/M16 scope approval, missing company-specific business policy, target selection/access/secrets, approved opening balances and operator UAT, production authorization. Record precise missing input; continue other independent authorized work without repeatedly asking routine development permission.

Current release decision remains **NO-GO — RELEASE BLOCKERS REMAIN** until closure evidence changes it.
