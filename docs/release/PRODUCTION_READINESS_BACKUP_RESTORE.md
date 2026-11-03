# Backup, restore and disaster recovery audit

Audit:2026-11-01. **Local synthetic restore: VERIFIED PASS for the exact tested scope. Production disaster recovery: BLOCKED.** No operational database was dumped for acceptance, reset, restored over, migrated or activated. Backup data remains under ignored private `.tmp`, not in a commit/report attachment.

## Actual current recovery performed (G10)

Luna adapted the existing `tests/Support/check-phase0-recovery-seed.php` and `check-phase0-recovery.php` for explicitly marked private PostgreSQL directories/ports and current synthetic actor/source IDs. Original helpers were not changed. The source fixture was created through real business Actions, not manually inserted fake balances.

| Step | Status | Exact evidence / scope |
|---|---|---|
| Create actual synthetic business data | VERIFIED PASS | `evidence/production-readiness-20261101/phase0-recovery-seed.json`:14/14 reported steps PASS, all_pass=true, explicitly committed only in private `erp_elec_new_test@127.0.0.1:49683`. Includes real source approvals/official documents/receipts/stock/FIFO/journals. The financial negative risk fixtures remain separately rolled back. |
| Record source manifest | VERIFIED PASS | `phase0-recovery-source.json`:92 applied migrations (chain ends198); selected users6/branches2/products3; one issued PO, one quotation, one issued Sales Order; four confirmed deliveries,12 posted journals, six FIFO layers. |
| Private-file source data | VERIFIED PASS | Two selected private files: company logo and a clearly synthetic private source fixture; SHA-256 manifest recorded. These are not all possible real-company attachment types. |
| Official document source integrity | VERIFIED PASS |19 official PDFs (journals, PO, quotation, Sales Order and delivery documents), stored identities/bytes/SHA-256 recorded from actual issued sources. |
| Create PostgreSQL backup | VERIFIED PASS | `pg_dump` of the synthetic source into private audit storage; complete logical database dump, not hand-picked ledger inserts. |
| Separate restore target | VERIFIED PASS | `restore-cluster.json`: new marked `.tmp/production-readiness-20261101/restore-pgdata`, loopback49684, PostgreSQL 17.11, exact test DB name. Live data_directory/port/name verified before operations. |
| Restore database | VERIFIED PASS after fixture correction | `pg-restore-utf8-summary.json`: pg_restore exit0, UTF8 target. Initial WIN1252 target inherited Windows encoding and rejected Arabic fixture rows; that failed disposable DB alone was recreated as UTF8/template0/localeC. The initial failure was retained and is an environment/harness error, not a concealed application pass. |
| Restore private files | VERIFIED PASS for selected files | Files copied only between private fixture roots and both SHA-256 hashes match the source manifest. |
| Verify restored source relationships/counts | VERIFIED PASS for selected controls | `phase0-recovery-restore-verify.txt`: selected count/four relationship joins match source; migration count does not regress. This is not a full row-by-row fingerprint or semantic audit of every table/source reference. |
| Retrieve restored official PDFs | VERIFIED PASS | All 19 PDFs retrieved through actual `OfficialDocuments` logic; document identity, `%PDF-` signature, bytes and all recorded/stored/retrieved SHA-256 values match. |
| Restored authorized application access | VERIFIED PASS (controller slice) | Actual restored `CompanyLogoController` and `OrderPdfController` invocations return200 for authorized synthetic actor and403 for outsider. These are authenticated controller-level invocations, not a full restored HTTP/browser/TLS test. `http_status` in the raw receipt names those controller responses. |
| Post-restore financial operations / whole-company reconciliation | NOT TESTED in full | This drill verifies restored current fixtures and source documents; it does not supply a full new post-restore posting/correction/reconciliation campaign for every owner or fix the three current financial defects. |

Authoritative final receipt: **`docs/release/evidence/production-readiness-20261101/phase0-recovery-restore-verify.txt`**, terminal **all_verified=true**; command receipt `phase0-recovery-restore-verify-summary.json`, exit0. Failures/intermediate manifests remain alongside final evidence. Neither a source report nor dump creation alone was used to declare restore success.

## What this drill does not establish

| Production recovery requirement | Status | Missing evidence / closure |
|---|---|---|
| Approved RPO | BLOCKED | No company maximum tolerable data-loss interval supplied. Define it and test backup/WAL frequency against it. |
| Approved RTO / measured full recovery | BLOCKED | No approved outage objective, timed complete target provisioning→restore→application/finance acceptance drill. Local command success is not an RTO measurement. |
| Backup encryption / key custody | NOT TESTED | Disposable synthetic dump is private local evidence; production encryption, access roles, rotation and recoverable APP_KEY/certificate/config custody not verified. |
| Offsite copies / retention / immutability | NOT TESTED | No target storage policy, offsite copy receipt, retention rotation or deletion/tamper protection accepted. |
| Backup failure alerting | NOT TESTED | No demonstrated failing backup notification to the named operator/escalation channel. |
| Full private-file consistency | NOT TESTED | Two selected files and19 issued PDFs verified; all-owner attachments, upload-in-flight consistency, file inventory and real storage service recovery remain unaccepted. |
| Sensitive encrypted fields | NOT TESTED | Fixture PDFs are not proof of decryption recovery for real MFA/secrets/encrypted business data. Production key/config restoration must be tested privately without copying secrets into reports. |
| Point-in-time recovery | NOT TESTED | Logical pg_dump/pg_restore is not WAL-based PITR. If required by the approved RPO, rehearse base backup plus continuous WAL chain. |
| Previous approved production schema upgrade | NOT TESTED | Latest-schema restore at the selected migration chain is not a populated previous-production-version→current upgrade. No approved previous release/target schema was identified; do not claim this from the restored92 migration count. |
| Failed deployment recovery | BLOCKED | No target-like application/web/supervisor environment; actual failed-release/application rollback plus compatible DB/file recovery not rehearsed. |
| Named operator/runbook acceptance | NOT TESTED | Existing `PHASE0_RECOVERY_RUNBOOK.md` is a reference. Update/execute the chosen target process, access and recovery objectives with actual operators and finance acceptance. |

PostgreSQL documents [logical restore and other backup methods](https://www.postgresql.org/docs/17/backup.html), and separately [continuous archiving/PITR](https://www.postgresql.org/docs/17/continuous-archiving.html). A successful logical restore is useful evidence; it does not imply PITR or a coordinated recovery of files, configuration and encryption material.

## Release consequence

PR-DR-001 remains a **P1 Go-Live blocker** until a complete production-like restore meets the company's approved RPO/RTO and security/retention/offsite/alert requirements. The isolated local restore closes only the bounded synthetic test above. It does not change the NO-GO decision arising from current financial defects and absent mandatory modules.

No automatic database rollback is recommended: immutable financial migrations intentionally refuse some `down()` operations. Rehearse compatible application rollback, forward repair and coherent backup restoration before relying on them during a real incident. Recovery must preserve source/document/ledger relationships rather than editing posted data to match a screen.
