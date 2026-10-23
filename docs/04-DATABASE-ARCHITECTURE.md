# Database architecture — fixed V1 data dictionary

2026-10-20 execution clarification: [ADR-0011](../adr/0011-sqlite-engine.md) selects SQLite for local development and [ADR-0012](../adr/0012-local-first-development.md) separates local, hosted and production acceptance. PostgreSQL17 types/mechanisms below record the original engineering prescription, not a production-engine decision or a local-development blocker. Keep the table/field inventory, exact decimal ranges, Unicode identity, foreign-key/uniqueness/check and overlap outcomes while porting mechanisms to SQLite. SQLite NUMERIC affinity alone does not meet exact-decimal acceptance. The integrator must document and test each replacement before accepting P04; missing production hosting/engine is deferred and does not block the port.

Version 2, adopted 2026-10-12 under delegated authority. Replaces the earlier illustrative schema. PostgreSQL 17, one database; [implementation contract](IMPLEMENTATION_CONTRACT.md) fixes types, naming, ownership and locking; [operating baseline](../scope/EGYPT_OPERATING_BASELINE.md) fixes business policies. This is a schema specification, not executed migrations.

## Common columns, types and constraints

Local SQLite representation follows [ADR-0013](../adr/0013-sqlite-exact-decimals-and-identity.md): logical numeric(p,s) values below are canonical decimal TEXT with explicit precision/scale validation and exact comparisons; declared SQLite DECIMAL is insufficient. Original logical ranges and business outcomes remain unchanged. Code identities use deterministic Unicode-normalized keys. These mechanisms must be verified per migrated table before P04 acceptance.

Every table has bigint `id`, UTC `created_at`, `updated_at` unless explicitly immutable; immutable tables retain created_at only. All named fields below required unless suffixed `?`; nullable is not zero/default approval. Reference suffix `_id` = bigint FK with RESTRICT deletion; Polymorphic source/obligation references are typed IDs validated by owner contracts, not impossible multi-table FKs. Explicit relational references use real FKs; cross-module FK is permitted for integrity, cross-module Eloquent relation/business queries are not. Integrator alone creates central migrations in dependency order. `code` string50, `name` string200, `status` string40 enum check, `date` date, `*_at` timestamptz, `qty` numeric18,4, `*_amount`/`*_value`/debit/credit numeric19,2, unit_price/unit_cost numeric19,6, rate numeric9,6; JSON only explicit snapshots/metadata, never a substitute for relational lines. `?` columns default null; other defaults only where stated. All unique keys and check constraints are named `uq_<table>_<purpose>` / `ck_<table>_<purpose>`.

Every mutable business document inherits `branch_id, number?, status='draft', business_date, origin=live/opening, version=1, created_by, approved_by?, approved_at?, approved_version?, posted_by?, posted_at?, source_type?, source_id?, correction_of_id?, reason?`. Posted number unique `(number)`; self-approval forbidden for policy-listed actions. Documents are hard-deleteable only if unreferenced draft; other states retained. Masters have `code unique, name, active=true, version=1`; referenced masters deactivate only. Immutable financial/stock facts use `source_module, source_type, source_id, source_line_id?, purpose, command_id, correlation_id, actor_id`, with owner-specific source uniqueness below. Approval snapshot includes all material lines and cannot survive changed document version.

Line convention: bigint document_id FK, positive `position`, immutable source-line link where applicable, unique `(document_id,position)`. Trading lines: `product_id?, description_snapshot, unit_snapshot, qty, unit_price, discount_amount=0, tax_profile_id, tax_rate_snapshot, net_amount, tax_amount, gross_amount, source_line_id?`; product required for stocked goods, service description required otherwise. `gross=net+tax`; `net>=0`; `qty>0`; discount within base. Currency fixed EGP. Header totals equal sum lines at posting, not independently editable. Money snapshots stored with documents, never resolved from mutable current price after posting.

## M01 Organization — exclusive owner

| Table | Additional columns | Keys / behavior |
|---|---|---|
| companies | code, name, currency='EGP', legal_name?, tax_registration?, address?, setup_complete=false | singleton ID1; live mode requires actual identity |
| branches | master fields, company_id=1, address? | unique code |
| users | Laravel auth fields, name, email, active=true, password, branch_id? | unique email; session revocation on disable |
| roles | code, name | fixed role codes from policy |
| user_roles | user_id, role_id | unique pair |
| access_grants | user_id, permission_code, branch_id?, warehouse_id? | permission namespace `Mxx.action`; unique normalized user/permission/scope; null means no location dimension, not all-locations grant |
| access_audits | actor_id, subject_user_id, action, before_snapshot, after_snapshot, occurred_at | immutable access history; no passwords/tokens |

Warehouse FKs installed after Inventory warehouse table by integrator. Branch-wide and warehouse-scoped actions validate both grants; payroll/cost are independent permissions.

## M02 Products

| Table | Additional columns | Keys / behavior |
|---|---|---|
| products | master identity, kind=serialized_equipment/bulk/service, sku, brand?, model?, base_unit, barcode?, tracking=serial/quantity/none | unique normalized SKU; unique nonnull barcode; no Product-owned tax requirement |
| product_prices | product_id, unit_price, effective_from, effective_to?, created_by | nonnegative, nonoverlapping effective ranges per product; service priced too |

Owner decision 2026-10-21: Product creation, activation, edit and future import/export are independent of Accounting. No tax_profile_id, tax-based catalog draft or commercial-readiness restriction belongs in Product Master. Sales/Purchasing validate tax at their transaction boundary through approved Accounting contracts. No tax calculations or inventory quantities in products.

Current M02 scope also authorizes hierarchical product_categories, brands, units_of_measure, explicit product_unit_conversions, structured specification metadata and private product_media. See m02-products-stage01.md for executable rules. No screenshot prices, warehouse balances or sample catalog data are seeded.

## M03 Parties

| Table | Additional columns | Keys / behavior |
|---|---|---|
| parties | master fields, is_customer, is_supplier, legal_name?, tax_id?, receiver_type=business/person/foreign, address?, phone?, email?, credit_limit_amount=0, payment_days=0, credit_changed_by? | at least one role; warn matching tax/name/contact, do not globally unique tax IDs |
| customer_sites | customer_id, code, name, address, contact_name?, phone?, active=true | unique customer/code; parent customer immutable after use |
| customer_equipment | customer_id, site_id, code, description, manufacturer_serial?, active=true | unique customer/code; not company stock |
| party_contacts | party_id, name, phone?, email?, role? | scoped to party |
| party_activities | party_id, site_id?, actor_id, occurred_at, kind, notes, next_action_at? | immutable authored interaction; correction note appended |

## M11 Accounting — created early for downstream schemas

| Table | Additional columns | Keys / behavior |
|---|---|---|
| accounts | master fields, parent_id?, type=asset/liability/equity/revenue/expense, postable, control_role? | no cycles; role unique when assigned |
| accounting_periods | year, month, starts_on, ends_on, status=open/closed, external_filing_final=false, closed_by?, closed_at? | unique year/month; no overlap |
| journal_entries | document fields, posting_key, purpose, source_module, reversal_of_id? | unique source_module/source_type/source_id/purpose; reversal one per original unless separate partial adjustment source |
| journal_lines | entry_id, position, account_id, branch_id, debit=0, credit=0, memo?, source_line_id? | exactly one positive side; totals balance at posting; no direct control journal |
| tax_profiles | code, name, effective_from, effective_to?, eta_type, eta_subtype, rate, recoverable, legal_reference, active | no overlap code/date; special zero/exempt carries legal code |
| tax_records | source_module, source_type, source_id, source_line_id, purpose, tax_profile_id, tax_point_date, base_amount, tax_amount, recoverable, reversal_of_id? | unique source-line/purpose; VAT register reconciles GL |
| withholding_certificates | direction=receivable/payable, party_id, payment_reference, number, date, base_amount, rate, tax_amount, evidence_id, actor_id | unique direction/party/number/date; no automatic universal rate |

## M06 Inventory

| Table | Additional columns | Keys / behavior |
|---|---|---|
| warehouses | master fields, slot smallint, branch_id | slot unique1..5, five active slots after setup |
| warehouse_bins | warehouse_id, code, name, active=true | unique warehouse/code; MAIN default |
| product_valuation_pools | product_id, qty, inventory_value, version | unique product; qty/value>=0; qty0 requires value0 |
| stock_balances | warehouse_id, bin_id, product_id, condition=available/quarantine/damaged, qty, reserved_qty, version | unique warehouse/bin/product/condition; 0<=reserved<=qty |
| inventory_units | product_id, normalized_serial, serial_display, verification=trusted/unverified, location_type=warehouse/transit/customer/consumed, warehouse_id?, bin_id?, transfer_line_id?, condition | unique product/normalized_serial for lifetime identity; one location combination only |
| stock_movements | source fields, product_id, warehouse_id?, bin_id?, inventory_unit_id?, condition, qty_delta, value_delta, movement_type, leg, unit_key, occurred_at, pool_effect | unique source_module/source_type/source_id/source_line_id/purpose/leg/unit_key with NULLS NOT DISTINCT; unit_key=0 for bulk aggregate, inventory_unit_id for serial; immutable; pool_effect false internal legs |
| stock_reservations | source_type, source_id, source_line_id, product_id, warehouse_id, qty, consumed_qty=0, released_qty=0, status, actor_id | active residual=qty-consumed-released>=0; unique source line/product/warehouse |
| stock_transfers | document fields, from_warehouse_id, to_warehouse_id | distinct warehouses |
| stock_transfer_lines | transfer_id, position, product_id, qty, dispatched_qty=0, received_qty=0, resolved_qty=0, cost_value, serial_snapshot? | receipt+resolved<=dispatch<=qty; residual remains owned in transit |
| stock_counts | document fields, warehouse_id, snapshot_at?, freeze_started_at?, freeze_ended_at? | at most one live freeze per warehouse |
| stock_count_lines | count_id, product_id, bin_id, condition, expected_qty, counted_qty?, variance_qty?, unit_cost?, reason? | unique count/product/bin/condition; counted nonnegative; mandatory variance reason |
| stock_count_scans | count_id, inventory_unit_id, scanned_by, scanned_at | unique count/unit |

Immutable serial location history uses stock_movements; no second mutable serial truth. Transfer movements and pool totals account for in-transit; sales availability excludes it. Counts freeze owner operations, not DB sessions.

## M12 ReceivablesPayables and M13 Treasury

| Owner / table | Additional columns | Keys / behavior |
|---|---|---|
| M12 open_items | party_id, branch_id, side=AR/AP, source_module/type/id, original_amount, credit_amount=0, allocated_amount=0, due_date, posted_at, version | unique source/side; remaining=original-credit-allocated>=0 |
| M12 credit_balances | party_id, branch_id, side=AR/AP, source_module/type/id, original_amount, applied_amount=0, refunded_amount=0, version | unique credit source/side; applied+refunded<=original; positive balance reduces net party control; never counts as cash |
| M12 credit_applications | credit_balance_id, target_type=open_item/refund, target_id, amount, source_type/id, reversal_of_id? | immutable unique source/purpose; positive amount; locks source credit and target |
| M12 payment_allocations | open_item_id, settlement_source_type/id, amount, allocated_at, actor_id, reversal_of_id? | source/open item/purpose unique; no negative allocations; reverse linked fact |
| M12 unapplied_funds | party_id, branch_id, side, source_payment_id, original_amount, applied_amount=0, version | unique source payment/side; applied<=original |
| M13 money_accounts | master fields, branch_id, kind=cash/bank, account_id, overdraft_limit_amount=0, bank_details? | cash overdraft always0; private bank fields |
| M13 payments | document fields, direction=receipt/payment/transfer, money_account_id, destination_account_id?, party_id?, obligation_type?, obligation_id?, amount, cleared_at?, method=cash/bank/cheque, external_reference?, posting_key | unique posting key; amount>0; cheque pending cannot settle |
| M13 expenses | document fields, category_account_id, party_id?, description, net_amount, tax_profile_id?, tax_amount, gross_amount, evidence_id, settlement=immediate/credit | posts expense once; links payment/AP |
| M13 bank_statement_lines | money_account_id, import_source_id, date, reference, signed_amount, matched_payment_id?, status | unique money account/source ID; amount exact; no auto balancing plug |

## M04 Sales and M05 Purchasing

| Owner / table | Additional columns | Keys / behavior |
|---|---|---|
| M04 quotations | document fields, customer_id, site_id?, revision, valid_until, net_amount, tax_amount, gross_amount | unique quote root/revision; sent revision immutable |
| M04 quotation_lines | standard trading lines, quotation_id | no stock/GL |
| M04 sales_orders | document fields, customer_id, quotation_id?, terms_snapshot, net_amount, tax_amount, gross_amount | eligibility separately tracked per line |
| M04 sales_order_lines | standard trading lines, order_id, warehouse_id?, confirmed_qty, delivered_qty=0, billed_qty=0, cancelled_qty=0 | delivered+cancelled<=confirmed; billed limited to authorized remaining |
| M04 sales_invoices | document fields, customer_id, kind=invoice/credit/debit/advance, net_amount, tax_amount, gross_amount, recognition=earned/deferred, original_invoice_id?, external_billing_source?, external_source_version? | unique external billing source+purpose; source changes cannot duplicate |
| M04 invoice_lines | standard trading lines, invoice_id, order_line_id?, earned_net_amount=0, credited_qty=0, credited_net_amount=0, source_evidence_snapshot? | earnings/credits cannot exceed original net/qty |
| M04 fiscal_submissions | invoice_id, channel=einvoice/ereceipt, payload_snapshot, payload_hash, external_uuid?, submission_id?, submission_version, state=pending/submitted/valid/invalid/cancelled, errors?, last_checked_at? | unique invoice/channel/submission_version; HTTP202 not valid |
| M05 purchase_orders | document fields, supplier_id, warehouse_id, net_amount, tax_amount, gross_amount | approved terms frozen |
| M05 purchase_order_lines | standard trading lines, order_id, ordered_qty, received_qty=0, cancelled_qty=0 | no overreceipt; replacement new line |
| M05 goods_receipts | document fields, purchase_order_id, warehouse_id, received_at, delivery_reference? | one source receipt identity |
| M05 goods_receipt_lines | receipt_id, position, order_line_id, product_id, qty, unit_cost, net_value, freight_value=0, condition, serial_snapshot?, invoiced_qty=0 | qty>0; invoice eligibility unique consumption |
| M05 supplier_invoices | document fields, supplier_id, supplier_number, supplier_date, net_amount, tax_amount, gross_amount, original_invoice_id?, kind=invoice/credit | unique supplier/normalized_number/fiscal_year/kind |
| M05 supplier_invoice_lines | standard trading lines, supplier_invoice_id, receipt_line_id, matched_qty, variance_amount=0 | cumulative matched<=eligible receipt; approved variance required |

## M07 DeliveryReturns

| Table | Additional columns | Keys / behavior |
|---|---|---|
| deliveries | document fields, sales_order_id, warehouse_id, recipient_name, evidence_id, delivered_at?, invoice_reference? | Sales entry coordinator supplies validated source; owner only writes delivery |
| delivery_lines | delivery_id, position, source_order_line_id, product_id, qty, serial_snapshot?, stock_movement_reference?, cost_value? | single physical effect source per line |
| returns | document fields, direction=customer/supplier, party_id, warehouse_id, original_document_type/id, evidence_id, inspected_by?, inspection_result, financial_correction_reference? | reject mismatch owner/source; only trade returns |
| return_lines | return_id, position, original_line_id, product_id, qty, condition, serial_snapshot?, cost_value, net_credit_amount, tax_credit_amount | cumulative return<=eligible original; serialized same unit one accepted return |

## M14 Payroll and M15 Attendance

| Owner / table | Additional columns | Keys / behavior |
|---|---|---|
| M14 employees | master fields, branch_id, user_id?, hire_date, termination_date?, date_of_birth?, aggregate_service_start?, insurance_regime?, registered_wage?, bank_details? | private pay/identity fields; not in attendance query |
| M14 employee_pay_terms | employee_id, effective_from, effective_to?, monthly_gross_amount, contractual_daily_hours, hourly_job_cost? | no overlap; positive pay/hours; cost only authorized |
| M14 payroll_runs | document fields, year, month, kind=ordinary/adjustment/opening, original_run_id? | one ordinary obligation enforced per employee/period lines, not just population |
| M14 payroll_lines | run_id, employee_id, year, month, kind, base_amount, overtime_amount, rest_holiday_amount=0, bonus_amount, absence_amount, other_deduction_amount, statutory_employee_amount, statutory_employer_amount, advance_recovery_amount, net_amount, paid_amount=0, input_snapshot, original_line_id? | partial unique ordinary employee/year/month; adjustment source unique; 0<=paid<=net |
| M14 statutory_payroll_inputs | employee_id, year, month, profile_code, income_tax_amount, employee_insurance_amount, other_statutory_amount, employer_contribution_amount, source_reference, evidence_id, approved_by, approved_at, revision | unique employee/year/month/revision; zero explicit; posted snapshot frozen |
| M15 employee_schedules | employee_id, effective_from, weekdays, start_local, end_local, overnight, break_minutes, timezone='Africa/Cairo' | validated employee supplied by Payroll coordinator, no foreign model query |
| M15 attendance_import_batches | source_code, file_hash, imported_by, status, accepted_count, rejected_count | same file replay safe |
| M15 attendance_events | employee_id, occurred_at, local_date, device_code, source_event_id?, fingerprint, direction=in/out, source=biometric/manual/system, correction_of_id?, reason? | unique source fingerprint; originals immutable |
| M15 approved_time_summaries | employee_id, period_start, period_end, regular_hours, day_ot_hours, night_ot_hours, unpaid_days, version, approved_by, approved_at, source_snapshot | unique employee/period/version; unapproved punches not payroll input |
| M15 leave_requests | document fields, employee_id, leave_type, from_date, to_date, days, evidence_id? | prevent overlapping approved absence; balances derived approved entitlement/usage |
| M15 leave_entitlements | employee_id, year, leave_type, days, source_reason, source_reference, actor_id | stable source identity; no year-end automatic forfeiture |
| M15 advances | document fields, employee_id, requested_amount, disbursed_amount=0, recovered_amount=0, scheduled_recovery_amount | recovery<=disbursed; approval does not imply money |
| M15 advance_movements | advance_id, kind=disbursement/recovery/refund/reversal, amount, source_type/id, reversal_of_id? | immutable unique source/purpose |

## M08 FieldMaintenance and M09 WorkshopRepairs

| Owner / table | Additional columns | Keys / behavior |
|---|---|---|
| M08 field_work_orders | document fields, customer_id, site_id, equipment_id?, contract_id?, problem, assigned_employee_id, planned_at, completed_at?, completion_evidence_id?, accepted_by? | customer/site validated; technician own jobs |
| M08 field_visits | work_order_id, technician_id, started_at, ended_at?, result?, evidence_id? | end>=start; no invented hours |
| M08 field_part_usage | work_order_id, product_id, warehouse_id, issued_qty, returned_qty=0, cost_value, stock_issue_reference | unique source issue; return<=issue |
| M08 field_work_evidence | work_order_id, version, description, measured_qty?, unit?, net_billable_amount?, customer_evidence_id, approved_by | unique job/version; submitted to Contracts/Sales through owner coordinator |
| M09 workshops | master fields, slot1..2, branch_id | exactly two active slots |
| M09 repair_intakes | document fields, customer_id, workshop_id, received_at, receipt_evidence_id | immutable posted receipt |
| M09 custody_items | intake_id, customer_id, model, serial_display?, normalized_serial?, temporary_id?, condition_text, accessories_snapshot, photo_evidence_id, holder_type=workshop/transit/released, holder_id?, version | physical held identity unique when trusted; temporary explicitly unverified |
| M09 custody_movements | item_id, from_holder_type/id?, to_holder_type/id, kind=intake/dispatch/accept/handover/unrepaired_return, actor_id, occurred_at, evidence_id, command_id | immutable source effect unique; current holder CAS version |
| M09 repair_jobs | document fields, custody_item_id, diagnosis?, contract_id?, quote_net_amount=0, customer_approval_evidence_id?, assigned_employee_id?, warranty_terms?, completed_at? | policy transitions; no automatic charge |
| M09 repair_part_usage | repair_job_id, product_id, warehouse_id, issued_qty, returned_qty=0, cost_value, stock_issue_reference | same source/return rules as field |
| M09 repair_tests | repair_job_id, version, checklist_snapshot, measurements_snapshot, manufacturer_procedure_reference, passed, technician_id, supervisor_id, tested_at | required failed item blocks ready; never generate electrical calibration limits |
| M09 custody_incidents | custody_item_id, type=loss/damage/dispute, description, evidence_id, reported_by, resolved_at?, resolution? | unresolved incident blocks release |

## M10 ServiceContracts

| Table | Additional columns | Keys / behavior |
|---|---|---|
| service_contracts | document fields, customer_id, starts_on, ends_on, billing_mode=installments/progress, signed_net_amount, coverage_snapshot, signed_evidence_id | mode frozen after active; no mixed mode/retention |
| contract_estimates | document fields, contract_id, revision, net_amount, customer_approval_evidence_id? | contract-dependent BOQ; approved version immutable |
| estimate_lines | estimate_id, position, work_code, description, unit, qty, unit_price, net_amount, brought_forward_performed_qty=0, brought_forward_claimed_qty=0, brought_forward_billed_qty=0 | positive qty; quantity/value limits; imported counters evidenced/immutable and included in cumulative limits |
| contract_installments | contract_id, position, due_date, net_amount, period_from, period_to, billed_net_amount=0, earned_net_amount=0 | unique contract/position; sum schedule=signed net |
| contract_work_evidence | contract_id, source_module, source_id, source_version, work_code, qty, unit, performed_at, customer_evidence_id, validated_snapshot | unique source/version/work; sent by source action, not arbitrary UI trust |
| progress_claims | document fields, contract_id, period_from, period_to, net_amount, billed_net_amount=0 | amounts derive lines |
| progress_claim_lines | claim_id, position, estimate_line_id, evidence_id, claimed_qty, unit_price, net_amount | cumulative claim<=approved performed eligible quantity |
| contract_billing_allocations | contract_id, source_type/id/line_id, invoice_id, net_amount, qty?, reversal_of_id? | source eligibility locked; totals<=eligible; invoice/correction unique |

## M16 Reporting and M17 System

Reporting owns predefined definition configuration and `report_exports(id, definition_code, requested_by, scope_snapshot, filters_snapshot, generated_at?, file_id?, status, error?)`; it reads authorized SQL views, no source writes. One row per report request. No report-builder tables. Define views using actual source grain and business dates from operating policy; protect payroll/cost fields before export generation and again at delivery.

| System table | Additional columns | Keys / behavior |
|---|---|---|
| settings | key, scope_code, effective_from, value, changed_by | unique key/scope/date; no plaintext secrets |
| documents | source_module/type/id, disk_path, original_name, mime, size, checksum, uploaded_by, access_scope | private access; never public path URL |
| audit_entries | actor_id, source_module/type/id, action, occurred_at, safe_before?, safe_after?, correlation_id | immutable, redacted; no duplicate access-audit truth |
| command_receipts | operation, actor_id, command_id, payload_hash, state=running/completed, result_snapshot?, completed_at? | unique operation/actor/command; running exists only inside active transaction; committed row must be completed with nonnull result/time and business effects |
| document_sequences | type, branch_id, year, next_number | unique type/branch/year; locked |
| outbox_events | event_id, type, source_module/type/id, payload, occurred_at, available_at, attempts=0, status, last_error? | unique event_id; redacted immutable payload |
| processed_events | consumer, event_id, processed_at | unique consumer/event |
| import_batches | source_system, source_hash, entity_type, mode=master/opening/informational, cutoff_date?, status, actor_id, summary | unique source/hash/entity/mode |
| import_rows | batch_id, legacy_id, payload, status, error?, target_id? | unique batch/legacy_id; errors explicit |
| legacy_id_map | source_system, entity_type, legacy_id, target_id, informational | unique source-system/type/ID |

## Migration and index acceptance

Integrator orders masters before references, then documents/lines, then cross-module FK additions. PostgreSQL NULL uniqueness is explicit: normalize absent scope with a stored scope key or use UNIQUE NULLS NOT DISTINCT for intended null-equality; choose the latter for access_grants here. All source/eligibility constraints remain DB-enforced in addition to actions. Add indexes on branch/status/business_date, party/due_date, source references and warehouse/product lookup used in actual queries; no speculative dozens of indexes.

Every migration must run forward on an empty PostgreSQL DB and have reversible pre-production down behavior. Never roll back production posted history destructively; use explicit forward-fix migration. No cascade delete of ledgers, posted lines, source evidence or payroll. No financial data in JSON-only blobs. Integration owner verifies data dictionary and actual migrations match before consumers start; omitted fields cannot be improvised silently by three agents.


## Precise correction and opening balance records

`open_items.credit_amount` contains only credits applied to currently outstanding amount; allocated history is retained. Credit note C applies min(C, original−credit−allocated) to that invoice. Excess creates `credit_balances` linked to the credit source; no edit to original receipt. Net AR control = remaining AR items − AR credit balances; AP analogous. Applying an excess credit to another same-party/side invoice increases that invoice credit_amount and credit_balance.applied_amount atomically, no new GL. A cash refund increases refunded_amount with Treasury movement and control-account journal. Every application is immutable/reversible through its owner.

Opening employee advances use Attendance.advances/movements and payroll-owned opening coordinator; opening unpaid payroll uses Payroll payroll_run kind `opening`, employee lines kind `opening`, original opening source unique, with verified outstanding net only (not new salary expense). Ordinary uniqueness index applies only kind='ordinary'. Imported open orders/contracts/custody retain original identity but do not repost historical effects. Information-only history stays import_rows/legacy_id_map with informational=true and is visible in a separately labelled archive view; it cannot masquerade as posted live transactional rows. Company control imports never duplicate owner-generated opening journals.

## M17 conversational persistence — minimum addition

Adopted2026-10-13, [ADR-0010](../adr/0010-conversational-erp-adapter.md). Common ID/UTC/FK rules apply; these are future migrations, no application code. Exactly three additional System tables; action tracking reuses command_receipts/audit_entries and source records rather than an AI event store.

| System table | Additional required / nullable columns | Constraints and privacy |
|---|---|---|
| ai_conversations | public_id UUID, user_id FK, locale=ar/en, scope_refs encrypted JSON, version=1, last_activity_at, expires_at | unique public_id; index user_id/last_activity_at; private to owner, no administrator transcript bypass. Scope is a selection hint, never a grant; current access on every read. Clear deletes permitted chat records only |
| ai_messages | conversation_id FK, request_id UUID, position int, kind=user/assistant/tool_summary/card, content encrypted text?, safe_record_refs encrypted JSON?, execution_bindings encrypted text?, correlation_id UUID, expires_at | unique conversation_id/position and conversation_id/request_id/position; one user turn per request_id enforced by partial unique conversation_id/request_id where kind=user. User input max4000 chars, sanitized response max16000; refs max100. execution_bindings only on user row, max10 steps/256KiB total, each low-risk payload max64KiB; immutable server stepIndex→operation/version/command UUID/hash/source refs; canonicalPayload only for immediate low-risk writes, intent reference only for consequential steps, no duplicate consequential payload. No raw provider payload/reasoning/credentials or confirmation bearer token. Referenced result/card detail reloaded/reauthorized, not cached business truth |
| ai_action_intents | public_id UUID, conversation_id FK, actor_id FK, session_binding_hash char64, operation_id string120, operation_version int, command_id UUID, payload_hash char64, payload encrypted text, source_versions encrypted text, state=pending/executed/rejected/expired/invalidated, expires_at, confirmed_at?, completed_at?, correlation_id UUID, invalidation_code? | unique public_id; unique operation_id/actor_id/command_id; payload/source snapshots <=64KiB each and never model-readable. Pending required snapshots+expiry; executed requires confirmed_at/completed_at and matching committed command receipt. Version/hash/session/actor bound; row claim held within business transaction only, no committed executing state. Terminal/expired purge includes sensitive payload; metadata audit retained via existing tables |

No chat `running` row is reused as a committed command receipt before effects. A prepared intent is distinct from execution: command receipt becomes completed only atomically with owner writes and executed intent. Do not widen command_receipts to represent provider generation/awaiting human approval. Audit records reference intent/public operation/command/correlation plus permitted source IDs, not sensitive intent payloads.

Conversation append checks expected conversation version, user request uniqueness and one active turn with the existing DB cache lock; no provider call during table/business transaction. Turn retry resolves prior sanitized response or pending intent, never reissues a draft write. Sequence append transactions are short. After grants/scope change, reauthorize saved refs and discard unsafe free-text context/cards; disabled users cannot read or continue history. No cross-user sharing/search/export of conversations in V1.

System.bindConversationStep is a narrow transaction-safe technical contract (not a model tool): authenticated actor/conversation/request/expectedVersion and server-validated operation/hash plus low-risk payload or prepared intent reference → stable stepIndex/command binding. Consequential binding and intent share command UUID; preserve its hash/reference but never copy sensitive intent payload into a30-day message. Lock the user-turn row to append a new immutable binding, then commit before owner execution; later identical binding returns itself, changed operation/hash rejects. No financial transaction or provider call is held while creating it. When executing a bound step, existing command tier protects duplicate effects; current authorization is still required. On recovery resolve each command receipt by saved actor/operation/UUID before regenerating provider calls; a committed draft receipt exists even if message append failed. No separate AI action table/status copy. Clear/expiry removes ephemeral bindings only; original command/source/audit remain, and continuation after purge is refused rather than inferred. AI-AT10/11 verify consequential payload is absent from message bindings and24h purge has no residual copy.

Live chat bodies expire30 days after creation; pending-intent lifetime10 minutes, sensitive terminal/expired payloads purged within24 hours (technical defaults from AI_NATIVE). Conversation expires30 days after last activity; independently expired messages still purge. Application purge deletes messages/intents then conversation explicitly, using RESTRICT FKs and short batches; only these ephemeral tables are deletable, business facts/audit/receipts are retained. User clear invalidates pending intents before deleting private history. Backups retain encrypted data under existing operational retention, not immediate erasure. Use existing encrypted storage/key management and verify backup restore; no separate vector store or AI action ledger. Schema indexes only user history, conversation ordering, intent lookup/state/expiry. Test encryption, uniqueness, expiry/purge, revocation, intent atomicity and restore with synthetic records (AI-AT06/10/11).
