# M14 / M16 release scope — owner decision

Decision status: **APPROVED OUT OF SCOPE for M14 Payroll and the independent M16 Reporting owner**. On2026-11-01 the owner selected the first proposal in this chat: “تأجيل Payroll M14 وReporting M16 المستقل، مع تنفيذ تقارير الوحدات ولوحة المؤشرات الفعلية”. Mandatory M10/M15, owner reports and a real scoped executive dashboard remain required. This decision changes no flags/grants and certifies no implementation. Financial Gate1 acceptance remains the prerequisite for accepting subsequent financial features.

## Approved first production scope

- Include mandatory M10 ServiceContracts and M15 Attendance after their complete acceptance. Follow the latest explicit request: Organization/M01 supplies employee identity/employment facts for Attendance. Existing user identities alone are not employee records. No wage or salary authority is inferred.
- Exclude M14 Payroll from this release: no salary calculation, statutory deductions, payroll posting, payslips or payroll disbursement. Existing service labor rates are approved costing sources, not a payroll engine. Attendance exports approved time facts only; they do not calculate pay or double-post labor expense.
- Include existing module-owned reports and the requested real permission-scoped executive dashboard. Keep each producer's balances and operational facts authoritative. Do not describe those capabilities as completion of a separate M16 Reporting owner.
- Defer the independent M16 reporting warehouse/read-model/catalog/export module. Existing authorized owner exports remain part of their modules. No hidden Reporting dependency may be exposed as working.

This confines the release to the requested operational ERP, real service contracts, approved time management and authoritative existing financial/operational reporting. It does not relax return/serial/Treasury/security requirements or external deployment/company/UAT gates.

## If M14 or independent M16 must be included

M14 needs an approved policy package: employment/pay component definitions; wage effective dates; overtime/absence/leave payment rules; statutory applicability and validated inputs; rounding; independent approvals; posting mappings; payout/advance recovery and corrections. No wage formula or statutory rate will be invented. M15 facts remain valid inputs but cannot substitute for those policies.

M16 needs an approved report catalog specifying each report's grain, business dates, dimensions, source owners, permitted fields/scopes, refresh guarantees, export size/privacy and reconciliation with owner totals. The dashboard and owner reports must not be summed again into duplicate financial totals.

## Decision boundary

PR-SCOPE-003 is APPROVED OUT OF SCOPE for the two independent conditional owners only. Their absence is not a software completion claim. PR-UI-002 remains open until the included dashboard is actually implemented and accepted. No signed UAT, company opening, external policy approval or production authorization is implied.
