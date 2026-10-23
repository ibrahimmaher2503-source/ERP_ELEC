# Cross-module business workflows

> **UI stack decision — 2026-10-20:** [ADR-0015](../adr/0015-livewire-flux-ui.md) adopts **Livewire 4 + Flux UI 2 + Tailwind CSS 4**. Earlier Filament-first instructions are superseded; historical runtime/test receipts retain their original scope. Documentation is adopted; runtime migration is pending. Business contracts, authorization, SQLite isolation and local-first policy remain mandatory.

2026-10-12, adopted v2. These workflows implement the [PRDs](MODULE_PRDS.md) under the fixed [Egypt operating policy](../scope/EGYPT_OPERATING_BASELINE.md). All OD references identify closed decisions. Exact orchestration/call direction is in [public contracts](../architecture/05-MODULE-CONTRACTS-EVENTS.md); numerical acceptance is in [ACCEPTANCE_CASES](../quality/ACCEPTANCE_CASES.md). A policy-reference is not an instruction to request user approval again.

## Shared execution and correction rules

An accepted operation has a stable source and purpose. Repeating the same request returns its original committed outcome; reusing its identity with changed content is a conflict. Validate permissions, current source state, source eligibility and applicable approval at execution time. A stale screen cannot authorize a later state. Lock/recheck competing balances and consumed eligibility; mandatory document, stock, GL, subledger and outbox records either all succeed or all roll back. Do not hold a transaction during physical work or human approval.

After commit, failed print/notification/export/integration delivery remains retryable without reposting business effects. A retry after an unknown response first resolves the source's current state. Errors distinguish forbidden, invalid input/state, stock conflict and closed period without leaking hidden records.

Before cancellation/reversal, show dependent receipts/deliveries/returns, allocations, claims, payroll payments or custody moves. Resolve those dependencies in their owners' allowed order. Never simply reverse a journal while retaining incompatible source balances. Closed-period corrections post linked adjustments on the current open date under adopted OD-05; no automatic backdate/reopen. Every correction preserves source, reason, author, evidence and links.

## WF-00 — Master data and scoped access

**Requirements/owner:** COM-01–03; M01-01–03, M02-01–03, M03-01–03, M17-01. Each master has exactly one owner.

**Trigger/actor/input:** Authorized administrator/catalog/party operator creates or changes a master. Require identity, active state, applicable location and permission. Check existing code, references and delegated authority.

**Processing:** Validate before save; referenced masters retain historical identity. Update effective attributes without rewriting issued documents; record change history. No universal approval engine; approval rules only where required by OD-06. Deactivation cannot silently move dependent work.

**Result/effects:** Active or inactive master and audit entry; no stock, cash or journal. Dependent modules receive approved current lookup data and use their own document snapshots.

**Failure/correction/acceptance:** Duplicate identity or privilege escalation changes nothing. A deactivated product remains legible on old invoices. Re-enable/correct with audit; destructive merge of financial identities is not assumed.

## WF-01 — Purchase order → receipt → supplier invoice

**Requirements/owner:** M05-01–03 orchestrates procurement; M06 owns movement/valuation; M11 GL; M12 AP. Related M02/03/07.

**Trigger/actor/input:** Buyer submits PO; approved receiver later confirms actual goods; finance posts supplier invoice. Require active supplier/products, permitted target warehouse, source order lines, quantities/serials, business date and approved cost/posting/mismatch rules (OD-03/04/05/06).

**Processing/approval:** PO approval commits no stock. Receipt rechecks remaining quantity and serial policy, records actual condition and atomically posts eligible Inventory receipt plus required GRNI/GL effects. Supplier invoice separately matches eligible receipts and price/tax amounts; mismatches remain visible pending authorized resolution. Posting creates payable and approved GL/GRNI clearing together; never another physical receipt. Freight/price differences follow adopted OD-04/05 and AT-08 examples, not an invented expense account.

**Calculation/result:** Ordered 10, confirmed receipt 6 → remaining receipt 4. Invoice matching tracks uninvoiced eligible receipt quantities separately. Receipt confirmed, supplier invoice posted, GL/AP references visible. GRNI residual explains received-not-invoiced goods under approved policy.

**Failure/recovery/correction:** Closed period or required GL failure rolls back that receipt/invoice operation, not an earlier committed PO/receipt. Duplicate receipt/invoice identity produces no duplicate stock/AP. Return after receipt uses WF-03; cancellation of residual PO never deletes confirmed quantities. Paid invoice correction first considers allocations.

**Acceptance:** One receipt and one invoice survive duplicate retries; AP and GRNI reconcile to approved posting matrix; unauthorized warehouse and mismatches cannot post. Concurrent receipts cannot overconsume order eligibility.

## WF-02 — Sales order → delivery and invoicing

**Requirements/owner:** M04-01–03 owns commercial documents; M07-01 owns delivery; M06 stock; M12 AR; M11 GL. M03 credit terms; M10 service billing source.

**Trigger/actor/input:** Sales clerk confirms direct/accepted-quotation order; dispatcher confirms actual issue/delivery; finance posts invoice. Require customer, branch, eligible source lines, warehouse for goods, approved prices/credit/tax/posting rules (OD-05/06), and current serial availability.

**Processing:** Confirming stocked-goods order reserves requested available stock; services do not reserve. Sales owns the posting entry: validate source/credit and use DeliveryReturns→Inventory once. Delivery-first creates/posts a fiscal invoice for exactly delivered units in the same transaction. Invoice-first leaves revenue deferred; delivery later consumes its quantity allocation and releases deferred revenue without another invoice/AR/VAT entry. Pure invoicing never issues stock.

**Calculation/result:** Order10, delivery4 → remaining6; delivery-first invoice bills4 atomically. Invoice-first4 then deliveries2+2 releases half the deferred revenue on each. Billed/delivered/returned/credited/paid totals remain separately visible. AT-09/10 fixes exact numbers.


**Failure/recovery/correction:** Concurrent last-unit requests allow one success. Insufficient stock, wrong scope, blocked credit/discount or stale version leave no partial effects. Cancel unfulfilled remainder and release remaining reservation once. Already delivered goods use return/credit path, not order deletion. Invoice reversal considers credits/payments, closed periods and original delivery.

**Acceptance:** Test delivery-first and approved invoice-first paths, duplicate direct-sale request, partial delivery, changed credit exposure and GL failure. Sum of physical issue units remains original fulfilled units, regardless of invoice retries. Use the exact adopted OD-05 posting matrix; no alternate milestone.

## WF-03 — Customer and supplier returns

**Requirements/owner:** M07-02/03 owns return/evidence; M06 movement; M04/M05 commercial correction; M12/M11 financial effects; M13 refund if applicable.

**Trigger/actor/input:** Authorized operator requests return with original delivery/receipt line, eligible quantity/serial, reason, condition and party. Inspection/approval rules OD-06/07; valuation and tax/credit policy OD-04/05.

**Processing:** Validate source ownership and cumulative unreturned eligibility; inspect into approved condition. Customer return creates one eligible stock receipt; supplier return creates one stock issue. Financial credit is separately traceable but required atomically with accepted trade-return stock in V1. Draft inspection may await approval without any posted physical/financial effect. Never equate refund cash with credit recognition.

**Calculation/result:** Delivered 5, prior returns 2 → maximum further return3; no alternative bypass in V1. Serial identity must match original when tracked. A paid invoice credit yields an approved refundable/unapplied position, not edited historic receipt. Source cost links retained; valuation OD-04.

**Failure/correction/acceptance:** Concurrent return of the same unit succeeds once. Rejected inspection has no saleable stock effect. Failed financial posting cannot masquerade as fully credited. Reverse physical/financial effects only after dependency checks; replacement eligibility never automatically reopens a PO/order without policy.

## WF-04 — Field work and workshop custody/parts

**Requirements/owner:** M08-01–03 owns site work; M09-01–03 owns repair/custody; M06 owns company parts. M03 owner/site; M10 coverage/estimate; M04 billing; M11 posting.

**Trigger/actor/input:** Dispatcher registers factory work or workshop operator receives a customer breaker. Require customer, site or workshop, reported problem, identity/condition/accessories, responsible actor and source evidence. Workshop test/release criteria OD-11; contract coverage OD-08.

**Processing:** Customer intake writes custody only. Diagnose and seek required estimate/repair approval. Company parts used on either job request one stock issue with immutable cost snapshot and required journal per OD-05. Unused parts return links original issue; cannot return more than issued and not yet consumed/returned under approved process. Time/expense evidence supports management job cost; salary GL remains exclusively payroll-originated.

**Custody transfer:** Origin authorizes transfer of an item currently held there; dispatch moves holder to explicitly recorded in-transit custody; destination accepts that transfer once. A discrepancy leaves the item visibly in transit/in dispute, never in two workshops. No automatic loss, write-off or ownership transfer. Further handover/transfer is denied if current state is incompatible.

**Completion/release:** Field completion needs required result/evidence and reconciled parts. Repair failed mandatory test returns to work/decision; successful test permits ready state. Customer handover verifies permitted recipient and records evidence; unrepaired return has its own result/reason and any charges require the accepted customer quote under OD-11. Handover is not automatically invoice/revenue.

**Calculation/result:** Job management cost = authorized parts cost + approved labor allocation + job expenses. Customer breaker contributes zero company inventory value. Financial billing eligibility follows WF-05 or approved service-sale policy OD-05.

**Failure/correction/acceptance:** Duplicate intake/part issue/transfer acceptance/handover has one result. Connection loss triggers safe status lookup/retry. Parallel handover and transfer cannot both consume one held item. Reversing a part usage restores linked inventory/cost once; no silent rewrite after billing. Customer custody balance/history reconciles all receipts, transfers and releases with no company-stock leakage.

## WF-05 — Contract → estimate/work → claim/installment → invoice → receipt

**Requirements/owner:** M10-01–03 orchestrates commercial eligibility; M08/M09 work evidence, M04 invoice, M12 balance, M13 cash, M11 GL.

**Trigger/actor/input:** Contracts officer records signed scope/value/coverage and a permitted billing mode; estimator proposes contract-dependent BOQ; approver certifies work or due installment eligibility. Adopted OD-08 fixes exclusive modes/approved variations/linked corrections; OD-05 fixes recognition/tax.

**Processing/approval:** Approve estimate/version without duplicating contract value. Record measured performed work with stable line/reference. Approve eligible claim quantities or release scheduled installment under signed terms. Allocate billable source to invoice once; partial billing retains remaining eligible amount. Claim approval does not itself create receipt or assumed revenue. Collection follows WF-06.

**Calculation/result:** For each approved work allocation, cumulative claimed quantity cannot exceed authorized performed/billable quantity; previously billed eligibility excluded. Contract summary separately shows signed value, measured/earned work, approved claims, invoices, credits, receipts and balance. Invoice outstanding derives from M12; it is not “contract value minus receipts.” No retention deduction.

**Failure/correction/acceptance:** Rejected claim can be revised with history; approved claim already invoiced cannot be simply deleted. Cancellation resolves billings/payments and remaining obligations under signed policy; retains history. Concurrent invoice generation for the same entitlement succeeds once. Test installment-only and claim-only contracts, reject mixed mode; no overlapping billing.

## WF-06 — Receipt/payment → allocation → reconciliation

**Requirements/owner:** M13-01–03 owns actual cash/bank; M12-01–03 open items/allocations; M11 journal.

**Trigger/actor/input:** Authorized cashier records approved money movement with date, bank/cashbox, amount, party/payee, reference and selected obligations; OD-05/06 define clearing, overpayment, cross-branch and approval rules.

**Processing:** Recheck payment amount/current open balances and counterparty/scope. Post required cash, journal and originating allocations atomically. A later reallocation is a separate short operation; it does not create another cash movement. Bank matching links statement evidence to existing movement; differences remain visible until approved resolution.

**Calculation/result:** Original invoice 100, valid prior allocation 40 → remaining 60. New cash 80 can apply at most 60; 20 remains identifiable unapplied under the adopted unapplied-funds policy. Remaining cash and invoice balances never negative through over-allocation. Internal cash transfer preserves total funds and has no revenue/expense effect.

**Failure/correction/acceptance:** Two concurrent allocations recheck locked balances. Repeat payment reference/source does not duplicate money. Reverse an allocation to reopen eligible obligation; reverse cash only through approved linked movement/GL and compatible settlement state. Test closed period, wrong party, role revocation, duplicate callback and rollback of required GL; no partial “paid” document with missing money/journal.

## WF-07 — Attendance/advance → payroll → payment

**Requirements/owner:** M15-01–03 time/leave/advance records; M14-01–03 employee/payroll; M13 disbursement/payment; M11 GL.

**Trigger/actor/input:** HR imports biometric or manual/system attendance and reviews exceptions; authorized approver freezes period summary. Payroll receives employee effective terms, approved overtime/deductions/manual bonus and recoverable advances. OD-09/10 define schedules/formulas/input formats; OD-05/06 accounts/approvals.

**Processing:** Stable punch source prevents repeated import; unknown mappings/ambiguous shifts require review. Advance approval alone creates no cash or recovery balance: Treasury disbursement and required advance financial effects are confirmed first. Payroll calculation freezes approved inputs and shows each component. Approval is invalidated by material recalculation. Posting synchronously records payroll/required GL and recognized advance recoveries; M15 applies recovery through its owner contract, no silent foreign writes.

**Calculation/result:** Net pay = approved base + overtime + manual bonus − deductions − advance recovery; component formulas/rounding are fixed in OD-09 and AT-21–24; verified statutory inputs are required. Confirmed advance 100, prior recovery 30 → maximum further recovery 70, subject to approved caps. Payroll post creates salary liability/expense once; Treasury payout settles liability, not another expense. Job labor allocation is analytical only.

**Failure/correction/acceptance:** Overlapping runs cannot post one employee/month obligation twice. Corrections have explicit original link and cannot evade uniqueness by a new ordinary run. After posting, changed attendance requires approved correction, not recomputation in place. Partial payroll payment 40 of 100 leaves 60. Retry payment/advance recovery once; correction reverses matching recovery/liability and considers money already paid. Unauthorized salary/advance export denied.

## WF-08 — Transfer and count → approved valuation adjustment

**Requirements/owner:** M06-02/03 owns physical facts and valuation; M11 GL.

**Trigger/actor/input:** Warehouse operator dispatches/receives transfer; counter captures barcode/manual count; permitted approver posts reconciled variance. Require product/location/serial scope and adopted OD-03/04/07 rules.

**Processing:** Transfer dispatch decreases source and increases in-transit exactly once; receiving actual quantity reduces in-transit/increases destination. Partial receipt keeps remainder in-transit; damages/discrepancies need approved settlement. Internal transfer is not a sale. Count records cutoff/expected baseline and observed quantities without a long database transaction. Under the adopted warehouse operational freeze, approval recomputes eligible variance and atomically posts movement, required GL and posted count. “Approved but stock not posted” is not presented as completed count.

**Calculation/result:** Transfer 5, receipt 3 → in-transit 2. Count observed 8 versus expected 10 at same reconciled cutoff → adjustment −2. Variance value uses the adopted moving-average valuation policy, not sale price.

**Failure/correction/acceptance:** Duplicate serial scan does not add unit. Stale count data, new movement or unauthorized approver triggers recheck/review. Repeat approval produces one adjustment/journal. Failure rolls back mandatory effects; posted count correction is linked adjustment, never overwrite original observed count. Concurrency tests use the chosen real DB engine.

## WF-09 — Authorized report/export

**Requirements/owner:** M16-01–03; source owners define measures, M01 grants, M17 documents.

**Trigger/input/process:** Permitted user selects approved definition, scope and date basis. Resolve source cutoff/refresh state, aggregate at defined grain, hide restricted fields before export. Reauthorize access to queued export at delivery; no broad report-role bypass. Print/export retains definition/date/scope used.

**Result/failure/correction:** Read-only result or clearly failed/unavailable/stale state; no stock/GL effects. Retry output safely. Zero rows is not failed-data-ingestion proof or proof of zero errors. Wrong totals require definition/query correction and reissue, not source-ledger edits.

**Acceptance:** Known fixture ties to source ledgers; invoice with two lines counted once at header grain; unauthorized branch/cost/payroll excluded from rows and totals/downloads. OD-13 and AT-25 supply fixed business examples; compare scoped source totals before acceptance.

## WF-10 — Legacy migration and recovery

**Requirements/owner:** M17-03 coordinates staging and stable mappings; each module owns imported facts. Finance signs opening/ledger reconciliation; OD-12/15 govern cutover/restore.

**Trigger/input/process:** Approved sanitized source sample → field/source-ID mapping → staged validation → dry-run → reviewed exceptions → owner-authorized import → reconciliation. Use opening balances plus open records at cutover; all older historical ranges are informational only; opening amounts exclude already posted imported history. Import scope records stock/serial/custody, GL, AR/AP, cash, advances/payroll and relevant contracts independently.

**Result/calculation:** Counts and monetary/quantity totals tie to signed source cutoffs and destination ledger/control totals. Imported custody never adds company stock. Restart reuses source-system/type/legacy-ID mapping, not another set of balances. Do not invent missing cost, serial or payroll detail.

**Failure/recovery/acceptance:** Conflicting rows quarantined with explanation; no silent overwrite. Failed batch/row retains explicit resumable status and committed effects. Trial rerun creates no duplicates. Practice restore into isolated environment and reconcile before cutover; a backup file alone is not restore proof. No live activation until actual imported source totals reconcile and the backup restore drill passes; OD-12/15 policy is already adopted.


## Required v2 refinements

WF-01: Inventory owns receipt Inventory/GRNI journal; Purchasing owns supplier invoice GRNI/AP/VAT. Price adjustment and supplier-return clearing follow the exact ERP-DOC-05 templates, never duplicate Inventory debit/credit.

WF-02: Sales `confirmDeliveryAndBilling` is the only delivery posting entry. Unbilled delivered quantities receive a fiscal invoice in that same transaction; pre-invoiced quantities release deferred revenue once. Invoice-only does not issue stock. Customer credit applies only up to original outstanding; excess becomes an AR credit balance eligible for refund/application under the owner contract.

WF-04/05: Noncontract completed work bills through the service owner to Sales. Contract work sends validated evidence to ServiceContracts; only Contracts bills it. Contract mode is exclusively installment or progress. Original service owner also coordinates any invoice correction so billable eligibility is never orphaned.

WF-07: Payroll coordinates employee-dependent time/leave/advance entry to avoid Attendance→Payroll cycle. Imported/entered verified statutory inputs are mandatory before calculate/post. Unknown employee, timezone or missing punch enters review; no automatic absence/fine. Monthly formulas and exact outputs AT-20–24 apply.

WF-10 and period close use the two named integration-composition entry points in IMPLEMENTATION_CONTRACT. System stores import staging/results only. Owner import APIs create each authoritative record. Close obtains period lock before reconciliation; no stale report authorizes a close.

## Conversational entry for WF-00–10

The [AI workflow protocol](../architecture/AI_NATIVE.md) changes interaction only. Each mandatory atomic workflow still enters its named owner coordinator once, never a model-generated sequence of stock/GL primitives. A purchase→receipt→invoice or contract→work→claim→invoice→receipt conversation spans separate real milestones: show saved/committed references, stop for missing evidence/independent approval and confirm each consequential step against current state. Failure retains earlier committed steps, exposes remaining work and resolves unknown outcome by original command/source; no automatic rollback/replay of previous milestones. WF-10 still uses fixed root Cutover; period close still uses fixed ClosePeriod. Arabic/English ambiguity clarifies before action; cancellation/reversal dependencies and all role/scope rules above remain mandatory. AI-AT04–07 test UI/API/AI parity with the existing AT numeric fixtures.
