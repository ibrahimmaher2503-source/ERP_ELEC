# Phase 2 SQL findings

No new SQL/index/cache change is delivered in the UI unification batch. Gate A acceptance precedes optional performance changes. Existing installed Debugbar and Pulse remain available with their original restricted opt-in configuration; no overlapping monitoring package is installed.

Preserved Phase 1 evidence: `ERP_NATIVE_PROFILING_RESULTS.json`, `ERP_QUERY_PLAN_SUMMARY.json`, `ERP_SOURCE_REVIEW.md`, `ERP_MODULE_PERFORMANCE_MATRIX.md`, and `ERP_PERFORMANCE_FINAL_REPORT.md`. Debugbar timings are diagnostic requests, never replacements for uninstrumented browser measurements.

| Finding | Existing measured evidence | Next justified action |
|---|---|---|
| Authorization amplification substantially reduced | User details 3,724 → 21 SQL statements; approximately 2,038 → 138ms DB time in the historical profile | Inspect the remaining actual fingerprints before changing authorization. Keep request-scoped semantics and immediate next-request revocation. |
| M06 transit receipt lookup remains per line | Explicitly retained as a bounded remaining opportunity in Phase 1 source/final review | Capture current requests at meaningful line cardinality, then batch only if actual evidence supports it. Preserve BCMath accepted-plus-damaged subtraction and stock locks. |
| Project report financial summaries | Remaining per-project read projection cost documented in Phase 1 | Profile actual report data/permissions; do not invent financial totals or add cross-owner table reads. |
| M02 media scan | Actual selected EXPLAIN execution was 1.393ms on the synthetic fixture | No speculative index for a small scan. Revisit with material cardinality and current plans. |
| M11 reconciliation scan | Actual selected EXPLAIN execution was 0.382ms | Retain correctness/transaction rules; no broad indexing/caching without evidence. |

No new all-module profiling pass, forward migration, PostgreSQL tuning or unsafe authorization cache is claimed here. Raw SQL/bindings remain private. Financial/customer data must not be copied into the public reports.

## Fresh native aging diagnosis after UI migration

One read-only, development-only Debugbar request on owned PostgreSQL 55461 observed 13 SQL statements / 124.07ms total, slowest 97.91ms, no repeated fingerprints, no exceptions, 8.5MB peak, 657 template entries. Instrumented request was 945.64ms; do not substitute this for the final uninstrumented aging UI medians 556ms desktop / 619ms mobile. Large instrumented application/view overhead is a follow-up lead, not proof of a new N+1 or justification for speculative indexes. Raw bindings stay in `.tmp/page-speed-audit/native-debugbar-aging-profile/`; public UI report carries only sanitized counts/times.

Final browser control run: `docs/qa/design-consistency/2026-11-01T01-28-37-638Z/after/`, 18/18 healthy samples, Debugbar/Pulse disabled. It resolves the earlier large UI-control timing outlier in a quiet run; it is not the required full Phase 2 166-case performance re-benchmark.
