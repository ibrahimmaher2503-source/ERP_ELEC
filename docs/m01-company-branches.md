# M01 stages 01–02 contract

Authority: MODULE_PRDS M01, database dictionary M01, WF-00. PostgreSQL overrides imported SQLite notes. One company, ID 1; required unique code/name, optional legal_name/tax_registration/address; EGP fixed. No invented city/contact/logo/registration fields. Setup is incomplete until an authorized operator supplies company code/name. Arabic is current presentation locale, not a new editable company field.

Branches: company_id=1; unique normalized uppercase code, name, optional address, active, version. No delete. Optimistic versions and transaction locks reject stale edits. Deactivation confirms and rejects assigned active users; no transactional owners exist yet. Future transactional modules must integrate their open-work eligibility contracts before exposing branch-dependent work.

Explicit current grants: M01.company_view / M01.company_update (no branch dimension); M01.branch_create (no branch dimension); M01.branch_admin (company-level branch master administration ONLY, no transaction/location authority). Delegated M01.branch_view / M01.branch_update / M01.branch_status grants require exact branch_id. Null scoped grants never mean all branches. Disabled actors denied on every operation. Directory statistics/search/pagination use authorized branches only. Local bootstrap command explicitly grants these company-level setup permissions to a chosen existing local account; no public grant editing, role bypass or automatic auth grant.

Organization writes its masters; System.RecordAudit owns immutable safe audit_entries, called in the same transaction. No foreign business queries. Grants changes use Organization access_audits. Company/branch edits require current permissions inside actions. Branch create requires configured company; setup code uniqueness and PostgreSQL checks enforce invariants.

Routine suites/browser automation/visual parity deferred per current delivery brief. Required checks: boot, routes, asset build, PostgreSQL migrations and disposable local authorization/mutation smoke checks.
