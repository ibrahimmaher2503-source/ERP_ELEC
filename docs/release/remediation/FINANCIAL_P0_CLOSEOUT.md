# Financial P0 remediation — Gate 1

Status: **Gate1 focused financial acceptance PASS; full-system/hosted-CI acceptance remains open**. Date 2026-11-01. All effects use marker-owned PostgreSQL test databases on127.0.0.1:49685; development remains at migration198, financially inactive with NULL Go-Live. Migrations199/200 were applied only to disposable test databases. New employee migration201 is subsequent, unaccepted work.

## Original failures retained

`evidence/gate1/financial-release-risks.json` reproduces all three original failures on unchanged source, before this implementation. It has equal before/after counts and rollback_verified=true. The original audit receipts under `../evidence/production-readiness-20261101/` are unchanged.

## Implemented changes

| Finding | Root cause | Current change | Executed acceptance so far |
|---|---|---|---|
|PR-FIN-001|Close iterated actor-visible branches, including an empty set, without full-company authority or a common posting lock.|Organization supplies the complete company set, including inactive branches, after company-close and every branch's report authorization. Close takes the common10401/11001 transaction locks before period rows. M12/Treasury posting now acquires that gate before source rows. Migration199 stores immutable, digest-checked closure evidence; success audit and status share its transaction. Reopening retains evidence.|Original no-report actor now receives Forbidden. Whole-company positive close in the financial source journey passes with explicitly complete synthetic report authority. Partial scope/mismatch/immutable receipt/concurrency matrix pending.|
|PR-FIN-002|Dated GL was compared to mutable current FIFO/open-item/credit/advance/GRNI totals.|The same inclusive business-date cutoff reaches Inventory, Purchasing, M12 and Treasury. FIFO replays original layer values and immutable allocated cents, including source-linked corrections and transit; it never subtracts from mutable current balances. Receipts/opening use their effective date; later return/transfer receipt recognition uses the new movement date while preserving original FIFO age. M12 replays original items, dated settlements and posted credits; GRNI uses receipt/invoice dates. Treasury supplies its dated movement provenance through its own API.|Original2000-12-31 reproduction now gives GL0.00/source0.00/difference0.00. Positive current-date financial source graph passes. Full dated chronology, corrections/returns/transit and closed-period stability pending.|
|PR-FIN-003|A duplicated manual-control list omitted optional advance mapping; lifecycle/mapping boundaries did not share the rule.|Mapping metadata centrally identifies source-owned controls, including optional customer advances/accruals and tax controls. Manual create/submit/approve/post/reversal consult the central guard. Optional mapping takes the common lock and refuses non-draft manual use. Valid ordinary journals and source-owned posting remain separate authorized workflows.|Original unsupported advance-control journal now fails at creation, without journal/line/source delta. Genuine M12/Treasury advances remain in the passing positive source graph. Full lifecycle/remapping/reversal matrix pending.|

The exact current PASS reproduction is `evidence/gate1/financial-release-risks-green.json`:3/3 PASS, rollback_verified=true, selected12 table counts unchanged. `phase1-financial-actions-green.json` records the positive source-action journey. Luna authored the checks; the lead reviewed the changed expectations and fixture authority. Expected forbidden exceptions are not reported as successful closes. The earlier incomplete positive-close fixture attempt is retained separately, not treated as an application failure.

## Cutoff contract

Inclusive `Y-m-d` is the effective business/journal date, not the wall-clock insertion time. UTC timestamps persist; Inventory movements whose business date is the posting day use Africa/Cairo conversion. Return/transfer layers retain original FIFO age but become assets only on the new movement day. Historical reports are replayed from immutable source amounts and dates. Transactions backdated into a closed period must fail atomically at the posting boundary; legitimate later-dated activity must not change an earlier cutoff. An authorized reopening deliberately permits restatement and produces a new closure version; previous closure evidence remains immutable.

## Exit still required

The originally pending focused checks now pass: see `evidence/gate1/focused-regression-results.json` (M06 113, M05/M06 68, supplier invoices, journal reversal, financial source journeys, FIN-P0 3/3, Project financial controls, close/control28), the two real close/post order receipts, and `evidence/gate1/historical/` (Inventory13 named scenarios, AP/AR/Treasury16, full M08 financial36 with dated unused return, controlled genuine old-producer opening→migration200→partial settlement). Branch-B mismatch is explicitly an owner-contract orchestration test double; source histories use actual Actions. No denied close audit leakage; closure UPDATE/DELETE protection and reopen/reclose history passed.

Remaining final acceptance: rerun both race orderings through the new portable CI orchestration on its nonce-bound5432 target, full Laravel suite, final system/browser/security/dependency/build/formatting regression after subsequent features. The current portable race refactor was locally smoke-tested for close-first; collect-first passed before the portability refactor. Hosted CI was not executed. Do not upgrade these bounded results into whole-release readiness.

Production deployment and activation have not occurred. Full release remains **NO-GO — RELEASE BLOCKERS REMAIN**.
