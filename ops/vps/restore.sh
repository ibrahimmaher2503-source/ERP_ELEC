#!/usr/bin/env bash
set -euo pipefail
umask 077
source "$(dirname "$(readlink -f "$0")")/target-guard.sh"
: "${ERP_RECOVERY_ROOT:?marked empty recovery root required}"
: "${ERP_BACKUP_SET:?downloaded encrypted backup set required}"
: "${ERP_RECOVERY_KEY:?separate private age recovery key required}"
: "${ERP_BACKUP_VERIFY_KEY:?trusted external Ed25519 manifest public key required}"
: "${ERP_BACKUP_VERIFY_KEY_SHA256:?operator-pinned public key fingerprint required}"
[[ "${ERP_TARGET_PURPOSE:-}" == isolated-recovery && "$ERP_EXPECTED_DB" == *_recovery ]] || { echo 'Restore is limited to an isolated recovery database' >&2; exit 1; }
[[ -d "$ERP_RECOVERY_ROOT" && ! -L "$ERP_RECOVERY_ROOT" ]] || exit 1
[[ "$(readlink -f "$ERP_RECOVERY_ROOT")" == /srv/electrical-erp/recovery/* ]] || exit 1
[[ -f "$ERP_RECOVERY_ROOT/.erp-recovery-owned" && ! -L "$ERP_RECOVERY_ROOT/.erp-recovery-owned" ]] || exit 1
[[ "$(cat "$ERP_RECOVERY_ROOT/.erp-recovery-owned")" == "$ERP_TARGET_NONCE" ]] || { echo 'Recovery filesystem ownership mismatch' >&2; exit 1; }
[[ -f "$ERP_RECOVERY_KEY" && ! -L "$ERP_RECOVERY_KEY" && "$(stat -c %a "$ERP_RECOVERY_KEY")" == 600 ]] || exit 1
[[ -f "$ERP_BACKUP_VERIFY_KEY" && ! -L "$ERP_BACKUP_VERIFY_KEY" && "$(sha256sum "$ERP_BACKUP_VERIFY_KEY" | cut -d' ' -f1)" == "$ERP_BACKUP_VERIFY_KEY_SHA256" ]] || exit 1
[[ -d "$ERP_BACKUP_SET" && ! -L "$ERP_BACKUP_SET" ]] || exit 1
tables="$(psql -XAt -v ON_ERROR_STOP=1 -c "SELECT count(*) FROM pg_tables WHERE schemaname='public'")"
[[ "$tables" == 0 ]] || { echo 'Recovery database must be empty; no reset is performed' >&2; exit 1; }
[[ ! -e "$ERP_RECOVERY_ROOT/private" ]] || { echo 'Recovery private-files target already exists' >&2; exit 1; }
cd "$ERP_BACKUP_SET"
[[ -f SHA256SUMS && ! -L SHA256SUMS && -f SHA256SUMS.sig && ! -L SHA256SUMS.sig ]] || exit 1
openssl pkeyutl -verify -rawin -pubin -inkey "$ERP_BACKUP_VERIFY_KEY" -in SHA256SUMS -sigfile SHA256SUMS.sig >/dev/null
# Fixed filenames prevent a supplied checksum list from selecting arbitrary filesystem paths.
for name in database.dump.age private-files.tar.gz.age environment.env.age migrations.txt release-path.txt; do
    [[ -f "$name" && ! -L "$name" ]] || exit 1
    expected_hash="$(awk -v name="$name" '$2==name {print $1}' SHA256SUMS)"
    [[ "$expected_hash" =~ ^[0-9a-f]{64}$ && "$(sha256sum "$name" | cut -d' ' -f1)" == "$expected_hash" ]] || exit 1
done
scratch="$(mktemp -d "$ERP_RECOVERY_ROOT/.decrypt-XXXXXXXX")"
cleanup() { rm -f -- "$scratch/database.dump" "$scratch/private-files.tar.gz"; rmdir -- "$scratch" 2>/dev/null || true; }
trap cleanup EXIT
# Authenticate each whole encrypted stream before any database/file restore mutation.
age --decrypt --identity "$ERP_RECOVERY_KEY" database.dump.age > "$scratch/database.dump"
age --decrypt --identity "$ERP_RECOVERY_KEY" private-files.tar.gz.age > "$scratch/private-files.tar.gz"
tar -tzf "$scratch/private-files.tar.gz" | awk '/^\// || /(^|\/)\.\.(\/|$)/ {bad=1} END {exit bad}'
tar -tvzf "$scratch/private-files.tar.gz" | awk 'substr($0,1,1)!~/^[-d]$/ {bad=1} END {exit bad}'
pg_restore --exit-on-error --single-transaction --no-owner --no-acl --dbname="$ERP_EXPECTED_DB" "$scratch/database.dump"
mkdir -m 700 "$ERP_RECOVERY_ROOT/private"
tar --extract --gzip --no-same-owner --no-same-permissions --file "$scratch/private-files.tar.gz" --directory "$ERP_RECOVERY_ROOT/private"
printf 'Isolated database/private files restored. Configure original APP_KEY from secret escrow without copying production DB endpoints; run full recovery acceptance before cutover.\n'
