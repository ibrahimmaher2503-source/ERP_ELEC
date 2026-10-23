# M04 quotation contract, owner approved 2026-10-21

Latest owner override2026-10-21: Administrator now receives every current/future registered permission by default. Earlier no-automatic-grant statements in this receipt are historical, superseded for Administrator only. Other roles, scopes and business prerequisites remain unchanged. See [RBAC policy](m01-rbac.md).

The following supplied contract supersedes legacy quotation rules only.

# CODEX MASTER PROMPT — M04 SALES / QUOTATIONS
# COMPLETE BUSINESS CONTRACT + EXECUTION
# ALL OWNER DECISIONS Q-01 TO Q-29
# APPROVED UI REFERENCES
# CONTRACT-FIRST VERTICAL SLICES · FAST DEVELOPMENT MODE

## YOUR ROLE

Act as the Lead Laravel ERP Architect, Senior Sales Domain Engineer, Livewire/Flux UI Specialist, and ERP Security Engineer.

Implement M04 — Sales / Quotations in the existing ERP modular monolith.

This is a critical commercial module. Every quotation must preserve pricing integrity, approvals, revisions, customer acceptance evidence, and traceability into Sales Orders.

The owner has completed the quotation business-decision process.

**Treat the decisions below as authoritative owner decisions. Do not reopen them or replace them with your assumptions.**

Use the attached approved quotation UI images:

1. Quotations List.
2. Create/Edit Quotation.
3. Quotation Details and Revisions.
4. Exception Approval.
5. Official PDF Preview.
6. Customer Acceptance and Sales Order Conversion.

If the attached montage contains duplicate/alternative list layouts, follow the established M01–M03 shared UI design system for the final implementation.

**CODE FIRST. CONTRACT FIRST. REUSE FIRST.**

---

# 1. MANDATORY TECHNOLOGY

Use the existing approved stack:

- Laravel
- Livewire
- Flux UI
- Blade
- Tailwind CSS
- PostgreSQL
- Eloquent
- Existing Modular Monolith

Do not introduce:

- Filament
- React
- Vue
- Inertia
- Prisma
- Separate frontend/backend applications

Preserve Arabic-first RTL and English LTR support.

Reuse the existing ERP design system and application architecture.

Do not recreate already implemented shared components.

---

# 2. FAST DEVELOPMENT MODE

Follow exactly four implementation steps for each vertical slice:

1. Contract + Business Rules
2. Required Database Migration
3. Application Actions + Authorization
4. Livewire + Flux UI

Do not create the entire Sales database schema upfront.

Build small executable slices.

Routine comprehensive automated tests, Playwright, and measured screenshot comparisons are deferred.

Mandatory essential checks remain:

- Laravel boot
- Routes
- Migration compatibility
- Real persistence
- Monetary calculation correctness
- Authorization
- Approval state transitions
- Immutable issued revisions
- Concurrent conversion safety
- Secure PDF and Excel access

Do not claim comprehensive QA acceptance.

Use IMPLEMENTED — QA DEFERRED only for completed and minimally verified features.

---

# 3. AUTHORITATIVE BUSINESS DECISIONS

The following 29 decisions are approved.

## Q-01 — Suggested Pricing With Authorized Override

When adding a product to a quotation, display its current suggested selling price from the approved pricing source.

An authorized sales user may request a different price.

A requested price override requires approval according to the configured delegation policy.

Store:

- Suggested price.
- Requested price.
- Override reason.
- Approval outcome.
- Relevant source references.

Do not silently replace the suggested price with a user-entered value.

Do not invent a pricing engine or a price list if its owning contract is unavailable.

## Q-02 — Exception-Based Quotation Approval

A user with the required issue permission may issue quotations that comply with approved prices and commercial terms without additional approval.

A quotation containing exceptions requires approval before issue.

Do not send every quotation through mandatory approval.

Do not allow exception-bearing quotations to bypass approval.

## Q-03 — Configurable Validity Period

Quotation validity has:

- Configured default duration.
- Approved adjustment limits.
- Exception approval when outside limits.

Validity rules are configurable by authorized users.

Do not hardcode an unapproved default number of days.

Persist issue date and validity/end date according to the approved policy.

## Q-04 — Approved Payment and Delivery Templates

Sales users select approved payment and delivery terms.

Unapproved or exceptional terms require approval.

Use stable versioned or snapshot references so an already-issued quotation does not change when the master term template changes.

Do not create financial posting or fulfillment behavior inside quotation terms.

## Q-05 — Immutable Issued Revisions

Draft quotations may be edited.

After issuance, an issued revision is immutable.

Any subsequent commercial change creates a new revision while preserving the previous issued revision.

Revisions retain the same quotation number and a separate revision identifier.

Approval for an older revision does not automatically approve changed exceptions in a newer revision.

## Q-06 — Customer Acceptance Evidence

Customer acceptance must reference a specific issued revision.

A written acceptance record is normally required before converting the quotation to a Sales Order.

Supported evidence may include:

- Customer purchase order.
- Customer email.
- Signed written acceptance.

Verbal acceptance may support conversion only through a documented, authorized exception.

Record:

- Evidence type.
- Evidence reference or attachment.
- Acceptance date.
- Recording actor.
- Applicable approved exception.

Do not treat a salesperson's unchecked statement as sufficient evidence for conversion.

## Q-07 — Full or Partial Sales Order Conversion

Allow conversion of all or part of the accepted quotation.

Support multiple Sales Orders referencing the same accepted revision, subject to remaining convertible quantities.

For each line track:

- Accepted quantity.
- Successfully converted quantity.
- Remaining convertible quantity.

Prevent double conversion and over-conversion.

Enforce this atomically server-side.

Quotation conversion quantity accounting is separate from actual inventory reservations.

Do not create warehouse stock reservations here.

## Q-08 — Optional Project Association

Quotation creation does not require a Project.

Allow:

- No project.
- An existing project reference when the owning contract is available.
- Manual project name/customer RFQ reference.

Do not create Project master data inside M04.

## Q-09 — Catalog Products Only

Every quotation line must reference a real M02 Product ID.

No free-text-only product lines.

If a requested item is not registered, it must first be added to the Product Catalog.

Allow a quotation-specific description without modifying the underlying Product Master.

At issuance, snapshot product description, SKU, unit and other required identity values.

Do not let future Product Master changes mutate an issued quotation.

## Q-10 — EGP Only

All quotations use Egyptian Pounds.

Currency code: EGP.

All line prices, discounts and totals use EGP.

No quotation currency conversion is required in this implementation.

Do not impose EGP-only behavior on Purchasing or other modules.

## Q-11 — Line and Quotation Discounts

Support discounts at:

- Individual quotation line level.
- Whole quotation level.

Both may exist together.

Show their individual and combined impact.

Prevent invalid double application.

Apply actual approval rules.

## Q-12 — Percentage or Fixed-Amount Discounts

Both line discounts and quotation-wide discounts may be:

- Percentage.
- Fixed amount in EGP.

Each discount instance has one selected calculation mode.

Apply quotation-wide discount after line discounts.

Reject negative discounts or discounts exceeding applicable amounts.

Use explicit monetary rounding conventions and decimal precision from approved contracts.

Do not use binary floating-point for financial amounts.

## Q-13 — Manual Discounts Only

No automatic discounts based on customer, quantity, segment or volume.

Users enter discounts manually.

Each discount is evaluated against the applicable approval policy.

Do not invent promotional or automatic discount engines.

## Q-14 — Prices Excluding Tax

Quotation unit prices are tax-exclusive.

Calculation presentation:

1. Gross line amounts.
2. Line discounts.
3. Quotation-wide discount.
4. Net amount before tax.
5. Separately calculated tax.
6. Final amount including tax.

Use the approved Accounting/Tax public contract.

Do not hardcode a tax percentage.

Do not invent a Sales-owned tax engine.

If tax calculation is unavailable, allow dependency-independent drafting but block official issue when required tax amounts cannot be reliably calculated under the approved contract.

Display the exact blocker.

Do not generate a misleading official PDF with estimated taxes.

## Q-15 — One Fixed Official PDF Template

Use ONE official company quotation PDF layout.

Company logo and information come from M01.

Include:

- Quotation number and revision.
- Company identity.
- Customer identity.
- Project/reference where applicable.
- Quotation issue/validity dates.
- Product lines.
- Quantities.
- Units.
- Unit prices.
- Discounts.
- Tax breakdown.
- Final total.
- Approved payment and delivery terms.
- Authorized signature area or approved equivalent.

Each issued revision retains its immutable official PDF artifact.

No multiple-template selector.

No drag-and-drop template editor.

## Q-16 — Manual Delivery

Users download the official PDF and send it externally.

No automatic email or WhatsApp integration.

Users record:

- Sent revision.
- Sent date/time.
- Delivery method.
- Recipient.
- Recording actor.

Downloading the PDF alone must not mark the quotation as sent.

## Q-17 — Final Closure

Expired, cancelled or finally rejected quotations are closed.

They cannot be reopened.

No new revision may be created from the closed quotation.

If the customer returns, create a new quotation with a new number.

Allow copying eligible old quotation data into the new draft where approved.

Never mutate the archived issued artifacts or historical approvals.

Previously valid Sales Orders remain independent.

## Q-18 — Separate Commercial and Conversion Status

Maintain separate concepts:

### Commercial Lifecycle Status

Examples of supported phases:

- Draft.
- Pending Approval.
- Issued.
- Sent.
- Accepted.
- Rejected.
- Expired.
- Cancelled.

Use a coherent state machine, not unrestricted status editing.

### Conversion Status

Derived from actual successful conversion allocations:

- Not Converted.
- Partially Converted.
- Fully Converted.

Users must not manually set conversion status.

Do not conflate commercial acceptance with order conversion.

## Q-19 — Administrator-Managed RBAC

Authorized security administrators manage quotation permissions through the existing M01 Dynamic RBAC interface.

Supported action permissions include:

- Create.
- View.
- Edit Draft.
- Submit Approval.
- Withdraw Approval.
- Approve/Reject.
- Issue.
- Record Delivery.
- Record Customer Acceptance.
- Cancel.
- Create Revision.
- Duplicate.
- Export.
- Import Lines.
- Convert to Sales Order.

Use actual approved permission key conventions.

Do not hardcode privileges into role names.

Authorization is always enforced server-side.

## Q-20 — Configurable Self-Approval

Self-approval is disabled by default.

An authorized administrator may configure self-approval for specific roles/users, subject to the approved delegation contract.

The user must still possess approval permission.

Audit:

- Policy changes.
- Actor.
- Effective target.
- Self-approved operations.

Do not allow unchecked self-approval via a front-end toggle.

This decision supersedes older blanket self-approval prohibition rules for quotation exceptions, but does not automatically remove unrelated mandatory legal or financial segregation controls.

## Q-21 — One Approval Authority for All Exception Types

Use one configurable approval authority for all quotation exceptions, including:

- Price overrides.
- Discounts.
- Validity exceptions.
- Payment/delivery term exceptions.

The authorized administrator determines who receives approval authority.

Do not implement a separate mandatory approver for each exception type.

## Q-22 — One Decision for All Exceptions

A quotation approval request contains all current exceptions.

The approver may:

- Approve all.
- Reject all and return the quotation for revision.

Do not implement partial approval of individual exceptions.

Store the submitted exception snapshot.

A rejection reason must be recorded according to the approval contract.

## Q-23 — Withdraw, Edit, Resubmit

While Pending Approval, the authorized user may withdraw the approval request.

On withdrawal:

- Mark the old approval request Withdrawn.
- Invalidate its ability to receive a new decision.
- Return quotation to Draft.
- Permit authorized editing.
- Require a new approval request for applicable exceptions.

Preserve prior approval request history.

Protect against approval of stale or withdrawn requests.

## Q-24 — Annual Automatic Numbering

Use:

QT-YYYY-NNNNNN

Examples:

QT-2026-000001
QT-2026-000002

Reset sequence per calendar year.

Revision number is separate.

Numbers must be unique under concurrency.

Never recycle previously allocated identifiers.

Do not let ordinary users edit official quotation numbers.

Follow existing company timezone/date configuration when deciding numbering year.

## Q-25 — Duplicate With Current Suggested Prices

Allow creating a new quotation from an existing one.

Copy:

- Customer reference.
- Optional project/reference.
- Product lines.
- Quantities.
- Valid units.

Do not copy:

- Old discounts.
- Old approvals.
- Old acceptance.
- Old sent state.
- Old issued PDF.
- Old quotation number.

Re-fetch current suggested prices.

Re-select or validate current approved commercial terms.

New quotation starts as Draft.

Missing product prices must be explicitly flagged, not guessed.

## Q-26 — Manual Draft Save

No Autosave.

The user explicitly clicks Save Draft.

Warn before leaving with unsaved changes.

Users with appropriate permission may reopen and continue a saved draft.

Protect against silent overwrite of another user's changes through optimistic concurrency or an equivalent safe version check.

Do not auto-submit draft for approval.

## Q-27 — Internal Attachments Only

Quotation attachments are internal.

Allow appropriate authorized documents such as:

- RFQ.
- Technical specifications.
- Drawings.
- Internal correspondence.
- Acceptance evidence.

Do not attach these files automatically to the official customer PDF.

Do not expose internal documents in customer-facing download packages.

Apply private storage and explicit access control.

## Q-28 — Excel Template Import for Quotation Lines

Reuse the existing shared Excel Import engine from M02/M03.

Mandatory template-first workflow.

Import file contains:

- Product SKU.
- Quantity.
- Unit of measure.

Match all products to M02 Product Master.

Reject unknown products/invalid units.

Do not create Product Master records.

Do not import prices or discounts from Excel.

Fetch current suggested prices.

Validate preview before applying changes.

Apply imported lines only to the unsaved draft UI state until the user explicitly saves the draft under Q-26.

No unauthorized database writes during preview or draft import.

## Q-29 — Excel Export Including Prices and Totals

Reuse shared Excel Export infrastructure.

Export authorized quotation data including:

- Product/SKU.
- Quantity.
- Unit.
- Unit price.
- Line discounts.
- Quotation discount.
- Line totals.
- Net before tax.
- Tax values where calculated.
- Final total where calculated.

Apply field-level and operation-level permissions.

Do not export unauthorized financial information.

No separate BOQ-only export is required in this first phase.

---

# 4. CONFLICT RESOLUTION

Inspect legacy contracts before implementation.

Where older documents conflict with the explicit Q-01 to Q-29 decisions, treat these latest decisions as owner-approved superseding requirements for M04 Quotations.

Update the authoritative quotation contract with:

- Old rule.
- New owner decision.
- Scope of replacement.
- Remaining unresolved dependencies.

Do not silently modify rules belonging to unrelated modules.

Do not invent missing tax rules, price source, approval identity, payment templates, or Sales Order interfaces.

Where a missing dependency blocks one feature, isolate that feature and continue implementing independent slices.

---

# 5. MANDATORY MODULE BOUNDARIES

## M01 Organization

Owns:

- Users.
- Roles.
- Permission assignments.
- Branch scope.
- Audit infrastructure.
- Company identity.

Consume through approved contracts.

## M02 Products

Owns:

- Product identity.
- SKU.
- Unit conversions.
- Product descriptions.
- Catalog status.

All quotation lines reference valid Products.

Do not duplicate Product Master tables.

## M03 Customers

Owns Customer Master and contact/address references.

Use actual Customer IDs.

Do not create customers from arbitrary quotation text.

## Pricing Owner

Use only the approved pricing source.

Do not build an arbitrary replacement price engine inside Quotations.

## Accounting/Tax Owner

Owns tax rules/calculation.

Quotation stores required issued tax calculation snapshots, not a competing tax master.

## Inventory Owner

Owns stock balances and reservations.

Quotation draft/issue must not alter stock.

## Sales Orders

Own the resulting order lifecycle.

Quotation owns only the source revision reference and convertible quantity tracking under the approved conversion contract.

Do not implement complete Sales Orders as an accidental subfeature of Quotations.

---

# 6. QUOTATIONS LIST — APPROVED UI

Build the full Quotations List shown in the approved reference.

Arabic title:

عروض الأسعار

Reuse the existing ERP Sidebar, Topbar, Page Header, Stat Cards, FilterBar and DataTable.

Display authorized real data.

Suggested columns:

- Quotation Number.
- Customer.
- Project/Reference.
- Latest Revision.
- Issue Date.
- Valid Until.
- Commercial Status.
- Conversion Status.
- Subtotal Before Tax.
- Tax.
- Final Total.
- Sales Representative.
- Actions.

Filters:

- Quotation Number.
- Customer.
- Project/Reference.
- Commercial Status.
- Conversion Status.
- Sales Representative.
- Date Range.

Implement:

- Server-side pagination.
- Sorting.
- Search.
- Status badges.
- Real KPI cards.
- Authorized row actions.
- Excel Export.

Do not fabricate quotation totals, status records, or sample customer records.

Reuse shared DataTable presentation and search/filter components.

No unrestricted generic bulk status editing of quotations.

---

# 7. CREATE / EDIT QUOTATION — APPROVED UI

Implement a dedicated full-page quotation editor.

This is NOT a small generic modal.

Use the approved image as the structural reference.

## Header Information

- Quotation Number (generated/read-only).
- Customer selector.
- Optional Project reference.
- Customer RFQ reference.
- Quotation date.
- Validity period/date.
- Sales representative.
- Currency EGP.

## Quotation Lines

Reusable editable line table:

- Product search/select.
- SKU.
- Description snapshot/draft override.
- Unit.
- Quantity.
- Suggested unit price.
- Requested unit price.
- Discount type/value.
- Net line amount.
- Row validation.
- Remove/reorder line.

Allow add/remove lines while Draft.

Use approved unit conversion rules.

Do not allow a free-text-only Product line.

## Pricing Summary

Display:

- Gross subtotal.
- Line discounts.
- Quotation-level discount.
- Net before tax.
- Tax where available.
- Grand total where available.

Use decimal arithmetic.

Show exact validation messages.

## Terms

- Approved payment template.
- Approved delivery template.
- Validity.
- Notes.

Mark unapproved exceptions visibly.

## Save Actions

- Save Draft.
- Submit for Approval where required.
- Issue when authorized and all dependencies are satisfied.

Honor manual-save policy.

Do not persist draft edits automatically.

---

# 8. QUOTATION DETAILS — APPROVED UI

Provide a dedicated details screen.

Include:

- Quotation number and revision.
- Commercial status.
- Conversion status.
- Customer.
- Project.
- Issue/expiry information.
- Line details.
- Pricing breakdown.
- Commercial terms.
- Issued PDF.
- Approval history.
- Delivery history.
- Customer acceptance.
- Internal attachments.
- Revision history.
- Audit timeline.

Render only legitimate available actions.

Do not permit edits to an issued revision.

When issuing a new revision, preserve earlier immutable artifacts.

---

# 9. APPROVAL WORKFLOW

Implement real approval requests using M01 RBAC/delegation policy.

Submission must snapshot:

- Requested price overrides.
- Discounts.
- Validity exceptions.
- Term exceptions.
- Reason(s).
- Submitted revision/draft version.
- Requesting actor.
- Timestamp.

Approval screen should show all exceptions together.

Approver may approve all or reject all.

Before approval:

- Verify request remains pending.
- Verify target quotation version.
- Verify actor's current approval authority.
- Evaluate configured self-approval rules.
- Ensure request has not been withdrawn.
- Ensure no invalidating change occurred.

Store immutable decision history.

No client-side-only approvals.

No automatic approval because the actor happens to be Administrator.

---

# 10. PDF GENERATION

Generate the single official PDF template.

Use M01 company branding and approved quotation data.

Make the layout suitable for Arabic RTL and English content.

Support multiple pages for large quotation line counts.

Include correct page numbers and repeated table headers.

Ensure decimal formatting and EGP labeling are correct.

The issued revision must preserve:

- The exact content snapshot.
- Official PDF artifact.
- Issue timestamp.
- Approving reference where relevant.
- Immutable document identity/hash where supported.

Never silently regenerate or replace an already-issued PDF using current mutable master data.

Do not add internal attachments.

---

# 11. CUSTOMER ACCEPTANCE

Implement an acceptance-recording workflow.

A user selects an issued revision and records:

- Acceptance method.
- Acceptance date.
- Customer reference.
- Evidence attachment or approved exception.
- Notes.

Written evidence must be linked to the acceptance.

Verbal acceptance requires an authorized documented exception.

Ensure revoked/expired/cancelled quotations cannot bypass conversion restrictions.

Do not automatically convert merely because acceptance was recorded.

---

# 12. SALES ORDER CONVERSION

Implement the quotation-side conversion workflow and its approved integration boundary.

Support:

- Full conversion.
- Partial conversion.
- Remaining line quantity.
- Multiple conversions with proper tracking.
- Idempotency/concurrency safety.

Before conversion:

- Verify accepted source revision.
- Verify commercial eligibility.
- Verify requested quantities.
- Verify approval/evidence state.
- Recheck conversion permission.
- Check the actual Sales Order owning contract.

Use database transactions and row locks or an equivalent correct concurrency strategy.

Do not reduce warehouse stock or create inventory reservations here.

Do not create a fake Sales Order.

If Sales Orders is not implemented, provide the prepared Quotation conversion UI/Action boundary but report actual order creation as BLOCKED until the approved Sales Order contract is available.

Do not mark conversion complete without a real persisted Sales Order.

---

# 13. EXCEL IMPORT / EXPORT

Reuse the shared Excel infrastructure built in M02/M03.

No new quotation-specific spreadsheet engine.

Register:

- QuotationLineImportDefinition
- QuotationExportDefinition

Import is template-first and matches Product SKU + quantity + unit.

Export includes approved line/discount/total fields.

Enforce template versions, row validations, spreadsheet safety and authorization.

Do not duplicate parser, uploader, or report components.

---

# 14. DATABASE DESIGN

Use feature-specific migrations only.

Conceptual entities may include:

- Quotation.
- Quotation Revision.
- Quotation Line Snapshot.
- Approval Request.
- Approval Decision.
- Exception Snapshot.
- Quotation Terms Snapshot.
- Quotation Attachment Reference.
- Delivery Record.
- Acceptance Record.
- PDF Artifact Reference.
- Conversion Allocation.

These are concepts, not authorization to create every table immediately.

Reuse existing approved schema and naming conventions.

Ensure:

- Stable identifiers.
- Referential integrity.
- Atomic number allocation.
- Version/revision uniqueness.
- Monetary precision.
- Immutable issued data.
- Approval request lifecycle integrity.
- Safe concurrency.
- Correct remaining quantity calculations.
- Audit references.

Do not create complete Sales Order, Accounting, Inventory or Project schemas here.

---

# 15. SECURITY AND AUDIT

Use existing M01 Dynamic RBAC.

Server-side authorization for every state transition and mutation.

No permission implied by an ordinary UI button.

Audit at minimum:

- Create/update quotation.
- Price override request.
- Discount change.
- Approval submission.
- Approval withdrawal.
- Approval/rejection.
- PDF issue.
- Revision creation.
- Manual delivery record.
- Acceptance record.
- Cancellation/rejection.
- Sales Order conversion where implemented.
- Delegation/self-approval policy changes through the owning security module.

Protect private customer data, internal quotation documents, prices and approval records.

Use the existing audit infrastructure; do not create a competing audit system.

---

# 16. SHARED UI COMPONENTS

Reuse:

- ERP App Shell.
- Sidebar.
- Topbar.
- Page Header.
- Breadcrumbs.
- Stat Cards.
- DataTable.
- Search/Filter components.
- Product Selector.
- Customer Selector.
- Status Badges.
- Form Components.
- Section Cards.
- Confirmation Dialog.
- File Uploader.
- Import Wizard.
- Excel Export.
- Audit Timeline.
- User/Role/Permission components.

Build new reusable components only when genuinely needed, such as:

- QuotationLineEditor.
- MonetarySummary.
- ExceptionSummary.
- RevisionTimeline.
- ApprovalDecisionPanel.
- AcceptanceEvidenceForm.
- ConversionQuantityEditor.

Do not duplicate current Product/Customer selectors or shared Excel engines.

Respect the approved Navy/Teal ERP design tokens and Arabic RTL interface.

---

# 17. EXECUTION SLICES

Implement in this sequence.

## M04-Q-S01 — Contract & Foundation

- Persist Q-01 to Q-29 in authoritative contracts.
- Resolve actual code dependencies.
- Implement quotation foundation.
- Minimal database schema.
- RBAC permission registry.

## M04-Q-S02 — Quotation List

- List.
- Search.
- Filters.
- Pagination.
- Real KPI data.
- Navigation.

## M04-Q-S03 — Draft Editor

- Create/edit.
- Customer selector.
- Product catalog lines.
- Current suggested prices.
- Unit validation.
- Manual draft saving.
- Concurrency protection.

## M04-Q-S04 — Discounts & Commercial Terms

- Line discounts.
- Global discount.
- Percentage/fixed amount.
- Discount calculations.
- Approved commercial templates.
- Validity exceptions.
- Approval requirement detection.

## M04-Q-S05 — Approval Workflow

- Submit.
- Snapshot exceptions.
- Approve/reject all.
- Withdraw and resubmit.
- Configurable approver.
- Configurable self-approval.
- Audit.

## M04-Q-S06 — Issue & PDF

- Official numbering.
- Revision identity.
- Issued snapshots.
- Official fixed PDF.
- Immutable artifact storage.
- Manual delivery recording.

## M04-Q-S07 — Details & Revisions

- Full quotation details.
- History.
- Issued revisions.
- Closed-state behavior.
- Duplicate with current prices.

## M04-Q-S08 — Acceptance

- Written evidence.
- Documented verbal exception.
- Accepted revision.
- Authorization.
- Audit.

## M04-Q-S09 — Conversion Integration

- Full/partial conversion interface.
- Quantity allocation rules.
- Order creation via actual Sales Orders contract if available.
- Explicit blocked state if unavailable.

## M04-Q-S10 — Excel Integration

- Template-first line import.
- Product matching.
- Draft-only line insertion.
- Quotation export.
- Shared infrastructure reuse.

## M04-Q-S11 — Integration & Cleanup

- Module public contracts.
- Shared UI consolidation.
- Essential checks.
- Pipeline updates.
- Honest implementation status.

Proceed through independent dependency-ready slices automatically.

Do not block the entire module because a later-stage dependency is unavailable.

---

# 18. ESSENTIAL VERIFICATION

Perform focused checks of:

- Valid Draft creation.
- Manual save.
- Duplicate SKU line handling according to approved rules.
- Quantity/unit validation.
- Monetary/discount calculation.
- Price override detection.
- Approval permission enforcement.
- Withdrawal invalidates pending approval.
- Approval cannot apply to changed data.
- Self-approval policy enforcement.
- Numbering uniqueness.
- Issued revision immutability.
- No editing closed quotations.
- Expiration restrictions.
- Official PDF preservation.
- Customer acceptance evidence.
- Partial conversion cannot exceed accepted quantities.
- No double conversion.
- Excel import does not persist unsaved draft.
- Excel export respects permissions.
- M01–M03 remain functional in affected shared components.

Do not run routine full regression suites, Playwright, or measured visual comparisons.

Fix any critical defect discovered in essential checks.

---

# 19. OWNER DECISION AND BLOCKER HANDLING

The owner has explicitly settled Q-01 through Q-29.

Do not ask the owner to reconfirm them.

Do not invent:

- Tax rates.
- Pricing source data.
- Validity duration numbers.
- Discount authority thresholds.
- Financial signing authority.
- Commercial template wording.
- Sales Order schema.
- Project master records.

For any missing required detail:

1. Inspect authoritative contracts and existing code.
2. Use approved configuration only.
3. Identify the exact unresolved dependency.
4. Block only the affected operation.
5. Continue independent functionality.

Where earlier contracts conflict, document which latest Q decision supersedes them.

Do not silently override unrelated module policies.

---

# 20. COMPLETION RULE

Mark any slice IMPLEMENTED — QA DEFERRED only when the actual code, persistence, UI and essential checks are complete.

Do not mark quotation issuance complete without correct authorized pricing, approvals, taxes where required, and immutable official PDF behavior.

Do not mark Sales Order conversion complete without actual Sales Order persistence.

Keep unfinished integrations explicitly BLOCKED.

Do not call the full module QA ACCEPTED or PRODUCTION READY.

---

# 21. REPORTING

For each slice, give a concise report:

1. Completed features.
2. Screens implemented.
3. Shared components reused.
4. Important schema changes.
5. Essential checks passed/failed.
6. Actual blockers.
7. Next executable slice.

Avoid long explanatory reports.

Do not stop after scaffolding.

---

# FINAL OWNER INSTRUCTION

**IMPLEMENT M04 SALES / QUOTATIONS NOW.**

Follow ALL approved owner decisions Q-01 through Q-29.

Use the attached approved Quotation UI references.

Reuse M01 Dynamic RBAC, M02 Product Catalog, M03 Customers, shared DataTables, File Upload, Excel Import/Export and Audit infrastructure.

Preserve immutable issued revisions, approval integrity, accepted evidence, annual numbering, correct discounts and safe partial conversion.

Do not create fake Accounting, Inventory, Pricing or Sales Order capabilities.

Work through Contract-First Vertical Slices.

Follow Fast Development Mode.

Defer full QA and Playwright.

**DELIVER WORKING CODE. REUSE FIRST. MINIMAL TALK.**


## Actual dependency resolution

| Previous rule | Owner replacement | Scope |
| --- | --- | --- |
| All overrides require independent FinanceManager | Q19–23: explicit RBAC authority, single all-exceptions decision; self approval only configured by Organization | Quotation exceptions only |
| Revisions after sending | Q05: immutable at issue | Quotations only |
| Currency or tax examples in screenshots | Q10/Q14: EGP, Accounting tax contract, no guessed rates | Quotations only |
| Automatic commercial defaults | Q03/Q04/Q12: approved configuration only | No invented validity, terms, rounding or thresholds |

ProductsApi supplies identity, catalog search, units and exact conversion. CustomersApi supplies active identities. Pricing has no runtime producer or public price contract. Accounting has no tax/rounding producer. Organization now supplies explicit quotation approval authorization and audited role/user self-approval configuration (disabled by default). Official company/signature snapshot contracts remain unavailable. Sales Orders and Projects have no runtime producer. These block dependent official actions, not independent draft saving. No official issued artifact, commercial threshold, automatic approval authority grant or successful order is synthesized.
