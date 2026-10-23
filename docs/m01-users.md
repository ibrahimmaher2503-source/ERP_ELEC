# M01 stage 03 — Users contract

Single Organization owner. Reuses users, branches, access_grants and synchronous safe master-change audit. No employee, avatar, phone, user-language preference or role data is invented from the reference. Current Cairo offset is calculated; last_login_at is written by successful Laravel Login events, not inferred from page visits.

Explicit M01.user_admin at company scope administers accounts only. It grants no financial, stock, payroll or transaction authority. M01.user_create may provision accounts without business grants. Delegated M01.user_view/update/status/password_reset require the target's exact primary branch. Null branch scope is never a wildcard. Directory counters, filters and details share the authorized query. Scoped operators cannot manage targets with global, cross-branch or sensitive grants; there is no implicit administrator role bypass.

Create: name/email, confirmed password (12+ mixed case and number), optional active primary branch; active account, unverified email, no grants. Uses existing verification flow. Update: identity/primary branch only; changing email clears verification and revokes sessions/reset tokens. Moving a scoped target requires authority on both old/new branches; primary branch never grants operational access. Existing branch assignment can be retained while inactive, but cannot newly assign/reactivate against an inactive branch.

No self administrative mutation: personal settings remain the self-service surface. No hard deletion. Version checks reject stale mutations. Status and password resets require separate confirmation; reset hashes passwords, clears reset tokens, invalidates database sessions and rotates remember/session versions. Current-session middleware rejects disabled/version-stale identities. Password/passkey login checks active state. Audit snapshots omit password hashes, tokens, MFA secrets and session payloads.

Stage 04 is implemented under the owner's dynamic-RBAC clarification and exact 14-role list; see m01-rbac.md. Account list/details display actual assigned roles. Security controls link to the real matrix/access screens only for explicitly authorized security administrators. Stage 05 warehouse grants depend on Inventory's warehouse producer. Account audit/session enforcement remains active; broader future stages are not claimed complete.

Delivery: IMPLEMENTED — QA DEFERRED after recorded bounded checks. Full suites, Playwright and automated visual parity remain deferred.

2026-10-21 owner addition: managed create/edit includes required gender male/female, persisted by a PostgreSQL check constraint; legacy values stay null. The profile settings also permit an active user to change only their own gender. Cosmetic portrait selection grants no access and uses the local fictional gender-specific pools described in default-avatars.md.

Stage05 unified access: User Details contains the reusable «الأدوار ونطاقات الوصول» section. Explicit verified security administrators who can view the account assign/revoke roles, independent branch scopes and direct grants; view effective access and relevant audit history. Create/edit flows lead to this section after persistence. No standalone access page or role-editor assignment controls remain. Warehouse mutations remain blocked pending M06. No schema migration or grant/history deletion was required.
