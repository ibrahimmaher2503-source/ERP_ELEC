# M01 stage 04 — Dynamic roles and permissions

IMPLEMENTED — QA DEFERRED. Owner clarification on 2026-10-21 authorizes an administrator-managed database matrix. No guessed commercial default matrix is supplied.

## Fixed roles approved by the owner

| Stable code | Arabic name |
| --- | --- |
| Administrator | مدير النظام |
| FinanceManager | المدير المالي |
| Accountant | محاسب |
| Cashier | أمين الصندوق |
| SalesClerk | مسؤول المبيعات |
| Buyer | مسؤول المشتريات |
| Storekeeper | أمين المخزن |
| WarehouseManager | مدير المخازن |
| ServiceManager | مدير الصيانة والخدمة |
| Technician | فني صيانة |
| WorkshopOperator | مشغل الورشة |
| HR | مسؤول الموارد البشرية |
| PayrollOfficer | مسؤول الرواتب |
| ManagerReadOnly | مدير للقراءة فقط |

No role creation/deletion is exposed. Latest owner decision2026-10-21: explicitly assigned Administrator has every current/future registered permission by default; its matrix is complete and read-only. Other role permission sets remain manually configured/default-deny. An account display name never implies Administrator membership. ManagerReadOnly accepts implemented view permissions only.

## Engine and boundaries

Organization owns permission_catalog, roles, role_permissions, user_roles and user_branch_scopes. PermissionCatalog defines stable keys for implemented M01 operations; administrators edit assignments, not the catalog's technical keys. The catalog includes the implemented owner permission keys. Registering a new key makes it available to Administrator automatically; other roles require explicit assignment. Permissions never implement a missing producer or bypass commercial/financial business checks.

PermissionAccess requires an active account and a known key. Company permissions require company scope. Branch role permissions require both the capability and an explicit matching user_branch_scopes entry. Primary user branch is never an access grant. Existing access_grants remain an independent explicit source: removing a role scope does not remove a direct grant, which can be separately revoked in the access screen. Warehouse requests deny until the Inventory producer/security contract exists.

RbacGuard requires an active, email-verified holder of M01.security_admin for every security screen and mutation. Authentication alone grants nothing. No permission cache is used: changes apply on the next authorized request. Existing OrganizationAccess/UserAccess queries consume the engine; financial/business invariants cannot be bypassed by matrix edits.

All security mutations use one transaction and PostgreSQL advisory lock. Role version checks reject stale edits/assignments. Users cannot change their own role assignments, scopes or direct grants, or edit any role assigned to themselves. The security key is permanently retained in Administrator and cannot be added to another role. An active verified security administrator must remain. Account status/email changes, including self-service email changes, preserve that invariant. Account operators without security authority cannot take over security-designated accounts, including inactive accounts.

Security changes append before/after snapshots to immutable access_audits, omitting credentials and session secrets. Role details show the latest 30 role-specific records and permission differences. Existing business master-change auditing remains separate.

## UI and local bootstrap

- /organization/roles: actual summary counts, searchable eight-row role pages and an adjacent selected-role matrix on desktop; stacked panels on smaller screens. Shared RoleDetailsPage renders the embedded matrix as well as the standalone screen.
- /organization/permissions: module-grouped permission catalog and matrix entry link.
- /organization/roles/{role}: editable operation matrix, protected controls, read-only assigned members and history.
- /organization/users/{user}: reusable «الأدوار ونطاقات الوصول» section handles role assignment/removal, independent branch scopes, existing direct-grant revocation, effective access and relevant audit history. Security administration and existing account-view authorization remain separately required.

Stage05 owner decision supersedes the former standalone access screen: Branch and warehouse scope management is integrated into User Details. Role permissions remain managed through Roles & Permissions. No standalone access-management page is required. Existing tables and audited Actions are reused without migration; new branch grants require active account/branch, while revocation remains available for inactive records. M06 warehouse integration remains blocked.

Visual refinement uses the supplied 1536×1024 reference: pale panels, compact table, selection highlight, green permission switches, module grouping, local permission search and Arabic pagination. Switches edit a draft; explicit Save reauthorizes and persists it. Locked/security/read-only/self restrictions remain enforced server-side. Counts and role labels are real data; reference-only role creation, role activation and fabricated permission totals were not introduced.

The local-only command `php artisan erp:bootstrap-security {email}` authorizes one existing active verified account to configure RBAC. It records only the explicit security grant and audit event; no business capabilities or branch/warehouse scopes are added. It was applied to the existing local administrator; credentials are not documented here.

Missing commercial delegation rules block only their future sensitive operations. They do not block this RBAC engine. Full QA, automated browser suites and production acceptance remain deferred.


## Owner standing decision: all Administrator permissions by default (2026-10-21)

This latest owner instruction supersedes earlier no-automatic-business-grants receipts, including M02/M03/M04 delivery notes. `PermissionAccess::roleCapability` grants every known key to users explicitly linked to the `Administrator` role; unknown keys still deny. `allows` and owner contracts retain active/verified account checks, scope restrictions and business-state requirements. Disabled accounts and removed memberships do not retain authority. No email/name heuristic or automatic role assignment was added.

`SyncAdministratorPermissions::execute()` synchronizes actual role_permissions rows under the existing RBAC advisory lock, preserves all other records and adds one `role.administrator_defaults_synced` audit plus a role-version increment only when permissions are added. Migration114 applies the policy to existing data. A MigrationsEnded(up) listener repeats this synchronization after future module migrations. No writes or fake audit records occur during ordinary permission reads. Future valid keys are effective for the role even before a missing matrix row is synchronized. No wildcard accepts unknown permission strings.

Administrator's displayed matrix is full and read-only; manual matrix edits cannot remove these defaults. Other role matrices remain editable under existing anti-self-escalation, read-only-role and last-security-administrator protections. Existing account, branch and warehouse scopes are not rewritten. Quotation self-approval remains disabled unless configured, and tax/pricing/issue blockers remain business requirements.

Automated synchronization has no human actor or user target. Migration114 permits null actor/subject in access_audits for these real role-level events without rewriting any existing entry or removing append-only triggers. Explicit actor-based invocation remains security-authorized and attributed.

Verification: lead reviewed and ran18 focused PostgreSQL Administrator-default assertions with rollback. Actual active/verified local user2 GET dashboard and Quotations returned200 with the Sales navigation link, in a read-only transaction. Scoped Pint and Blade checks passed. The local Administrator matrix is synchronized; no further per-module enablement is required.
