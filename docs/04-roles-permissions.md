# Authorization
Identity uses Laravel Fortify. ERP accounts are provisioned, not publicly registered. Account history must survive deactivation; no self-service account deletion.
Organization owns role/action grants and branch/warehouse scopes. Missing scope denies. Cost/payroll/sensitive fields require independent grants; login is not business authorization. Every action, selector, total, download and queued export must reauthorize current scope.
The owner approved 14 exact fixed role codes and dynamic matrix management; see m01-rbac.md. No implicit administrator bypass or default commercial delegation matrix exists. Approval thresholds and future sensitive operations still require their own exact policies.

Branch and warehouse scope management is integrated into User Details. Role permissions remain managed through Roles & Permissions. No standalone access-management page is required. See m01-ui.md; warehouse mutations stay blocked pending the M06 contract.
