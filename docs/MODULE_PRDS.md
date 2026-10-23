# Functional PRDs — 17 modules

> **UI stack decision — 2026-10-20:** [ADR-0015](../adr/0015-livewire-flux-ui.md) adopts **Livewire 4 + Flux UI 2 + Tailwind CSS 4**. Earlier Filament-first instructions are superseded; historical runtime/test receipts retain their original scope. Documentation is adopted; runtime migration is pending. Business contracts, authorization, SQLite isolation and local-first policy remain mandatory.

2026-10-12. Adopted replacement v2; source authority: [scope](../scope/MASTER_SCOPE.md). Business wording only; technical contracts live in architecture documents. No original functional requirement IDs were present. IDs M01-01 through M17-03 are newly assigned and must be preserved when original IDs are reconciled.

## How to use these specifications

Each module below covers: overview/objective (1–2), in/out scope (3–4), actors/permissions (5), master data (6), identified requirements (7), screens/fields (8), workflows (9), rules/states/calculations (10–12), integrations/financial-stock effects (13–14), reports (15), exceptions (16), acceptance criteria (17), decisions/dependencies (18–19). Common requirements below apply to every module, so they are not repeated 17 times.

A/B/C labels below preserve original traceability: A = supplied scope, B = clarified behavior, C = formerly unresolved policy. **All are adopted now** under delegated authority; C is not an open decision. [Egypt operating baseline](../scope/EGYPT_OPERATING_BASELINE.md) fixes all policy choices, [data dictionary](../architecture/04-DATABASE-ARCHITECTURE.md) fixes persisted fields/enums, and [acceptance cases](../quality/ACCEPTANCE_CASES.md) fix numerical tests. These screens are the V1 specification, not claims of rendered UI or real customer-data verification.

### Common functional requirements

| ID | Requirement / validation | Acceptance criterion |
|---|---|---|
| COM-01 (A/B) | Every view, search, action, print, attachment and export respects permitted branch/warehouse, role and sensitive-field scope. A denied record must not leak via totals, selectors or downloaded files | A user of branch A cannot retrieve branch B records by direct reference, search or export; stock scope and payroll/cost visibility tested independently |
| COM-02 (B) | Lists filter by document/reference, applicable party/location, status and date; master lists by code/name and active state. Stable pagination, explicit no-results, clear filters; default order newest documents first | Identical filters produce matching detail/export totals; empty results disclose no unpermitted record counts |
| COM-03 (A/B) | Trace who changed/approved/posted/reversed what and when. Preserve posted source snapshots and history. Deactivate referenced masters instead of deleting history | Changing a current address/price does not rewrite an issued document; posted edit rejected and correction links visible |
| COM-04 (B) | Quantities/money use exact decimal calculation, reject invalid signs and inconsistent units. Posted effects and required approvals are indivisible; repeated submission has one outcome | Forced failure leaves no partial stock/GL/subledger effect; retry after lost response returns same outcome; conflicting repeat is rejected |
| COM-05 (B/C) | Show numbering, business date, responsible branch, status, party/source links, totals, actor history and relevant attachments. Required fields marked. Numbering, rounding and dates follow adopted OD-05/12 | Missing inputs identified at their fields; stale document version cannot overwrite newer work; preview shows all intended effects before posting |
| COM-06 (B) | Drafts may be corrected/cancelled. Posted records require authorized linked reversals/adjustments and dependency checks; no hard delete of posted facts. External failures show pending/retry state | Reversal cannot leave delivery, allocation, custody or subsequent posting silently inconsistent; recovery keeps original evidence |

### Requirement coverage and readiness

Conversational addition2026-10-13: all COM-01–06 and M01–M17 requirements also apply to embedded Arabic/English chat. [AI-F01–07, AI-S01–04, AI-Q01](../architecture/AI_NATIVE.md) define alternate interaction, structured results, ambiguity handling and security; [all-module operation register](../architecture/AI_TOOL_CONTRACTS.md) binds each module's existing screens/actions to the same operations. No business requirement is replaced. Physical evidence/signatures/uploads use existing authorized controls and linked navigation, then resume chat. Each module card must expose/test its permitted operations, not implement separate chatbot business logic.

This is the maintained coverage index, not a separate audit report. Source S-* refers to the scope table; all rows inherit COM-01–06 and reference their own field/rule/acceptance sections below. All modules are specified; predecessor tasks and acceptance tests are defined in EXECUTION_PIPELINE. No application UAT has run.

| Module / requirements | Source | Related modules | Workflow | Data / screens / acceptance | Implementation readiness |
|---|---|---|---|---|---|
| M01-01–03 Organization | S-01/02; architecture registry | All | WF-00 | M01 section | Specified; execute pipeline predecessors and module acceptance |
| M02-01–03 Products | S-03/10 | M04/05/06 | WF-00/01/02 | M02 section | Specified; execute pipeline predecessors and module acceptance |
| M03-01–03 Parties | S-05/11 | M04/05/08/10/12 | WF-00/02 | M03 section | Specified; execute pipeline predecessors and module acceptance |
| M04-01–03 Sales | S-03/06 | M06/07/10/11/12 | WF-02/05 | M04 section | Specified; execute pipeline predecessors and module acceptance |
| M05-01–03 Purchasing | S-03 | M06/07/11/12 | WF-01/03 | M05 section | Specified; execute pipeline predecessors and module acceptance |
| M06-01–03 Inventory | S-02/04/10 | M02/04/05/07/08/09/11 | WF-01/02/03/04/08 | M06 section | Specified; execute pipeline predecessors and module acceptance |
| M07-01–03 DeliveryReturns | S-03 | M04/05/06/11/12 | WF-02/03 | M07 section | Specified; execute pipeline predecessors and module acceptance |
| M08-01–03 FieldMaintenance | S-05 | M03/06/10/11/14 | WF-04/05 | M08 section | Specified; execute pipeline predecessors and module acceptance |
| M09-01–03 WorkshopRepairs | S-02/04 | M03/06/10/11 | WF-04 | M09 section | Specified; execute pipeline predecessors and module acceptance |
| M10-01–03 ServiceContracts | S-06 | M03/04/08/09/11/12/13 | WF-05 | M10 section | Specified; execute pipeline predecessors and module acceptance |
| M11-01–03 Accounting | S-01/07 | M04/05/06/12/13/14/16 | WF-01–08 | M11 section | Specified; execute pipeline predecessors and module acceptance |
| M12-01–03 ReceivablesPayables | S-07 | M03/04/05/10/11/13 | WF-02/05/06 | M12 section | Specified; execute pipeline predecessors and module acceptance |
| M13-01–03 Treasury | S-07/08 | M11/12/14/15 | WF-06/07 | M13 section | Specified; execute pipeline predecessors and module acceptance |
| M14-01–03 Payroll | S-08 | M08/09/11/13/15 | WF-07 | M14 section | Specified; execute pipeline predecessors and module acceptance |
| M15-01–03 Attendance | S-08/09 | M11/13/14 | WF-07 | M15 section | Specified; execute pipeline predecessors and module acceptance |
| M16-01–03 Reporting | S-11 | All | WF-09 | M16 section | Specified; execute pipeline predecessors and module acceptance |
| M17-01–03 System | S-12 | All | WF-00/10 | M17 section | Specified; execute pipeline predecessors and module acceptance |

## M01 — Company, branches and users

**Overview/objective; scope:** Represent one company and scoped operators (A). Manage branch/user access without duplicating warehouses/workshops. Multi-company tenancy and additional legal entities are out of scope.

**Actors/master data:** Authorized administrator maintains company identity, branches, users, roles and access grants; managers see only delegated grants. Inventory and WorkshopRepairs own actual locations. Approval authority policy is OD-06.

| ID | Functional requirement |
|---|---|
| M01-01 (A) | Specified; execute pipeline predecessors and module acceptance |
| M01-02 (B) | Activate/deactivate users and scoped roles; revoke access without erasing their transaction history |
| M01-03 (B) | Show role/action and sensitive-field permission matrix; record grant changes and prevent unauthorized privilege escalation |

**Screens/fields:** Company identity (name, code, EGP currency); branch (unique code, name, status); user (name, login identifier, active state, role, permitted branches/warehouses); grant detail/history. Company/branch code and user identity required; location selections must exist. Real legal/tax identifiers come from client data, not examples.

**Workflow/rules/states:** WF-00. Administrator proposes valid grants → saves → user access reflects them. Active/inactive master state only; never impose financial approval states on a user. Deny access outside grants; location inactivation must identify dependent open work rather than silently relocate it. No monetary calculations.

**Integrations/effects/reports:** All modules consume access decisions. No stock/GL effect. User-access register and branch/location mapping export are restricted to authorized administrators.

**Exceptions/acceptance:** M01-01: branch A user fails branch B direct access. M01-02: disabled user cannot start a new protected action, historical authorship remains. M01-03: an operator cannot give themselves payroll or cross-warehouse access. Missing scope is denied, not interpreted as all branches.

**Adopted policies/dependencies:** OD-02/06. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M02 — Products and pricing

**Overview/objective; scope:** Accurate electrical product identification and sale/purchase units and prices (A). No product configurator, automated commission or speculative pricing engine.

**Actors/master data:** Catalog operator maintains products/brands/units/specifications; authorized pricing role maintains prices. Cost visibility is separate from selling-price visibility. Serial policy owned here; physical serial occupancy belongs to M06.

| ID | Functional requirement |
|---|---|
| M02-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M02-02 (A/B) | Effective selling prices and price history; preserve prices on confirmed/posted source documents |
| M02-03 (A/C) | Barcode/manual identification and product serial policy, with approved rules for consumable/legacy exceptions |

**Screens/fields:** Product list/detail; required SKU/name/unit, optional manufacturer specification and barcode until validated; price list with product, amount, EGP, effective date; serial requirement and exception reason. Reject duplicate canonical SKU and ambiguous barcode mappings; do not assume part number globally unique. The 2026-10-21 M02 implementation request additionally approves category hierarchy, brand/unit masters, explicit same-dimension product-to-base conversion definitions, specifications and private media. No hidden conversion: contract conversion is explicit and exact; transaction owners retain quantity boundaries.

**Owner tax decision 2026-10-21:** Product registration/activation/edit/import/export never requires Accounting or a tax profile, and has no tax-based draft/readiness state. Tax validation belongs to Sales/Purchasing transaction boundaries. M02 stores no tax rates or accounting tables.

**Workflow/rules/states:** WF-00; create active catalog entry → publish authorized price → use effective price → deactivate prospectively. Price amount cannot be negative; zero-price/discount overrides require independent FinanceManager approval with recorded reason under OD-06. Serial exception is not silent free text. No automatic historic repricing.

**Integrations/effects/reports:** M04/M05 resolve product and price; M06 resolves tracking policy. No stock movement or GL from editing catalog. Product/price list export respects cost restrictions.

**Exceptions/acceptance:** M02-01 duplicate SKU rejected; deactivation preserves old invoice descriptions. M02-02 changing price leaves posted totals unchanged. M02-03 ambiguous scan prompts resolution before quantity is added; nonserial products cannot silently inherit a serial waiver.

**Adopted policies/dependencies:** OD-03/05/06. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M03 — Customers and suppliers

**Overview/objective; scope:** Lightweight customer CRM and supplier master, including factories/sites (A). No full marketing automation or separate CRM platform.

**Actors/master data:** Authorized sales/purchasing staff maintain their permitted parties; finance controls commercial credit policy. Customer and supplier roles may refer to the same real party without automatic balance netting.

| ID | Functional requirement |
|---|---|
| M03-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M03-02 (A) | Customer factories/sites, contacts and follow-up history for field work and contracts |
| M03-03 (B/C) | Maintain authorized credit terms/profile; show authoritative balances from M12, never editable party totals |

**Screens/fields:** Party code/name/type/status required; tax identity when applicable, address, contact methods, payment terms, credit policy; site must identify parent customer, name/address and responsible contact; dated follow-up with author/result/next action. Warn on matching tax ID/name/contact rather than imposing unsupported global tax-ID uniqueness.

**Workflow/rules/states:** WF-00/02. Create/check duplicates → activate → add sites/interactions → deactivate future use where permitted. Site cannot move to another customer once used without explicit controlled correction. No financial calculation beyond displaying sourced balances.

**Integrations/effects/reports:** M04/05 party reference, M08 site, M10 contract, M12 credit/open items. No journal on CRM edits. Customer/supplier register and follow-up list; statements belong to M12/M16.

**Exceptions/acceptance:** M03-01 duplicate candidate is visible and merge does not silently rewrite financial history. M03-02 customer A job cannot select customer B site. M03-03 balance is read-only and obeys finance permission; unauthorized users cannot change credit terms.

**Adopted policies/dependencies:** OD-06. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M04 — Sales and quotations

**Overview/objective; scope:** Direct and quotation-based selling with independent order, invoicing and fulfillment (A). No POS or commission engine.

**Actors/master data:** Sales operator drafts; authorized commercial approver accepts overrides; finance poster posts invoices. Customer, product, terms, branch, applicable tax/account policy are prerequisites.

| ID | Functional requirement |
|---|---|
| M04-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M04-02 (A/B) | Post invoices and linked credits once; show billed quantities independently of delivered quantities |
| M04-03 (B/C) | Validate prices, discounts, credit terms, eligible source quantities and approvals before confirmation/posting |

**Screens/fields:** Quote/order editor: customer, branch, date, validity, product/service description, unit, positive quantity, unit price, discount, tax category, terms, destination/source warehouse as applicable. Invoice: source order/claim/installment, eligible quantity, net/tax/gross, financial date and posting status. Detail shows quotation revisions, fulfillment, credits and payments separately. Tax/rounding fields are policy-controlled (OD-05), not arbitrary rates.

**Workflow/rules/states:** WF-02/05. Quote draft → sent → accepted/rejected/expired; edits after sending create a revision. Order draft → confirmed → partially fulfilled → fulfilled; cancel only unfulfilled remainder after dependent effects are resolved. Invoice draft → posted; credited/adjusted is a derived correction view, original remains posted. Line base = quantity × unit price; net = base − approved discount; tax/gross depend on OD-05; negative quantity is not a return shortcut.

**Integrations/effects/reports:** M06 reservation/issue; M07 delivery; M12 receivable; M11 required GL. M10 supplies billable contract allocation. Posting stock only at approved physical milestone; invoice never silently repeats delivery issue. Quote/order/invoice printing, sales register and source-to-credit history.

**Exceptions/acceptance:** M04-01 duplicate order confirmation creates one order. M04-02 deliver 4 of 10 and invoice those4 in the same transaction without another stock issue; the adopted invoice-first path defers revenue until delivery. M04-03 unauthorized discount/credit override fails with no posting; revision after approval requires re-evaluation. Concurrent billing cannot consume the same eligibility twice.

**Adopted policies/dependencies:** OD-05/06/08. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M05 — Purchasing

**Overview/objective; scope:** Purchase orders, partial receipts and supplier invoices (A). No tendering/procurement platform beyond approved order processing.

**Actors/master data:** Buyer drafts; permitted approver approves PO; receiver records goods into permitted warehouse; finance posts supplier invoice. Supplier/product/warehouse and posting policy required.

| ID | Functional requirement |
|---|---|
| M05-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M05-02 (A/B) | Receive partial goods with serial/quantity/condition validation and source-line linkage |
| M05-03 (A/B/C) | Match supplier invoice to eligible order/receipt; surface quantity/price/tax mismatch before payable/GL posting |

**Screens/fields:** PO supplier, branch, target warehouse, date, product/unit/quantity, expected cost, discount/tax/terms; receipt PO lines, received quantity, serials, condition and date; supplier invoice number/date, supplier, receipt allocation, net/tax/gross and mismatch reason. Supplier invoice duplicate detection must be supplier-scoped and retain legitimate correction references.

**Workflow/rules/states:** WF-01. PO draft → approved → partly received → received/closed; close/cancel residual quantities with reason, never erase receipts. Receipt draft → confirmed; correction via linked reversal/return. Invoice draft → review → posted or returned for correction. Remaining to receive = approved order quantity − confirmed receipts − cancelled remainder; returns never reopen the PO, replacements use new linked order lines. OD-06 controls tolerances; block unresolved excesses.

**Integrations/effects/reports:** M06 stock receipt, M11 GRNI/clearing as approved, M12 AP; M07 supplier return. Invoice does not re-receive stock. Print PO/receipt/matching result; reports for outstanding orders, unmatched receipts and supplier invoice discrepancies.

**Exceptions/acceptance:** M05-01 PO 10 receipt 6 leaves 4. M05-02 repeat receipt has one movement; wrong warehouse/serial rejected. M05-03 invoice mismatch remains unposted until authorized resolution, receipt balance and GRNI reconcile without another receipt.

**Adopted policies/dependencies:** OD-03/04/05/06. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M06 — Inventory and stock counting

**Overview/objective; scope:** Exclusive company-stock ledger/valuation, reservations, transfers and counting across five warehouses (A). Workshops are custody/service locations, not sixth/seventh stock warehouses.

**Actors/master data:** Stock operator restricted by warehouse; counter records counts; authorized inventory approver approves adjustments. Own warehouse master, stock location, unit occupancy, reservation, movement and count records.

| ID | Functional requirement |
|---|---|
| M06-01 (A) | Specified; execute pipeline predecessors and module acceptance |
| M06-02 (A/B) | Reserve/release/issue/receive once and transfer through explicit in-transit quantities |
| M06-03 (A/B/C) | Barcode and manual counts with cutoff, difference review and authorized one-time stock/valuation adjustment |

**Screens/fields:** Stock list warehouse/location/product/serial/condition, on-hand/reserved/available and restricted cost; transfer source/destination, lines, dispatched/received/damaged quantities and evidence; count warehouse/location/cutoff, expected quantity, observed quantity, input method, counter, reason and approval history. Require different source/destination, positive moved quantities, nonnegative observed count; a repeated serial scan warns rather than adding another unit.

**Workflow/rules/states:** WF-01/02/03/04/08. Reservation active → consumed/released; transfer draft → approved → dispatched → partially received → received, discrepancy tracked separately until resolved. Count draft → counting → review → posted; authorized approval and required stock/GL posting succeed together, not an exposed half-posted final state. Available = eligible on-hand − active reservations; in-transit/unavailable condition cannot be sold. Count variance = observed − expected at reconciled cutoff. Cost method OD-04, count movement handling OD-07.

**Integrations/effects/reports:** M04/05/07/08/09 request owner effects; M11 required valuation journal; M16 reads scoped balances. No sale/revenue on internal transfer. Movement card, stock/valuation, low availability, serial trace, in-transit and count variance reports.

**Exceptions/acceptance:** M06-01 two concurrent requests for last unit yield one success; other fails without partial journal. M06-02 dispatch 5 then receive 3 leaves 2 in transit; replay receipt adds nothing. M06-03 approved count 8 versus reconciled expected 10 posts −2 once with approved cost and GL; restricted counter cannot see cost/export it.

**Adopted policies/dependencies:** OD-02/03/04/07. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M07 — Delivery and returns

**Overview/objective; scope:** Fulfillment evidence and customer/supplier returns (A), separate from invoices and custody handover. No fleet optimization.

**Actors/master data:** Dispatch/receiving operator, authorized return approver; links to source order, delivery/receipt and party. Customer-owned repair returns belong to M09, not trade inventory.

| ID | Functional requirement |
|---|---|
| M07-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M07-02 (A/B) | Customer return links original delivered units, condition and accepted quantity; credit eligibility separate from physical receipt |
| M07-03 (A/B) | Supplier return links source receipt and stock issue; supplier credit/payable correction remains a financial operation |

**Screens/fields:** Delivery source order, warehouse, positive lines/serials, destination/recipient/date/proof; return original source line, party, quantity/serial, reason, inspection condition, destination and credit/settlement links. Return quantities cannot exceed eligible unreturned quantities; returned goods are not automatically saleable.

**Workflow/rules/states:** WF-02/03. Sales/Purchasing owns commercial entry orchestration; DeliveryReturns owns physical documents only. Delivery draft → confirmed; delivery acknowledgement/evidence retained. Return requested → inspected → accepted/rejected → physically processed; financial credit status separate. Cancellation before physical effect releases eligibility; after effect use linked correction. Source/serial mismatch blocks acceptance.

**Integrations/effects/reports:** M06 owns physical movement; M04/M05 own credit source documents, M12 open-item effects, M11 journals. Each movement and financial effect has one source identity. Delivery note, return receipt, unfulfilled orders and uncredited accepted returns reports.

**Exceptions/acceptance:** M07-01 4 of 10 updates delivered 4 and outstanding 6 once. M07-02 concurrent returns of the same serialized unit cannot both succeed; accepted return does not create a duplicate credit. M07-03 paid supplier invoice correction links to refund/credit settlement instead of editing cash history.

**Adopted policies/dependencies:** OD-04/05/06/07. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M08 — Field maintenance

**Overview/objective; scope:** Customer-factory maintenance and service costing (A), online technician web. No offline synchronization or dispatch optimization engine.

**Actors/master data:** Service dispatcher, assigned technician, service supervisor; customer/site/equipment reference and work order. Salary data is not exposed to technicians via job cost.

| ID | Functional requirement |
|---|---|
| M08-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M08-02 (A/B) | Record work performed, time and company parts used/returned against one job |
| M08-03 (A/B/C) | Complete work with required service evidence; evaluate contract coverage and billable eligibility separately |

**Screens/fields:** Job customer/site/equipment, problem, branch, priority, planned date, assigned technician, contract/estimate when applicable; work visit actual time, result, parts quantity/serial, completion checklist/evidence and customer acknowledgement where required. Required customer/site/problem/assignee before active work; completion requires actual time, parts reconciliation and customer acknowledgement, approved by ServiceManager under OD-08.

**Workflow/rules/states:** WF-04/05. Open → assigned → in progress → completed; waiting parts/customer and cancelled are explicit alternatives. Completion cannot silently discard outstanding issued parts; reconcile used/returned quantities. Job cost = approved consumed-part snapshots + approved labor allocation + attributable expense; this is management costing, no second payroll expense.

**Integrations/effects/reports:** M03 site, M06 parts, M10 coverage/estimate, M14 authorized costing source, M04 invoice. Parts may have required M11 financial effect per OD-05; job completion alone is not automatic revenue. Work order/service report printing; open jobs, time/parts and profitability with cost permission.

**Exceptions/acceptance:** M08-01 technician sees only assigned/permitted jobs. M08-02 repeated part use has one issue; unused part return reconciles original usage. M08-03 lost connection after completion can retry safely; no claim/invoice until billable eligibility established.

**Adopted policies/dependencies:** OD-05/06/08/09. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M09 — Workshop repairs

**Overview/objective; scope:** Customer-owned breaker intake, repair, testing, transfer and handover across two workshops (A). No commercial ownership transfer, auto scrap/disposal or customer stock valuation.

**Actors/master data:** Intake operator, workshop technician, supervisor and authorized handover operator; workshop master, intake, custody item, repair job/test and custody history. Customer ownership is distinct from physical holder.

| ID | Functional requirement |
|---|---|
| M09-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M09-02 (A/B/C) | Track one current custody holder, workshop transfer dispatch/acceptance and authorized customer handover |
| M09-03 (A/B/C) | Diagnose, obtain applicable repair approval, consume parts, record test and release/return-unrepaired result |

**Screens/fields:** Intake required customer, workshop, breaker model/serial or visibly temporary identifier, received date/operator, condition/accessories and receipt evidence; repair diagnosis, estimate/contract link where applicable, approval evidence, parts/labor, test result; handover recipient identity/authority, date and signed proof. No invented serial for damaged labels.

**Workflow/rules/states:** WF-04. Received → diagnosis → awaiting approval/parts → repairing → tested → ready → handed over. Failed test returns to repairing/awaiting decision, not ready. Unrepaired return requires reason/evidence; charges require an accepted quote under OD-11. Transfer dispatch records an explicit in-transit holder; receiving workshop accepts before becoming current holder. Release and repair status remain distinct.

**Integrations/effects/reports:** M03 owner, M06 company parts, M10 estimate/coverage, M04 service invoice, M11 parts posting where due. Intake/transfer/handover: zero company-stock receipt and zero inventory value for customer breaker. Custody receipt/handover print, held-by-workshop/ageing/test and unreturned-items registers.

**Exceptions/acceptance:** M09-01 intake changes custody count but not company stock or valuation. M09-02 concurrent handover/transfer cannot create two holders; no release to unverified recipient. M09-03 failed mandatory test blocks ready state, and returned-unrepaired item remains traceable with original accessories/evidence.

**Adopted policies/dependencies:** OD-03/11, OD-05/08. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M10 — Maintenance contracts and estimates

**Overview/objective; scope:** Coverage, contract-dependent estimates/BOQs, scheduled installments and measured progress claims (A). No retention workflow or standalone generic billing engine.

**Actors/master data:** Contracts officer, authorized estimator/claim approver; customer/sites, signed contract/version, coverage, BOQ, due schedule and work measurements.

| ID | Functional requirement |
|---|---|
| M10-01 (A) | Specified; execute pipeline predecessors and module acceptance |
| M10-02 (A/B/C) | Approve measured work against remaining eligible quantities; track revisions and prevent repeated claiming/billing |
| M10-03 (A/B) | Distinguish signed value, earned work, approved claim, issued invoice, receipts and outstanding amounts |

**Screens/fields:** Contract customer, sites, start/end, signed value, coverage, billing mode, document evidence; estimate/BOQ version, work item/unit/quantity/rate; installment due date/amount; claim measured period, work references, prior/current/cumulative quantity, approval and invoice allocation. Required contract link for dependent estimate; positive quantities/amounts, valid dates within authorized coverage or explicit variation.

**Workflow/rules/states:** WF-05. Contract draft → approved → active → completed/terminated; estimate draft → submitted → approved/rejected, revisions explicit. Claim draft → submitted → approved/rejected → partly billed → billed. Contract completion does not erase unpaid invoices. Proposed claim value = eligible measured quantity × approved rate, adjusted only under signed variation; exact tax/revenue follows the adopted OD-05 matrix. Scheduled and claimed lines must not bill the same entitlement.

**Integrations/effects/reports:** M08/09 performed work, M04 invoice, M12 balance, M13 receipts, M11 recognition. Claim approval is not cash/revenue. Print estimate/BOQ/claim, due installments, unbilled approved work and financial summary with separate measures.

**Exceptions/acceptance:** M10-01 contract-linked estimate cannot reference another customer's work. M10-02 approved work 10 with prior claimed 6 permits 4, not 5; duplicate invoice allocation rejected. M10-03 receipt changes collected total, not signed contract value or measured work; terminated contract keeps settlement history.

**Adopted policies/dependencies:** OD-05/06/08. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M11 — General accounting

**Overview/objective; scope:** One entity EGP ledger, chart, periods, journals and financial statements (A). No multi-entity consolidation or foreign-currency engine.

**Actors/master data:** Accountant, authorized poster and period closer; chart of accounts, posting mapping, financial periods, branch/cost dimensions where approved. Control-account posting policy OD-05.

| ID | Functional requirement |
|---|---|
| M11-01 (A) | Specified; execute pipeline predecessors and module acceptance |
| M11-02 (A/B) | Reverse/adjust with original link, reason and open-period validation; preserve financial history |
| M11-03 (A/B/C) | Period controls and reconciliations of trial balance, stock, GRNI, AR/AP, treasury and payroll |

**Screens/fields:** Chart account code/name/type/postable state; period start/end/status; journal date, source, purpose, branch, account, debit/credit, memo, approval and reversal link. Require positive amount on exactly one side of each nonzero line; valid postable accounts and open date. No floats or balancing plug invented by system.

**Workflow/rules/states:** WF-01–08. Journal draft → posted; reverse via linked journal, original retained. Period open → closed; reopening only by FinanceManager with reason when external filing is not final; otherwise current-open-period correction. Debit total = credit total exactly under approved precision; no posted edit or silent date move to bypass closed period. Reversal treatment/date/account rules OD-05.

**Integrations/effects/reports:** All financial owners request GL; Accounting alone writes it. Trial balance, general ledger, income statement, balance sheet and reconciliation schedules; OD-13 defines report grain/date/fields and printable layouts. No direct GL correction that leaves subledger inconsistent.

**Exceptions/acceptance:** M11-01 unbalanced journal rejected, forced failure rolls back source and mandatory entries. M11-02 repeated reversal cannot double reverse; closed-period request reports actionable failure. M11-03 posting source/control totals reconcile and unexplained difference blocks close under approved policy.

**Adopted policies/dependencies:** OD-04/05/12/13. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M12 — Accounts receivable and payable

**Overview/objective; scope:** Trade open items, terms, allocations and reconciled balances (A). No automatic customer/supplier balance netting or invented employee trade accounts.

**Actors/master data:** Authorized receivables/payables accountant; immutable source invoice/credit, due date, open item, payment allocation. Party profile belongs to M03, cash movement to M13.

| ID | Functional requirement |
|---|---|
| M12-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M12-02 (A/B) | Reverse allocation/credit with traceability and expose unapplied payment separately |
| M12-03 (A/B/C) | Reconcile statements, ageing and credit exposure with GL and source documents |

**Screens/fields:** Customer/supplier statement date range, source/date/due/gross/credit/applied/outstanding; allocation payer/payee, currency, payment reference, open items, selected amount, remaining payment and effective date. Restrict to matching counterparty and authorized branch policy; cross-branch settlement policy belongs to OD-06.

**Workflow/rules/states:** WF-06. Open → partly settled → settled, derived from valid allocations/credits; reopened when linked allocation reversed. Outstanding = original debit obligation − eligible credits − net valid allocations, never overwrite it manually. Allocated sum ≤ applicable payment and ≤ eligible open amount under lock. Overpayment remains visible unapplied subject to OD-05, not negative invoice balance.

**Integrations/effects/reports:** M04/05 source, M13 cash, M11 control reconciliation, M10 contract reference. Allocation financial consequences follow approved mapping; allocation must not create a second cash journal. Statements and ageing filters/base date from OD-13.

**Exceptions/acceptance:** M12-01 two concurrent payments cannot over-settle 100; selected amount > remaining fails. M12-02 reversing allocation restores original open amount and retains payment trail. M12-03 total open items reconciles to approved control balance, unauthorized party/scoped export denied.

**Adopted policies/dependencies:** OD-05/06/13. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M13 — Treasury and expenses

**Overview/objective; scope:** Cashboxes/banks, receipts, payments, transfers, expenses and bank reconciliation (A). No payment gateway infrastructure unless later approved.

**Actors/master data:** Cashier within cashbox/branch scope; authorized payment approver; finance reconciler. Cashbox/bank account, movement, transfer, expense evidence and reconciliation item.

| ID | Functional requirement |
|---|---|
| M13-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M13-02 (A/B) | Transfer own funds and record supported expenses without treating transfers as revenue/expense |
| M13-03 (A/B/C) | Reconcile statement lines to existing bank movements and track unmatched/difference resolution |

**Screens/fields:** Receipt/payment date, branch, cashbox/bank, counterparty/payee, amount EGP, method/reference, source obligation and allocation; expense category/evidence and approval; transfer source/destination and amount; bank line date/reference/amount/match state. Positive amount, different transfer accounts; duplicate reference warning scoped to approved policy, not universally unique arbitrary text.

**Workflow/rules/states:** WF-06/07. Draft → approved where required → posted; clearing and reconciliation separate if applicable. Reversal through linked financial correction. Internal transfer preserves entity cash total; expenses do not generate duplicate AP payment. Cash cannot go negative; bank limit defaults0; uncleared cheques cannot settle, under adopted OD-05/06.

**Integrations/effects/reports:** M12 trade settlement, M14 payroll payment, M15 employee advance disbursement, M11 journal. M15 owns advance entitlement/recovery balance; Treasury owns disbursed cash. Cash/bank ledger, expense register, cash position, unreconciled lines; access protects account information.

**Exceptions/acceptance:** M13-01 retry produces one movement and journal; payment allocation failure rolls back mandatory originating effects. M13-02 own transfer amount 100 decreases source/increases destination 100 with no revenue. M13-03 repeated statement matching does not create another payment; unmatched item stays visible.

**Adopted policies/dependencies:** OD-05/06/09. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M14 — Employees and payroll

**Overview/objective; scope:** Employee/pay master, monthly fixed payroll, approved overtime/deductions and manual bonuses (A). No sales commissions or automatic incentive engine.

**Actors/master data:** HR maintains employees; restricted payroll officer calculates; approver/posts per policy; employee payslip visibility only when explicitly permitted. Own employee master, effective pay terms, payroll run/lines and payslip.

| ID | Functional requirement |
|---|---|
| M14-01 (A/B/C) | Specified; execute pipeline predecessors and module acceptance |
| M14-02 (A/B) | Approve/post payroll once per employee/period obligation; separate payment and job labor costing |
| M14-03 (A/B/C) | Correct unposted calculations and posted payroll through traced correction with advance/payment reconciliation |

**Screens/fields:** Employee code/name/branch/employment dates/status, effective fixed salary and restricted payment details; run month/population, base pay, overtime, manual bonus, deductions, advance recovery, net, approval/post/payment state and source snapshots. Salary values not exposed via general employee lookup.

**Workflow/rules/states:** WF-07. Draft → calculated → reviewed → approved → posted; partial/full payment derived from M13 confirmations. Changes after approval invalidate that approval; posted corrections use linked adjustment. Net = policy-calculated base + overtime + manual bonuses − approved deductions − advance recovery; rates, proration and caps OD-09. Statutory amounts come from verified per-employee/month inputs; missing is incomplete, not zero. Use the fixed ordinary OT/proration and recovery rules in the baseline.

**Integrations/effects/reports:** M15 approved time/advances, M11 payroll liability/expense, M13 settlement, M08/09 management labor allocation. Job allocations never post a second salary expense. Payslip, payroll register, deduction/advance reconciliation, unpaid payroll with strict field scopes.

**Exceptions/acceptance:** M14-01 changed attendance after frozen payroll does not silently recompute posted pay. M14-02 overlapping runs cannot pay/post same employee obligation twice; partial payment 40 of 100 leaves 60. M14-03 reversal restores appropriate advance recovery and liability once; unauthorized export never includes salary.

**Adopted policies/dependencies:** OD-09, OD-05/06. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M15 — Attendance, leave and advances

**Overview/objective; scope:** Biometric/manual/system attendance, leave and employee advances (A). No new HR platform; Payroll remains employee source of truth.

**Actors/master data:** HR imports/corrects time; supervisor approves where permitted; authorized advance approver. Own punch/import source, correction, leave request and advance entitlement/recovery records.

| ID | Functional requirement |
|---|---|
| M15-01 (A/B/C) | Specified; execute pipeline predecessors and module acceptance |
| M15-02 (A/B/C) | Approve leave/time summary and supply stable period inputs to payroll |
| M15-03 (A/B/C) | Request/approve advance; track confirmed disbursement and recoveries without owning cash movements |

**Screens/fields:** Import device/source/file fingerprint/period, original event identity/time/employee mapping, accepted/rejected/ambiguous rows; manual entry employee/date/time/reason/author; leave employee/type/from/to/approval; advance employee/amount/date/recovery proposal, approval, disbursement link and outstanding balance.

**Workflow/rules/states:** WF-07. Import staged → validated → applied with row exceptions; exact replay adds nothing. Leave draft → submitted → approved/rejected/cancelled per policy; time corrections retain originals. Advance requested → approved/rejected → partly/fully disbursed → partly/fully recovered, with linked reversals. Advance balance = confirmed disbursements − valid recoveries/refunds, not approved request amount. Hours/leave/shift calculations OD-09/10.

**Integrations/effects/reports:** M14 employee/time consumer and recovery source; M13 disbursement; M11 approved advance financial effects. Attendance/leave approval alone creates no cash. Daily attendance, missing punches, leave register, employee advance balances and import exceptions.

**Exceptions/acceptance:** M15-01 importing file twice does not duplicate time; unknown employee rejected for mapping. M15-02 ambiguous overnight punch is flagged, not invented hours. M15-03 approved but undisbursed 100 creates no recoverable 100; recovery cannot exceed confirmed remaining amount, retry is safe.

**Adopted policies/dependencies:** OD-09/10/06. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M16 — Reports and dashboards

**Overview/objective; scope:** Strong predefined reports and operational/financial dashboards (A). No custom report builder or speculative BI warehouse.

**Actors/master data:** Managers/finance/operations consume authorized definitions and exports; definition ownership is the relevant business department. Reporting owns read models/export jobs, never source business records.

| ID | Functional requirement |
|---|---|
| M16-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M16-02 (A/B) | Dashboard values show authorized scope, period, definition and freshness; no global totals leak |
| M16-03 (A/B) | Print/export consistent results with sensitive-field restrictions checked at request and delivery |

**Screens/fields:** Report picker, date/as-of, branch/warehouse/party/status filters, authorized cost/payroll toggle only where granted; displayed generated-at/refresh cutoff, units/EGP, included statuses, total and source drilldown. Zero, unavailable and stale data are visibly distinct.

**Catalog/rules:** Trial balance/GL/balance sheet/income; AR/AP statements and ageing; GRNI and treasury reconciliation; stock quantity/valuation/serial/in-transit/count; sales/purchase outstanding and returns; job profitability/parts; custody ageing/holder; contract signed/earned/claimed/invoiced/collected/outstanding; attendance/leave/advance/payroll. Each definition must specify grain, source owner, date basis, status inclusion, calculation, filters, field access and acceptable refresh before acceptance (OD-13). No summing invoice totals across line joins.

**Workflow/states:** WF-09. Select definition/scope → run → display or export queued → ready/failed. Export cannot bypass current permission after a role change. No stock/GL changes. Financial reconciliation should use authoritative cutoff rather than an unexplained stale projection.

**Exceptions/acceptance:** M16-01 known small fixture reconciles source totals without duplicate join multiplication. M16-02 refreshing an empty application is not “zero errors”; missing feed shows unavailable. M16-03 restricted user cannot export cost/payroll or download another user's report by guessed reference.

**Adopted policies/dependencies:** OD-13/15. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## M17 — Settings, documents and migration

**Overview/objective; scope:** Shared configuration, supporting documents, traceability and controlled legacy migration (A). No shared business engine or parallel financial source of truth.

**Actors/master data:** System administrator, data migration operator, authorized auditor and document owners. Own settings, file metadata/access, audit trail, migration staging/mapping and deferred-operation tracking.

| ID | Functional requirement |
|---|---|
| M17-01 (A/B) | Specified; execute pipeline predecessors and module acceptance |
| M17-02 (A/B) | Trace security/business actions and deferred document/report work with safe retry and observable failures |
| M17-03 (A/B/C) | Stage/map/validate/import stable legacy identities; reconcile openings/history before controlled cutover |

**Screens/fields:** Settings key/value/effective scope/author; document source/type/name/size/access and safe upload validation; audit actor/action/source/time/result; import batch/source type/stable legacy ID/mapping/status/error/reconciliation result. Secrets are never ordinary visible settings or audit payloads. Allowed file types/sizes and retention follow fixed OD-12/15 and IMPLEMENTATION_CONTRACT.

**Workflow/rules/states:** WF-00/10. Import staged → validated → approved for import → applied → reconciled; exceptions remain visible, restart uses same source identities. Posted imported facts corrected through owners, not overwritten. Opening balances and included historical postings cannot count the same value twice. Deferred output failed → retry does not repost source business effects.

**Integrations/effects/reports:** All modules request files/audit/settings. Import delegates each business write to the owning module; any stock/GL effects follow its rules and cutover plan. Import reconciliation and audit export are restricted. Backup/restoration is operational responsibility under OD-15.

**Exceptions/acceptance:** M17-01 attachment URL cannot leak another scope; replacing file preserves approved evidence history. M17-02 failed PDF generation leaves posted invoice intact and retry creates no journal. M17-03 second import has no duplicate balances; conflict quarantined, trial reconciliation signed before production cutover.

**Adopted policies/dependencies:** OD-12/14/15. Policy is resolved in the operating baseline; implement only after the module’s predecessors in [EXECUTION_PIPELINE](../development/EXECUTION_PIPELINE.md). Actual company data is collected during setup.

## Binding v2 details

Goods delivery-first creates/posts the delivered-quantity invoice atomically; invoice-first posts deferred revenue then releases it on actual delivery. Physical and fiscal records remain distinct. Supplier return uses current moving average and an explicit clearing/variance entry; customer return uses original issue cost. Paid-invoice credits become separately refundable balances without deleting original payments. Contracts select installments OR progress once, never mixed V1. Payroll verified statutory inputs are a mandatory screen/import, with evidence and separate approval. Attendance employee lookup/entry is coordinated by Payroll, not a reverse module import. Detailed schemas, contracts and tests govern these points; no inferred alternate implementation is permitted.
