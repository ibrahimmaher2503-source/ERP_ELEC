# Company Go-Live checklist

Audit date: 2026-11-01. **Do not activate posting or deploy from this checklist.** The audit authorizes evidence collection only. Findings and closure tests are in [the finding register](PRODUCTION_READINESS_BLOCKERS.md).

## Ordered release gates

| Order | Gate / accountable owner | Current status | Evidence required to close |
|---:|---|---|---|
| 1 | M11 financial integrity / Accounting | VERIFIED FAIL | Resolve full-company period close and consistent historical source cutoffs; accept manual control-account protection and close-vs-post races. Preserve failed-case evidence; use real source Actions. |
| 2 | Required scope / product owner | VERIFIED FAIL | Deliver/accept M10 ServiceContracts and M15 Attendance. Define M14/M16 scope explicitly; do not silently omit absent owners or relabel module reports. |
| 3 | End-to-end operations / owners and finance | BLOCKED | Fresh connected Sales, Purchasing, Inventory, Projects/Tender, Maintenance, Workshop and Finance traces on one reviewed candidate, including required returns, serial goods, approvals, concurrency and rollback. Resolve or explicitly exclude unsupported capabilities before choosing a pilot scope. |
| 4 | Security / Organization and security owner | BLOCKED | Close all selected role/object/action/download/export/scope tests; resolve dependency advisories; verify production cookies, HTTPS, headers, mail, session revocation and secret access. |
| 5 | Exact artifact / release manager | BLOCKED | Review all dirty/untracked business code, migrations, contracts, factories and tests; create a versioned artifact with lockfiles, source/assets fingerprints and reproducible build. Do not commit local data or secrets. |
| 6 | CI and installation / engineering | BLOCKED | Provision isolated PostgreSQL and the required extensions in CI; verify clean locked installs, migration, runtime boot and current regression. Distinguish harness refusals from app defects. |
| 7 | Hosting / infrastructure owner | BLOCKED | Bind a concrete target to this ERP; validate OS/runtime/DB, least privilege, document root, network, DNS/TLS, private storage and available resources. |
| 8 | Deployment / release manager | NOT TESTED | Rehearse atomic release, migration, config/route/view cache, workers, scheduler, health checks and failed-deployment recovery on disposable target-like staging. Restore backup for incompatible migrations; never assume `migrate:rollback` is safe. |
| 9 | DR / infrastructure and finance | BLOCKED for production | Define RPO/RTO, encryption/key custody, retention/offsite copy and restore owner. Restore database + private files + compatible app/config/key; compare hashes and reconcile real Actions within measured objectives. |
| 10 | Capacity / engineering and operations | NOT TESTED on target | Run defined 5/20/50/100-user mixes on target-like staging with realistic volumes, write contention, exports/reports/jobs, P95/error/SLO and CPU/memory/DB evidence. Local built-in-server observations do not qualify. |
| 11 | UI / business UAT | BLOCKED | Finish current page/state/role/viewport matrix, Arabic RTL, keyboard, validation/loading/empty/errors/modal/export acceptance. Verify executive analytics from authoritative projections. |
| 12 | Real company setup / company representatives | BLOCKED | Supply and reconcile the actual inputs below. Do not copy synthetic fixture values. |
| 13 | Operator readiness / business operations | NOT TESTED | Named operators, training, approval responsibilities, opening/cutover rehearsal, incident/escalation/restore procedure and owner sign-off. |
| 14 | Final GO / independent release authority | NO-GO | Re-audit the exact artifact and target after closure. A pilot requires explicit scope, restrictions, recovery and owner approval. |

## Company input register (G12)

The current development database is not a source of approved production openings. Fresh read-only evidence (`evidence/production-readiness-20261101/dev-readonly-snapshot.json`) shows Go-Live NULL, activation false and tax validation false, with zero operational movements/posted journals/invoices/Treasury movements. These are truthful inactive states, not missing values to fill automatically. They do not establish whether the company maintains approved inputs elsewhere.

| Input | Status | Required supplied/validated evidence |
|---|---|---|
| Legal company / real branches | BLOCKED | Approved names/codes, legal/tax identifiers, addresses, active locations, accountable operators. |
| Warehouses / locations / scopes | BLOCKED | Actual warehouses/bins/conditions, branch ownership, custodians, permitted scopes and movement cutoffs. |
| Four real workshops | BLOCKED | Names, locations, specialties, capacity, supervisors, technicians, hours and parts warehouse. Four configurable records in M09 are not four configured real workshops. |
| Employees / accounts / roles | BLOCKED | Real employment and explicit account/role/scope mapping; default-deny ordinary roles, independent approvers, MFA/recovery arrangements. M14/M15 availability must be resolved. |
| Customers / suppliers / sites | BLOCKED | Cleansed company-owned masters, contacts/sites, commercial terms and approved credit policy. |
| Product catalog / prices | BLOCKED | Actual units/conversions/barcodes/tracking rules, approved prices/override policies and serial requirements. |
| Stock / FIFO openings | BLOCKED | Physical counts, ownership/condition/location/serial identity, actual oldest-first cost layers, GL tie-out and independently approved cutover. Unsupported serial opening must remain blocked. |
| COA / mappings / periods | BLOCKED | Active postable accounts, distinct control mappings, valid open periods and finance sign-off. |
| Financial openings | BLOCKED | Signed trial balance, detailed AR/AP, customer advances/credits, cash/bank, unmatched receipts/GRNI and stock controls; no equity balancing plug for unexplained differences. Validate supported opening contracts for every real balance type. |
| Tax treatment | BLOCKED | Applicable dated codes/rates/exemptions/recoverability/precision and evidence. General engine/rounding policy is already approved; actual treatment is company input. |
| Statutory documents | NOT TESTED | Company-specific applicability, document fields/codes, signatures/certificates and submission obligations. Local PDF generation does not prove acceptance by an external authority. |
| Pricing / approval limits | BLOCKED | Approved effective prices, independent override authority and policy; no guessed thresholds. |
| Labor / service policies | BLOCKED | Actual rates/approval, warranty/goodwill evidence, contract coverage/usage/billing rules after M10 delivery. |
| Shift / attendance rules | BLOCKED | Actual shifts, overnight rules, devices/mappings, leave/overtime approval and locked timesheet process after M15 delivery. |
| Numbering / templates | BLOCKED | Approved document type/year/branch requirements, starting positions and real templates; verify immutable source and retry behavior. |
| Opening and cutover approval | BLOCKED | Actual date, reconciled source snapshots, independent approval and signed cutover/recovery result. |

## Blocker ownership

- **Software:** financial correctness, absent mandatory modules, unsupported returns/serial paths, incomplete Treasury lifecycle and current security/functional acceptance.
- **Configuration:** real masters, dates, tax validation, mappings, rates, scopes, periods and signed openings.
- **Infrastructure:** target, deployment, TLS/mail, least privilege, monitored processes, production backup/restore and capacity.
- **Training/operations:** named staff, responsibilities, standard procedures, recovery objectives, incident handling and owner release acceptance.

## Pilot boundary

No operational pilot is approved or presently recommended. A read-only synthetic demonstration remains a demonstration. A future limited pilot must define supported modules/products/branches, exclude missing M10/M15 and unsupported financial/stock paths only through an explicit scope decision, have reconciled test openings, proven recovery and rollback, controlled operator access and owner approval. It cannot waive a financial correctness or authorization defect affecting the allowed operations.
