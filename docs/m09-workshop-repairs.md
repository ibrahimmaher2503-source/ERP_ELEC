# M09 WorkshopRepairs

WorkshopRepairs owns workshop configuration, customer custody, repair execution and source-specific offers. Four workshops are independently configurable through `/workshops/settings`; the database allows expansion. Company names, specialties, rates and warranty periods are entered by authorized operators. Demo workshop identities are explicitly synthetic and inactive, never company configuration.

## Boundaries

Customer equipment is custody, never company stock. Repeated intake links use the normalized customer/serial identity; unreadable serials remain visibly unidentified and are not assigned a fabricated serial. Company repairs reference either an existing Inventory serial or a genuine positive Inventory receipt plus the actual equipment tag supplied by the operator. M06 validates product/warehouse/branch, isolates one existing unit and releases it after successful verified repair. Custody does not create a purchase, FIFO layer or journal. Unrepairable company equipment remains unavailable. This is an Inventory equipment source reference, not a second asset register.

M06 owns reservations, issues, FIFO, GL-linked stock effects and exact-source unused returns. The valued producer now supports genuine serialized receipts/openings, issue-cost identity and transfers; full serialized service-parts/unused-return acceptance remains open. Serialized company repair references use actual Inventory identity and isolation; custody never creates stock or valuation.

## Offers and authorization

`WorkshopEstimates::propose` captures cost estimates separately from parts/labor/other selling charges, discount, net, validity, warranty days/conditions and the actual Sales `QuotationPricing` result. A missing/different approved M02 price requires the documented pricing exception reference and independent M09 pricing approval. No arbitrary rate becomes an M02 approved price. Tax comes from M11.

`RepairOffers::approve` independently approves the exact immutable estimate. `issue` creates one private System `workshop_quote` PDF per revision, exposing only customer selling figures. `accept` records the customer's decision against that issued revision. `WorkshopEstimates::decide` is retained as an internal-pricing-approval adapter only; it no longer supplies customer acceptance. Revisions before execution invalidate eligibility until newly approved, issued and accepted. Repricing after actual work or issue is denied.

`RepairOffers::nonbillable` requires independent authorization: warranty needs a prior delivered repair for the same equipment and an unexpired explicitly offered warranty; goodwill needs reasoned authorization; company internal work needs its real Inventory isolation. Contract work now requires the real M10 signed/active entitlement for the equipment/site/date and synchronizes actual usage through typed contracts. No entitlement is inferred from a text reference. M10 financial billing remains an M10/M12 source workflow, separate from repair custody.

## Execution and custody

`WorkshopActions` enforces branch access, active workshop technician membership, optimistic versions and source-bound creation keys. `WorkshopCustody::dispatch` records in-transit custody and holds execution; `receive` accepts the exact transfer, preserves history and clears the technician pending assignment at the destination. Direct cross-workshop reassignment is rejected.

`WorkshopParts::alternative` independently approves a same-base-unit replacement before any original reservation, retains the original demand and prevents its later allocation. Parts are not deducted at request. Actual issues are bounded by source reservations; used quantities cannot be returned. Customer-provided components have separate source-linked receipt/use/return history and no company valuation.

Actual work records carry technician, start/end, duration and description. M08/M09 intervals are checked synchronously under the service locks. Rate approval is independent; absence of a rate means cost unavailable. M11 recognizes an approved rate-derived labor source once, and genuine other expense sources are claimed exclusively through Accounting. No payroll expense or authentication event is synthesized.

Mandatory QC needs a real latest passing test. Failure/retest/rework remain append-only; mandatory tests cannot be waived. Independent supervisor verification issues the System repair report. Delivery reconciles company and customer parts, preserves actual recipient/accessories/condition/acknowledgment, and creates a private System handover PDF. Delivery retries validate the original payload; an unrelated second delivery is denied. Payment is not a release prerequisite.

## Financial handoff

`WorkshopApi::billingSource` and `validateBillingEvidence` expose only verified customer-accepted billable sources. M12 `WorkshopInvoices` creates/validates/posts one invoice/AR/GL source per repair. Invoice-date tax is recalculated by M11 and must match the accepted offer tax; quoted and fiscal snapshots remain separate. A changed tax amount blocks billing for a documented commercial/tax resolution. M12 owns invoice/collection progress; M13 owns actual collection. Project attribution uses existing M07/M11 owner contracts. M08 referral visits retain unique source links and shared repair history; labor cannot be counted in both owners.

## Access and UI

The existing Arabic RTL shell, Flux controls and compact tables remain. Intake, diagnosis/assignment, offers, actual work, parts, costs, QC, delivery and history use existing pages/tabs. Company equipment source fields and new transfer/offer actions stay inside those pages. Reports and XLSX/PDF use matching filters and sensitive cost checks. Private official document routes are `/workshops/jobs/{job}/{quote|delivery}/{record}/pdf`; private evidence and repair reports retain their existing authorized routes.

All M09 keys are registered through Organization; ordinary roles remain default-deny. Explicit Administrator membership receives the registered keys through `SyncAdministratorPermissions`, with an append-only audit of additions. It still needs branch/warehouse scope and cannot bypass independent approval, source eligibility or missing M10.

Acceptance receipts, failures, browser images and remaining limits are recorded in `release/M09_WORKSHOPS_IMPLEMENTATION_REPORT.md` and `release/TENDERS_WORKSHOPS_FINAL_ACCEPTANCE.md`. This contract is not evidence that an unrun check passed.
