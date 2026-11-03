#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${ERP_TARGET_MARKER:?operator-owned marker path required}"
: "${ERP_TARGET_NONCE:?operator-owned marker nonce required}"
: "${ERP_EXPECTED_DB:?exact database required}"
: "${ERP_EXPECTED_USER:?exact operator database role required}"
: "${ERP_EXPECTED_ADDRESS:?observed PostgreSQL server address required}"
: "${ERP_EXPECTED_PORT:?observed PostgreSQL port required}"
: "${ERP_EXPECTED_PGDATA:?live absolute PostgreSQL data_directory required}"
: "${PGPASSFILE:?private PostgreSQL credential file required}"
[[ -f "$ERP_TARGET_MARKER" && ! -L "$ERP_TARGET_MARKER" ]] || { echo 'Missing target marker' >&2; exit 1; }
[[ "$(cat "$ERP_TARGET_MARKER")" == "$ERP_TARGET_NONCE" ]] || { echo 'Target marker mismatch' >&2; exit 1; }
[[ -f "$PGPASSFILE" && ! -L "$PGPASSFILE" && "$(stat -c %a "$PGPASSFILE")" == 600 ]] || { echo 'PGPASSFILE must be a private regular file, mode 600' >&2; exit 1; }
expected="${ERP_EXPECTED_DB}|${ERP_EXPECTED_USER}|${ERP_EXPECTED_ADDRESS}|${ERP_EXPECTED_PORT}|${ERP_EXPECTED_PGDATA}"
actual="$(psql -XAt -v ON_ERROR_STOP=1 -c "SELECT current_database()||'|'||current_user||'|'||host(inet_server_addr())||'|'||inet_server_port()||'|'||current_setting('data_directory')")"
[[ "$actual" == "$expected" ]] || { echo 'Live database identity differs from target receipt' >&2; exit 1; }
