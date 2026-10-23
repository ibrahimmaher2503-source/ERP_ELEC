# M01 Stage07 — Organization integration checkpoint

This consolidation records implemented contracts, not production or full QA acceptance. Company, branches, users, fixed roles, operation matrix, user-specific branch grants and embedded audit/security share the existing Organization owner and ERP shell. The 9 Organization routes contain no standalone access or audit screen. No new business producer, commercial delegation rule or warehouse dataset was invented for integration.

| Contract | Current responsibility |
| --- | --- |
| OrganizationAccess | Authorized company identity and branch reads using PermissionAccess |
| UserAccess | Authorized account directory/detail/target operations; primary branch is metadata |
| PermissionAccess | Known-key default deny, explicit direct grants or role capability plus independent scope; no warehouse authority |
| SaveCompany / SaveBranch / SaveUser | Owner-local validation, authorized persistence, concurrency and safe MasterChanged events |
| SaveRolePermissions / AssignUserRole / SetUserBranchScope / RevokeDirectGrant | Serialized, reauthorized, audited security changes; immutable invariants |
| UserSecurityHistory | Current security and target-view gates before user-specific audit reads |
| Organization.Domain.UserActivityReader | Internal typed read contract returning safe user activity; caller must authorize |
| System.ReadUserActivity / RecordAudit | System-owned append-only history reads/writes; root provider binds/bridges contracts |
| MasterChanged | Safe event metadata with nullable authenticated actor and explicit outcome; never credentials |

Company/branch/account changes and required audit records remain synchronous. Effective revocation is checked at the next authorized request without permission caching. Shared shell/components are presentation-only. Scope editing and user-specific security history live in User Details; role operation matrices remain in Roles & Permissions. Both security authority and target-view access remain required for sensitive history.

M06 warehouse integration remains blocked on its producer and branch/warehouse consistency contract. Financial, payroll and approval authority remain absent until approved operations/policies exist. This local checkpoint does not mark all M01 QA ACCEPTED.

## Stage07 executable public API

`App\Modules\Organization\Application\Contracts\OrganizationApi` is container-bound to OrganizationService. It follows the approved one-readonly-request / one-readonly-result convention from 05-MODULE-CONTRACTS-EVENTS.md. Only the three required producers are implemented; no speculative 17-owner scaffolding or generic dispatcher.

| Method | Request / Result | Runtime behavior |
| --- | --- | --- |
| authorize | AuthorizeRequest / AuthorizeResult | actorId, permissionCode, recordBranchId, optional warehouseId, requestedFields; current active verified actor, explicit operation/scope and safe field allowlist, or Forbidden |
| getBranch | GetBranchRequest / GetBranchResult | actorId, branchId and query context; singleton company branch, authorized exact scope, scalar field projection only; hidden/unavailable branch yields NotFound |
| listAllowedWarehouses | ListAllowedWarehousesRequest / ListAllowedWarehousesResult | validates identity/query context, then raises IncompleteSetup503 while M06 producer/grants are absent; never returns invented warehouses or successful empty authority |

Query DTOs carry scopeBranchId, warehouseIds, requestedFields, filters, page and pageSize (1..100); selected scope never grants access. Unsupported filters are rejected rather than silently ignored. Positive identifier/page checks run in constructors; current grants, nested values and field projections are validated at the producer. No Eloquent model escapes. AuthorizeResult carries allowedFields and explicit branchIds/warehouseIds; an empty location list is not a wildcard. ContractException exposes a local ErrorCode and safe HTTP-equivalent code. Consumers supply actorId from authenticated session/job identity, never a browser-selected actor. No public HTTP dispatcher was added.

M01 navigation now uses actual current grants and consolidates the role matrix/catalog under one sidebar entry. Company and branch pages cross-link only when the target operation is authorized. Existing data, migrations, audit records and shared components remain preserved.
