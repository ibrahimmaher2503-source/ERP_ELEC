# M10 ServiceContracts

ServiceContracts owns signed contractual terms, coverage, reserved/consumed service entitlement, preventive commitments and contract billing sources. Organization owns employee identity and current permission/branch scope. Projects owns tender award; FieldMaintenance and WorkshopRepairs own actual work; M12 owns invoices, collections and credit balances; M11 owns journals; System owns official documents. Cross-owner access uses typed application contracts.

## Contract lifecycle

An authorized operator creates a draft with branch/customer, a genuine responsible employee, term dates, exact EGP value, SLA response/resolution, equipment/site coverage, visit/labor/parts limits and exclusions. Billing is either signed installments or measured BOQ claims, with an explicit tax basis. A tender award may create an unsigned draft; it never supplies a signature or approval.

Submission freezes the proposed revision for independent review. Signature evidence is a private uploaded file bound to the exact revision and terms digest. Activation requires that independent approval, genuine matching signature evidence and current authority. Amendment preserves prior revisions and committed financial/work sources. Renewal creates a linked new draft; suspension, resumption and closure retain history. Already committed installments, measured identities/rates and actual usage cannot be reduced or silently repriced by amendment.

## Work and entitlement

Coverage validates actual customer, branch, equipment, site, date and service kind. The owning service action registers its work source; reservation and consumption are separate, source-linked events under the common transaction lock. Actual visits, labor and parts synchronize from M08/M09. Verification consumes entitlement; eligible cancellation releases the reservation. A text contract reference grants no coverage.

An optional signed preventive schedule specifies code, equipment/site, service date and description. Dispatch creates a real M08 draft through its API and records one immutable source link. It does not fabricate a completed visit or permit new dispatch after expiry. Parts must also be supported by the owning stock/service workflow; full serialized service-parts return acceptance remains a release blocker.

## Billing and reports

Installments create independently approved deferred-revenue sources. Measured claims use approved actual measurements and preserve per-product original tax snapshots. M12 issues/posts the invoice and manages the resulting AR; contract reports read actual invoice, collection, advance-application and credit facts. Earned recognition requires actual consumed service evidence and approved source allocation. Corrections/reversals begin from the original M10 source and retain journals and documents.

Reports require `M10.reports_view` in the relevant branch. Financial details additionally require M12 AR authority; unavailable financial authority must not leak amounts. The executive dashboard reads owner projections and keeps GL, invoice and cash measures separate.

## Fixtures and evidence

`ServiceContractFactory` produces a realistic unsigned draft and revision/coverage/installment graph. `ServiceContractsDemoSeeder` is explicit, local/testing-only and idempotent; it creates planned drafts without signatures, approval, work usage or postings. Authoritative financial/dispatch/history entities are persisted through genuine owner actions in the acceptance graph. Additional draft financial factories remain a tracked limit; source history must not be invented to fill tables.

See `release/remediation/M10_SERVICE_CONTRACTS_ACCEPTANCE.md` for current commands, receipts and acceptance limits. Implemented source and focused PASS checks do not constitute final release or company UAT acceptance.
