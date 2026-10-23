# M01 UI specification — approved unified access management

Branch and warehouse scope management is integrated into User Details. Role permissions remain managed through Roles & Permissions. No standalone access-management page is required.

Roles & Permissions manages the fixed 14 roles, dynamic operation matrix, module-grouped catalog/search, descriptions and assigned user counts. Members are read-only here; assignment lives in User Details. No role-scoped branch/warehouse grants are introduced.

User Details embeds the reusable «الأدوار ونطاقات الوصول» section for explicit verified security administrators. It assigns/revokes roles, manages independent branch scopes and existing direct grants, shows scope state and effective operation/location access, and displays relevant audit history. Self mutations remain blocked. Non-security viewers retain only their authorized read-only account/branch summary.

Create User explains that access configuration follows account persistence; an authorized security administrator who can view the new account is taken to its details. Edit User links to the same section rather than creating a competing access editor. Primary branch is organizational metadata, not a grant.

Warehouse access is visibly blocked pending M06 Inventory's owning lookup and branch/warehouse consistency contract. No placeholder warehouses/selectors or mutations are supplied. Scope grants alone confer no operation. Existing direct grants are independent and must be separately revoked; the effective summary uses the authorization engine. Inactive accounts have no effective privileges, and verified login remains necessary.

The standalone access route/view was removed after caller inspection. No data or audit records are removed. Existing shared shell, cards, selectors, badges and confirmations are reused. Delivery remains IMPLEMENTED — QA DEFERRED after essential checks.

Stage06: the existing User Details page now hosts four tabs, including separate permission history and security activity. Access history no longer appears as a duplicate block below the grants editor. A compact profile header and shared audit timeline are reused. History/security data requires both explicit security authority and current target-view permission. See m01-audit-security.md.
