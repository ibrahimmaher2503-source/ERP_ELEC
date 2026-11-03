# M09 workshop gap completion

Decision: **M09 COMPLETE** for the authorized standalone paid, company-internal and warranty/goodwill repair capability. Contract-covered repairs remain **BLOCKED BY DEPENDENCY** on the absent M10 producer. This is local capability acceptance, not company or production activation.

2026-10-31. Local implementation following the separately preserved Stage A audit in `TENDERS_WORKSHOPS_CURRENT_STATE.md`. No company workshop names, monetary activation inputs, rates, stock or privileges were seeded into development. The original dirty tree and applied migrations 188–191 were preserved.

## Implemented changes

- Existing M09 extended in place. Workshop configuration supports adding another record after saving; there is no four-record database limit. Four actual company identities remain operator configuration. No fourth software owner was created.
- Company repair identity now consumes real M06 serial or positive receipt references through `InventoryApi::repairEquipment` / `repairEquipmentFromReceipt`, with warehouse/branch permission checks. One existing unit is isolated and released by M06; a supplied actual equipment tag ties repeat custody history together. Customer equipment remains nonvalued custody. Serial financial-stock production remains a separate M06 capability and is not fabricated here.
- New `RepairOffers` separates independent internal price approval, System offer issuance and exact-revision customer acceptance. Selling charges/discount/validity/warranty are separate from estimated cost; M04 price lookup, M11 tax, M17 PDFs are reused. Old combined `decide` is an internal-approval adapter. Quote changes before execution create a new revision requiring fresh approvals; changes after actual work/issue are denied.
- Warranty, goodwill and company internal work require independent, evidence-backed authorization. Warranty checks the same equipment, an actual delivered earlier repair and its explicitly offered unexpired warranty. M10 absence keeps contract-covered claims disabled.
- `WorkshopCustody` records separate dispatch/receipt events and in-transit execution hold. Destination receipt clears old technician assignment. Direct cross-workshop reassignment is denied. Source-bound creation hashes and detailed replay payload checks prevent keys being reused for different intake/job/work/delivery requests.
- Independently approved same-unit part alternatives preserve the original unallocated demand. M06 still owns availability, reservation, partial issue, FIFO/GL and exact-source unused return. Customer components remain separately reconciled custody.
- Mandatory failed tests cannot be waived. Latest passing tests plus independent verification and parts reconciliation precede delivery. A private System delivery PDF supplements the repair report and actual handover acknowledgment. Internal unrepaired equipment stays unavailable; successful repair releases the original M06 isolation.
- M12 still owns invoice/AR/GL. Workshop invoices recalculate the actual invoice-date M11 tax, require the accepted tax amount to match and retain the original quoted snapshot. Invoice dates can follow the quote date without inventing a rate or silently changing accepted amounts. No collection-before-release policy was introduced.
- Existing tabs/shell retained; new offer and custody partials, private quote/delivery PDFs, repeat equipment history, workshop/status/billing/overdue filters and matching export filters added. Sensitive internal costs remain permission-controlled.

## Forward migrations

| File | Purpose |
|---|---|
| `database/migrations/2026_10_31_192000_complete_workshop_offers_and_custody.php` | Company serial identity/isolation; internal approvals, immutable offers, nonbillable authorization, transfers/receipts and delivery documents; M09 catalog/sync. |
| `database/migrations/2026_10_31_193000_add_workshop_part_alternatives.php` | Immutable original/replacement demand approval and permission registration. |
| `database/migrations/2026_10_31_194000_bind_workshop_command_payloads.php` | Creation-payload hashes and fault classification. |
| `database/migrations/2026_10_31_195000_link_company_repair_inventory_identity.php` | Genuine quantity-receipt equipment identity and immutable custody-source guard. |

These were verified incrementally on a newly cloned, guarded PostgreSQL `erp_elec_new_test` at loopback 55462. Existing isolated fixture sources 55447/55448 and development 5443 were not mutated by those acceptance campaigns. Development migration/activation status is reported separately at final delivery; a test-clone migration does not imply production migration.

## Files and contracts

New owner files: `app/Modules/WorkshopRepairs/Application/{RepairOffers,WorkshopCustody,WorkshopReports}.php`; `Presentation/RepairDocumentController.php`; `resources/views/workshops/{offers,custody-transfer}.blade.php`; `docs/m09-workshop-repairs.md`.

Changed owner source: `WorkshopActions`, `WorkshopEstimates`, `WorkshopExecution`, `WorkshopService`, `WorkshopParts`; `Presentation/{IntakePage,JobPage,WorkshopsPage,WorkshopExportController}`; existing intake/job/index views. Narrow integration changes: Inventory API/service identity lookups, System official document types, Organization permission catalog and M12 `WorkshopInvoices`. Existing M09 factories and explicit idempotent `WorkshopDemoSeeder` remain; tests verify persistence without fabricated execution.

Existing routes remain `/workshops/{dashboard|settings|intakes|jobs|reports}`, `/workshops/intakes/create`, `/workshops/intakes/{intake}`, `/workshops/jobs/{job}`, existing evidence/report/export routes. New official route: `/workshops/jobs/{job}/{quote|delivery}/{record}/pdf`, protected by current M09 document and branch authority. No unrelated module UI was rebuilt.

## Current evidence

The M09 and Tender acceptance campaigns are Luna-authored and reviewed by the lead. The owner also explicitly authorized the lead to author checks when needed; the broader financial negative audit used that exception. Initial failures are retained alongside reruns, not erased.

- `evidence/final-20261030/m09-workshop-financial-check.php`: rollback-only real-Action acceptance, exact loopback test-target guard.
- `evidence/final-20261030/prepare-and-run-m09-financial.ps1`: read-only dump from synthetic 55448 into fresh private 55462 cluster, isolated storage and forward migrations. This is not a production provisioning script; its trust-auth/superuser fixture setup is confined to the disposable loopback clone.
- `evidence/final-20261030/final-current-m09-financial.txt`: **74 assertions PASS on final scoped source**, including four workshops, receiving/custody, exact quote approval/issue/acceptance, M08 overlap, genuine FIFO issue/use/source return, approved alternatives, company equipment isolation/repair/release, mandatory failed-test refusal, approved labor GL, genuine M12 invoice/AR, M13 partial/final collections, expired and in-date warranty and factory persistence. Effects roll back on disposable port 55481.
- `m09-audit-20261031-034230-financial-check.txt`: **71 PASS**, including two independent workers for stock issue, invoice creation, QC and delivery. `m09-audit-20261031-034230-financial-rollback.txt`: **69 PASS**, including injected financial failure with no partial posting.
- `evidence/m09-browser-20261031/run-8A231255DA/result.json`: complete authenticated repair journey PASS at the three required viewports. Four workshop records are created through UI; job 45 is delivered, invoice 72 posted as `CI-2027-000016`; actual issue 1.5, usage 1.0 and return 0.5 leave on-hand 7→6, reserved 0. Failed mandatory QC blocks verification; later passing QC and a different supervisor succeed. Quote/report/invoice/attachment access denials return 403; unexpected browser errors are empty.
- `evidence/m09-browser-20261031/tender-run-233CC98869/result.json`: refreshed posted-invoice job card at 390px and real private XLSX/PDF exports; the workbook contains `WJ-2027-000001`. Unauthorized export requests return 403.
- Final cross-capability scope check `tender-actions-branch-scope-final-20261031-1720.txt`, 58 PASS, verifies that a direct M09 branch permission without actual user branch scope cannot expose lists or authorize Actions; genuine scope assignment restores access. Stock controls also require real M06 warehouse/M11 posting authority, while the assigned technician can record usage without receiving those permissions.
- `final-current-aggregate-rerun.txt`: **21/21, 875 explicit assertions PASS**; current Laravel, Vite, Blade and scoped Pint receipts are listed in `TENDERS_WORKSHOPS_FINAL_ACCEPTANCE.md`.
- `browser-clone-backup-restore-20261031-163438.json`: actual restore of the browser-derived final schema, 209 table counts/catalog inventory equal, 20 private referenced files and 223 official PDFs verified. Restore port 55487 stopped; this is not production RTO/RPO evidence.
- Earlier Laravel/session and browser input-value/selector failures are preserved. Corrected campaigns above resolve them; no source save was fabricated from a screenshot. Genuine source defects in catalog rendering and private handover evidence were fixed and rerun.

## Development delivery and boundaries

Development forward migrations 192–198 were applied after a private schema-191 backup. `development-preservation-final.json` confirms selected operational counts, draft project versions and inactive financial/Go-Live state unchanged; Administrator has all 288 registered permission rows. No demo seeder or operational posting ran on development.

The quote is an approved service offer with separate parts/labor/other selling charges, costs, discount, validity and warranty snapshots. Price revisions require renewed approval/acceptance before actual execution; pricing locks once work/parts execution begins. Actual FIFO, supported labor and expenses remain separate from quoted estimates. No full duplicate Sales engine, asset register, contract entitlement or payment ledger was created.

Actual workshop identities, production rates, tax validation and company activation remain operator/company inputs. M10 coverage is unavailable; serial financial-stock production remains an M06 dependency rather than an invented M09 posting. Production verification is **NOT TESTED**. Broader financial P0s and missing modules remain in `FINAL_REMAINING_WORK.md`; they prevent actual company activation despite local M09 acceptance.
