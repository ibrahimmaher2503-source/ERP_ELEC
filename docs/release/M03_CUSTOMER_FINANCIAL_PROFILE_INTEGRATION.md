# M03 Customer and Supplier Financial Profiles

Date: 2026-10-31. Local integration; no production activation or remote publishing.

## Implementation

Both Customer Details → الملف المالي and Supplier Details → المشتريات والحسابات now consume authorized read projections. The former integration placeholders are replaced by financial summary cards, invoice and cash-movement tables, aging, a date-filtered statement, and explicit unconfigured policy states. The existing Arabic RTL, pale-blue/white components and tabs remain in use.

The nested `Parties\Presentation\PartyFinancialSection` is instantiated only when the financial tab is selected. Other tabs do not fetch financial histories. Invoice and movement pages contain 10 rows; statement pages contain 20 rows. Aggregates operate in PostgreSQL rather than loading all historical records into PHP. There are no per-invoice database lookups. A standalone projection runs in a repeatable-read transaction so summaries, details and statement use a consistent database snapshot.

## Existing capabilities reused

- **M03:** existing customer/supplier identity contracts, basic view and export permissions, current Party Details component and design tokens.
- **M12:** posted invoice headers, `rp_open_items` generated remaining amounts, immutable settlement allocations, posted credit notes, excess party credits and customer advances. `ReceivablesPayablesApi::partyFinancialProfile` is a read contract implemented by the M12 owner.
- **M13:** executed customer collections/supplier payments through `TreasuryApi::partyMovements`. M12 enriches the returned movement page with its own allocation amounts. M03 never queries Treasury or M12 tables directly.
- **M11:** existing source-posted financial entries remain unchanged. No competing GL, subledger, balance table, posting or settlement implementation was added. M12 remains the balance authority.
- **System:** existing `WorkbookCodec` produces a private/no-store XLSX of the complete filtered statement and full-period opening/closing balances. The existing 10,000-row workbook bound allows 9,998 financial events plus the two balance rows; larger exports request a shorter period instead of silently truncating. Browser printing is explicitly labelled as printing the current page.

Invoice links open the original M12 invoice when its separate invoice-view permission is available. Cash movement links open a read-only, branch-authorized M13 movement page. Neither financial state nor payments can be edited from the M03 section.

## Balance definitions and reconciliation

- Invoice total includes headers with `posted_at`; unposted drafts/validated/issued records do not contribute to posted balances. Unposted invoice rows additionally require the owner's invoice-view permission in the branch.
- Remaining gross debt is the sum of authoritative M12 `remaining_amount = original_amount - settled_amount - credited_amount`.
- Posted credit-note totals are shown independently. The applied portion is already reflected in remaining debt; it is not subtracted a second time.
- Excess unallocated credit is read from `rp_party_credits.remaining_amount`. Net debt is remaining gross debt minus that excess credit, matching M12 reconciliation semantics.
- Allocated receipts/payments come from M12 settlement amounts. Actual Treasury cash movements are displayed separately, with allocated and unallocated portions. They are not added again to the statement.
- Customer advances are shown separately from AR. The existing owner stores them as immutable original amounts and currently has no advance-allocation lifecycle. Supplier advances have no implemented owner contract; no supplier advance engine or balance was fabricated.
- Aging uses current generated remaining balances and due dates as of the Cairo calendar date. Due today is current; overdue buckets are 1–30, 31–60, 61–90 and over 90 days.
- The statement is a **M12 recognition-event statement**, explicitly labelled in the UI. It uses the Cairo date of immutable M12 recognition timestamps: open-item creation, posted credit note and settlement allocation. It is not represented as a backdated GL statement by invoice/Treasury document date. Opening and closing balances include the full filtered event range; the on-screen table is paginated while the export includes the complete bounded period. Events are ordered by exact recognition timestamp with deterministic tie-breakers. Full-range closing reconciles to net debt.
- The older cutover contributor `payable_opening_items` does not automatically create settlement-enabled `rp_open_items`. Any scoped supplier opening registration without such a handoff is shown as a separate warning/amount, explicitly outside the operational statement. It is not silently omitted or merged into a competing payable balance. Completing that historical cutover handoff remains work in the owning M12/M11 lifecycle.

## Security

Every projection rechecks basic M03 identity access and current `M12.ar_view` or `M12.ap_view` branch authority. No available financial branches returns an unauthorized state without amounts. An explicitly requested foreign branch is rejected. IDs are customer/supplier scoped on every query; branch filters intersect actual permissions. Invoice navigation and draft details require separate invoice-view authority. Treasury rows intersect the M12 branch selection with M13 collection/payment-screen authority. Revocation applies on subsequent component requests. Nested party identity/type and applied statement dates are locked Livewire properties.

Exports require both current financial access and the existing M03 export permission, and return private/no-store responses. The read-only movement route independently reauthorizes its exact branch. No role, scope or privilege is created by runtime profile code.

## Policy gaps

Payment terms and discounts exist on Sales and Purchasing documents; these are not approved default terms for a party. Party-wide payment days, discount policy, credit limits/status/utilization/available credit and party collateral have no authoritative implemented contract. They display غير مُعد / غير مُفعّل. Project/tender guarantees are not misrepresented as customer collateral. No credit-management engine, new financial schema or migration was added.

## Files

- `app/Modules/ReceivablesPayables/Application/{PartyFinancialProfile,ReceivablesPayablesService,Contracts/ReceivablesPayablesApi}.php`
- `app/Modules/Treasury/Application/{PartyMovements,TreasuryService,Contracts/TreasuryApi}.php`
- `app/Modules/Parties/Presentation/PartyFinancialSection.php`
- `app/Modules/Treasury/Presentation/MovementDetailsPage.php`
- `resources/views/parties/{details,financial-section}.blade.php`
- `resources/views/parties/partials/financial-pagination.blade.php`
- `resources/views/treasury/movement-details.blade.php`
- `routes/web.php`: read-only movement detail route only for this integration; pre-existing unrelated route edits retained.

## Acceptance

New test/check and browser code is Luna-authored and lead-reviewed. Separate owned PostgreSQL port 55460 is used; development port 5443 is not an acceptance target. Real M12/M13 owning actions produce the posted fixture history; no fabricated posting rows or production data are used.

Focused real-action results are in `docs/qa/evidence/phase1-financial-journeys.json` (`all_pass: true`). In this fixture, supplier allocated settlements are **50.16 EGP**, remaining operational AP **12.54 EGP**, unapplied credit **2.00 EGP**, and net AP / statement closing / M11 control **10.54 EGP**. Customer allocations are **54.72 EGP**, remaining AR **0.00 EGP**, unapplied credit **2.00 EGP**, and net AR / statement closing / M11 control **-2.00 EGP**. An actual unallocated customer collection produces a separate **1.00 EGP** advance, matching its GL control. All corresponding reconciliation differences are **0.00**. Aging is exercised with a forward test clock; browser screenshots use the actual current clock and therefore need not show the same overdue bucket.

Authenticated browser evidence: `docs/qa/evidence/m03-financial-profile/run-5968765D9A/result.json` passes customer and supplier views at **1440×900** and **390×844**, RTL, no document overflow, first-page pagination controls and period filtering, with **zero browser errors**. Screenshots in the same folder: `customer-1440.png`, `customer-390.png`, `supplier-1440.png`, `supplier-390.png`. Supplemental export/revocation checks are recorded after their execution below.

Final expanded browser acceptance: [run-10116CFF0F/result.json](../qa/evidence/m03-financial-profile/run-10116CFF0F/result.json), **10 checks passed, zero browser errors**. It verifies both party types at both viewports, invalid-period rejection with balance preserved, valid-period application, the exact authorized Treasury movement returning HTTP 200, a real XLSX download, a valid but empty authorized AR branch returning zero history while withholding Treasury data, a basic-M03-only actor seeing no amounts, Treasury HTTP 403 without M13 authority, and current Livewire authorization after removal of the M12 branch grant. The direct export check inspects the complete worksheet, reconciles event arithmetic to the net balance, and asserts `private, no-store` on the response.

Final screenshots: [customer desktop](../qa/evidence/m03-financial-profile/run-10116CFF0F/customer-1440.png), [customer mobile](../qa/evidence/m03-financial-profile/run-10116CFF0F/customer-390.png), [supplier desktop](../qa/evidence/m03-financial-profile/run-10116CFF0F/supplier-1440.png), [supplier mobile](../qa/evidence/m03-financial-profile/run-10116CFF0F/supplier-390.png). Only initial pagination controls have browser coverage; a multi-page history navigation stress run was not performed. The query itself is database-paginated and the complete filtered export is independently checked.

`npm run build` and scoped Pint passed. Existing font URL resolution warnings remain unchanged. No full regression suite has been claimed for this scoped change.

Acceptance found and corrected two defects in the new integration: a Treasury detail view variable collided with a public Livewire integer property (renamed to `movementId`), and Symfony's binary response constructor overrode an initial private cache header (the statement download now explicitly calls `setPrivate()` after construction). Failed-run evidence is retained alongside successful runs.
