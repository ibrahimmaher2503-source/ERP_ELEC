# Infrastructure/recovery readiness — target blocked

PR-INF-001, PR-DR-001 and PR-OPS-001 remain **BLOCKED BY EXTERNAL DEPENDENCY**. The owner confirmed there is no Staging environment. No VPS was accessed, installed, restarted or deployed; no production activation is authorized.

Local package `ops/vps/`: Nginx/PHP-FPM templates, dedicated queue and scheduler systemd units/timer, target ownership guard, coordinated encrypted offsite backup, isolated restore and operator README. Queue timeout120 now has database/Redis retry_after180. Templates require actual target/domain/runtime/storage/user/secret bindings; placeholders are intentional.

Backup requires a marked target, exact live PostgreSQL identity and safe credential-file mode; maintenance/queue/scheduler coordination; DB/private-files/config escrow encryption; fixed-file SHA256 manifest signed with Ed25519; offsite transfer verification. Restore pins the verification-key fingerprint, verifies signature and exact checksums before decrypting, rejects unsafe archive entries, and refuses nonempty/unmarked recovery destinations. It does not reset databases or copy production database endpoints into recovery.

Luna-authored `php -d opcache.enable_cli=0 tests/Support/check-vps-operational-templates.php`:12 static assertions PASS. This checks source safeguards only. It is not a backup, offsite durability, decryption, restore, actual alert delivery, RPO/RTO or target supervision acceptance.

PR-CAP-001 remains **BLOCKED BY EXTERNAL DEPENDENCY** for a real target. `tests/Capacity/` provides5/20/50/100-actor cohorts with exact allowlists, ownership/stack preflight and semantic responses. It rejects loopback/built-in-server targets. Host metrics are explicitly NOT TESTED; no target workload has run. Historical bounded localhost throughput is not staging capacity.

Required external inputs: selected isolated target/access, actual domain/TLS/secret bindings, resource inventory, approved RPO/RTO/retention, operator-owned encryption/signing keys and alert destinations, and authorization for target rehearsal. Production remains a separate explicit authorization boundary.
