#!/usr/bin/env bash
set -euo pipefail
umask 077
source "$(dirname "$(readlink -f "$0")")/target-guard.sh"
: "${ERP_ROOT:?exact /srv/electrical-erp root required}"
: "${ERP_BACKUP_ROOT:?private backup root required}"
: "${ERP_BACKUP_RECIPIENT:?age public encryption recipient required}"
: "${ERP_OFFSITE_REMOTE:?configured rclone destination required}"
: "${ERP_APP_DB_USER:?dedicated application database role required}"
: "${ERP_BACKUP_SIGNING_KEY:?private Ed25519 backup manifest signing key required}"
[[ -f "$ERP_BACKUP_SIGNING_KEY" && ! -L "$ERP_BACKUP_SIGNING_KEY" && "$(stat -c %a "$ERP_BACKUP_SIGNING_KEY")" == 600 ]] || exit 1
[[ "$ERP_APP_DB_USER" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || exit 1
[[ "${ERP_MANUAL_WRITERS_STOPPED:-}" == "$ERP_TARGET_NONCE" ]] || { echo 'Operator must attest all other writers stopped' >&2; exit 1; }
[[ "$(readlink -f "$ERP_ROOT")" == /srv/electrical-erp ]] || exit 1
[[ -d "$ERP_BACKUP_ROOT" && ! -L "$ERP_BACKUP_ROOT" ]] || exit 1
for tool in age rclone pg_dump psql tar sha256sum runuser systemctl openssl; do command -v "$tool" >/dev/null; done
cd "$ERP_ROOT/current"
[[ ! -e storage/framework/down ]] || { echo 'Existing maintenance state is preserved; operator review required' >&2; exit 1; }
queue_was_running=false; scheduler_was_running=false; entered_maintenance=false
systemctl is-active --quiet electrical-erp-queue.service && queue_was_running=true
systemctl is-active --quiet electrical-erp-scheduler.timer && scheduler_was_running=true
resume() {
    if $entered_maintenance; then runuser -u electrical-erp -- /usr/bin/php artisan up >/dev/null 2>&1 || true; entered_maintenance=false; fi
    if $queue_was_running; then systemctl start electrical-erp-queue.service || true; fi
    if $scheduler_was_running; then systemctl start electrical-erp-scheduler.timer || true; fi
}
trap resume EXIT
runuser -u electrical-erp -- /usr/bin/php artisan down --retry=60
entered_maintenance=true
systemctl stop electrical-erp-scheduler.timer electrical-erp-scheduler.service electrical-erp-queue.service
active="$(psql -XAt -v ON_ERROR_STOP=1 -c "SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND usename='$ERP_APP_DB_USER' AND state<>'idle'")"
[[ "$active" == 0 ]] || { echo 'Application transactions have not drained; backup refused' >&2; exit 1; }
private="$ERP_ROOT/shared/storage/app/private"
[[ -d "$private" && ! -L "$private" && -z "$(find "$private" -type l -print -quit)" ]] || { echo 'Private storage contains unsupported symlinks' >&2; exit 1; }
set_dir="$(mktemp -d "$ERP_BACKUP_ROOT/set-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")"
pg_dump --format=custom --no-owner --no-acl | age --recipient "$ERP_BACKUP_RECIPIENT" > "$set_dir/database.dump.age"
tar -C "$private" -czf - . | age --recipient "$ERP_BACKUP_RECIPIENT" > "$set_dir/private-files.tar.gz.age"
age --recipient "$ERP_BACKUP_RECIPIENT" "$ERP_ROOT/shared/.env" > "$set_dir/environment.env.age"
psql -XAt -v ON_ERROR_STOP=1 -c "SELECT migration FROM migrations ORDER BY id" > "$set_dir/migrations.txt"
readlink -f "$ERP_ROOT/current" > "$set_dir/release-path.txt"
(cd "$set_dir" && sha256sum database.dump.age private-files.tar.gz.age environment.env.age migrations.txt release-path.txt > SHA256SUMS)
openssl pkeyutl -sign -rawin -inkey "$ERP_BACKUP_SIGNING_KEY" -in "$set_dir/SHA256SUMS" -out "$set_dir/SHA256SUMS.sig"
resume
trap - EXIT
rclone copy "$set_dir" "$ERP_OFFSITE_REMOTE/$(basename "$set_dir")" --checksum
rclone check "$set_dir" "$ERP_OFFSITE_REMOTE/$(basename "$set_dir")" --one-way
printf 'Encrypted coordinated backup uploaded; decryption/restore acceptance still required: %s\n' "$(basename "$set_dir")"
