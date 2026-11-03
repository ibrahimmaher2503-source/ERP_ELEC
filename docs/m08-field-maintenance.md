# M08 Field Maintenance

M08 owns service requests, work orders, assignments, visits, checklist snapshots,
time records and completion evidence. It uses the existing Arabic RTL application
shell. Runtime identity is `FieldMaintenance` in `config/modules.php`; M15 remains
Attendance. The release decision and executable acceptance receipts are in
`release/PHASE3_FIELD_MAINTENANCE_REPORT.md`.

## Operating flow

Requests are saved as drafts, submitted and accepted before conversion. Creation
keys make request/work-order retries safe; versions reject stale changes. An
independently authorized standalone order is also supported. Branch membership,
active verified accounts and each registered `M08.*` permission apply separately.
Administrator permissions follow Organization synchronization, with no automatic
branch or warehouse scope.

Work-order tabs contain assignment, scheduling, visit/checklist evidence, parts,
labor, costs, billing, private documents and history. Multiple assignments support
a team. Overlapping schedules require explicit override authority and a reason.
Planned visits do not create actual labor. Completed visit records and checklist
snapshots remain immutable; corrections use later visits and authorized reopening.
All stored timestamps are UTC; forms and displays use Africa/Cairo.

Completion requires an actual completed visit, resolved mandatory checks, recorded
labor for executed visits, the required customer acknowledgment and reconciliation
of stock issues/returns/reservations. Verification creates a private official PDF.
Closure retains evidence and ends active assignments. Billable closure requires a
visible M12 billing handoff, without settling receivables or requiring payment.

## Owner contracts

- Organization supplies scoped user identities and permissions. Parties validates
  customer/site/contact relationships. Products supplies real catalog identities
  and units. Projects validates open project/customer/branch and planning links.
- `FieldMaintenanceApi` publishes Inventory demand and issue authorization,
  reconciliation authority, verified billing evidence and project work-order
  projections. Consumers do not query M08 tables.
- Inventory owns reservations, maintenance issues, exact-source unused returns,
  stock balances and FIFO. Requests alone never deduct stock. M08 stores genuine
  movement/journal references. Authorized stock and mandatory GL effects share the
  caller's transaction and replay identity.
- Accounting owns posting, approved tax calculation and project dimensions.
  `AccountingApi::inventorySourceProject` returns frozen attribution from the
  posted original inventory source, under posting authority, for exact-source
  returns. Inventory does not query Accounting tables.
- Approved labor rates require a persisted proposal and a different approver.
  Unrated time has NULL monetary cost. Recognition needs configured M11 expense
  and accrual mappings. Linking an approved existing manual expense creates no
  second journal and excludes protected control and material/labor sources.
- ReceivablesPayables owns service invoices, credits, AR and settlements. Service
  pricing requires a persisted independent commercial approval and owner tax
  calculation. A verified work order produces a `maintenance_service` source;
  it never masquerades as a goods delivery. Live balances require M12 authority;
  the restricted handoff exposes only actual invoice identity/status.
- System owns official numbering, service-report PDFs and audit. Private uploads
  require M08 document authority and scoped record visibility; downloads verify
  their hash and use private/no-store responses.

The transaction lock order is financial owners, Projects, then Maintenance.
Forward migrations 182–187 add schema and immutable history guards. Applied
migrations must not be rewritten or rolled back to remove operational history.
When a disposable acceptance clone is migrated by a different PostgreSQL owner,
its normal runtime role must receive the matching table/sequence privileges for
new tables before browser execution. This database setup is separate from user
RBAC; immutable-history triggers and all owner Action permissions still apply.

## Local demonstration

Invoke `php artisan db:seed --class=FieldMaintenanceDemoSeeder` explicitly in local
or testing PostgreSQL. `EgyptMarketDemoSeeder` includes the same composition.
Stable identities are `DEMO-EG-MR-001`, `DEMO-EG-WO-001` and `DEMO-EG-CHK-001`.
The seeder preserves edits and creates one unstarted planned visit with an inactive
proposal assignment. It does not grant permissions, invent rates, record actual
labor or fabricate approvals, inventory postings, invoices or completion history.
Factories cover request/work-order drafts, checklist configuration and planned
visits. Acceptance fixture execution belongs exclusively in isolated PostgreSQL.
