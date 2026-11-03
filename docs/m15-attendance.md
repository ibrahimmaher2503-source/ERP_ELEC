# M15 Attendance

Attendance records factual employee time. Organization owns employee identity, employment dates, active status and branch authority. A user account is not an employee record. Attendance does not calculate salary, statutory deductions or payable overtime, and does not post payroll or duplicate service labor costs.

Authorized operators define scoped shifts and planned schedules. Schedule terms are frozen per assignment. Times persist in UTC; work dates and month attribution follow Africa/Cairo, including overnight shifts and daylight-saving transitions. A shift crossing midnight belongs to its scheduled start date.

Punches are append-only `in`, `out`, `break_start` or `break_end` facts with source request keys. CSV imports are bounded and atomic; invalid input does not create a partial accepted batch. Reusing a request key with changed content conflicts. Missing punches and incomplete intervals remain visible; an assumed punch is never created to complete a day.

Corrections, leave and bounded actual overtime are requested separately and approved by an independent authorized actor. The original schedule/punches and decision history remain intact. Reports distinguish worked time, breaks, late arrival, early departure, absence and incomplete facts; they supply no wage formula.

An elapsed month can be locked only after required pending decisions are resolved. The stored snapshot has a canonical digest. Schedule, punch and adjustment actions refuse changes to that employee's locked start month, including an overnight punch falling in the next calendar month. Approved adjustments are also immutable at the database boundary. Locked exports use the verified factual snapshot, with current employee/branch/export authority.

Factories provide valid synthetic draft/planned records. Locked timesheet creation uses actual authorized facts through `lockFromFacts`; it cannot manufacture locked history. `AttendanceDemoSeeder` is explicitly invoked only locally/in testing and adds idempotent planned shifts/schedules without invented punches, approvals or privileges.

Typed `AttendanceApi::timesheet`, `export` and `workedIntervals` expose authorized time facts. The UI is `/attendance`. See `release/remediation/M15_ATTENDANCE_ACCEPTANCE.md` for exact scoped evidence and remaining monthly browser/operator/concurrency acceptance. M14 Payroll remains independently deferred by the owner.
