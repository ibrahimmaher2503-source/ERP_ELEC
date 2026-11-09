# ERP module performance matrix

Before: mvwmlhzx-nba600; after: after-20261031200934.
Browser timing is uninstrumented. SQL counts are from separate native Debugbar page requests. Module P95 pools samples within the module; page P95 is withheld at n=3.

| Module | Route cases | Before warm median / P95 ms | After warm median / P95 ms | SQL profiles before / after | Improved / unchanged / regressed / gated / repaired / other |
|---|---:|---:|---:|---:|---|
| M11 Accounting | 13 | 740.6 / 1286.6 | 331.05 / 533.9 | 0 / 13 | 13 / 0 / 0 / 0 / 0 / 0 |
| M08 FieldMaintenance | 12 | 1197.7 / 2943.2 | 272.2 / 524.1 | 5 / 12 | 12 / 0 / 0 / 0 / 0 / 0 |
| M06 Inventory | 25 | 785.3 / 1526.7 | 252.75 / 349.5 | 4 / 25 | 25 / 0 / 0 / 0 / 0 / 0 |
| M01 Organization | 13 | 774.85 / 3211.3 | 256.4 / 463.3 | 3 / 13 | 13 / 0 / 0 / 0 / 0 / 0 |
| M03 Parties | 13 | 719.65 / 1510.2 | 316.3 / 500.1 | 1 / 13 | 13 / 0 / 0 / 0 / 0 / 0 |
| M02 Products | 9 | 740.25 / 1275.4 | 250.75 / 641.3 | 0 / 9 | 9 / 0 / 0 / 0 / 0 / 0 |
| M07 Projects | 11 | 834.35 / 1699.4 | 259.75 / 384.8 | 3 / 11 | 10 / 0 / 0 / 0 / 1 / 0 |
| M05 Purchasing | 19 | 626.7 / 1206.2 | 249.95 / 377 | 1 / 17 | 17 / 0 / 0 / 2 / 0 / 0 |
| M12 ReceivablesPayables | 8 | 650.85 / 1178.1 | 236.6 / 323.1 | 0 / 8 | 8 / 0 / 0 / 0 / 0 / 0 |
| M04 Sales | 19 | 749.15 / 1938.1 | 286.4 / 404.7 | 3 / 18 | 18 / 0 / 0 / 1 / 0 / 0 |
| M17 System | 5 | 580 / 766.8 | 244.5 / 420.9 | 1 / 5 | 4 / 0 / 0 / 0 / 0 / 1 |
| M13 Treasury | 5 | 642.75 / 964 | 257.2 / 452.7 | 0 / 5 | 5 / 0 / 0 / 0 / 0 / 0 |
| M09 WorkshopRepairs | 8 | 655.25 / 922.9 | 282.75 / 534.5 | 0 / 8 | 8 / 0 / 0 / 0 / 0 / 0 |
| M07 Projects · Tenders capability | 6 | 710.7 / 1159.6 | 239.1 / 347.7 | 0 / 6 | 6 / 0 / 0 / 0 / 0 / 0 |

## Review status

This matrix summarizes browser before/after results. It does not modify business logic or gates. Query counts and duration are diagnostic evidence; compare both with page TTFB and visible-content timings.
No eligible module pages existed for M10 ServiceContracts, M14 Payroll, M15 Attendance, or M16 Reporting in the original route inventory; these owners have no invented rows or measurements.
