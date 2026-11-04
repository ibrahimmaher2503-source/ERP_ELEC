# VPS deployment package — uninstalled, target acceptance blocked

These are local templates, not evidence of a deployed target. No remote access, production installation, activation, actual domain, secrets, TLS certificate, server capacity or recovery approval is supplied. Prepare a named VPS/staging target and an operator-owned change record before using this package. Production deployment still requires explicit owner authorization.

## Target prerequisites

Use the locked PHP-compatible runtime (currently PHP8.4 supported by Laravel13), PostgreSQL17, required Composer extensions, Nginx, systemd, a restricted `electrical-erp` Unix account, HTTPS certificate and a dedicated app DB role without superuser/create-database privileges. Bind PostgreSQL to loopback or a private network and limit pg_hba to exact roles/addresses with SCRAM. The application may not run as postgres/root. Pin package sources and apply supported security updates.

Keep release directories under `/srv/electrical-erp/releases/<manifest-sha256>`, a reviewed `current` symlink, and private `.env`/storage under `/srv/electrical-erp/shared`. Runtime files are writable only by the application account; source/vendor and the web root remain read-only. Never publish private storage through a web symlink. Keep `APP_KEY`, database credentials, mail/backup keys and `PGPASSFILE` outside the artifact and logs. Restore requires the original APP_KEY for encrypted data.

Fill all `@...@` template values from the selected target. Measure PHP worker RSS and reserve OS/PostgreSQL memory before choosing max_children; the template does not certify capacity. Run `nginx -t`, PHP-FPM config validation and `systemd-analyze verify` on the rendered files before installation. Use a valid known-host TLS name, enable secure cookies and `APP_DEBUG=false`, production logging and the real approved `APP_URL`. Confirm health endpoints do not reveal configuration or credentials.

## Environment

Create the private `.env` from `.env.production.example`, not `.env.example`. A deploy must stop when `APP_ENV` is not `production` or `APP_DEBUG` is not `false`. Never run `composer setup` or `db:seed` on a server. Exclude `.env*`, `.local`, `.tmp` and `.tools` from every release artifact. After the release is linked, run `php artisan vendor:publish --tag=livewire:assets --force`, and confirm the Livewire script loads over HTTPS.

## Controlled release sequence

1. Obtain the pinned source/dependency/asset manifest and original audit/acceptance receipts. Refuse unexplained source drift. Upload the reviewed artifact without environment, DB copies, private runtime or credentials.
2. Verify exact target host, database/current role/server address/port/live `data_directory` against the operator's ownership receipt. Refuse a mismatch. Verify APP_ENV and that all actual company configuration/openings and UAT gates are approved before any production activation.
3. Take the coordinated encrypted database/private-files backup below; record its actual offsite receipt and restore drill. Stop scheduled writers, enter Laravel maintenance mode, gracefully stop queue workers and confirm active app transactions have drained. Restrict every other writer, including imports/CLI.
4. Prepare the new immutable release with locked Composer production install and prebuilt assets. Link the existing private environment and storage. Run `composer check-platform-reqs`, reviewed forward migrations, Administrator permission synchronization via the migration event, `config:cache`, `route:cache` and `view:cache` in the new release. Never run demo seeders in production.
5. Switch `current` atomically, reload PHP-FPM, restart the supervised queue and scheduler, then leave maintenance. Verify `/up`, real login and role/scope denials, a semantic authorized read, queues including `data-operations`, scheduled execution, private documents and owner reconciliation with no invented transactions.
6. Keep the previous release and backup. An application symlink rollback is allowed only when the forward schema is backward compatible. Never run migration rollback against posted history. Otherwise restore to a separately owned recovery target and follow the approved coordinated restore/cutover procedure.

## Queues and scheduler

Install the supplied queue service and scheduler timer after target validation. ProcessOperation has120s timeout; the queue uses120s and `TimeoutStopSec=150`, so database/Redis visibility retry must be180s or longer. Config defaults were corrected from90 to180; target environment overrides must be checked. Use three tries; inspect failed jobs and processing status rather than retrying blindly. Test a synthetic `data-operations` import/export on staging, worker termination/restart, duplicate delivery and recovery. Business stock/GL/subledger posting remains synchronous within owner transactions.

Do not run both cron and the supplied timer. Validate exact configured scheduled tasks with `schedule:list`; a running timer without task evidence is not scheduler acceptance.

## Coordinated encrypted backup and restore

`backup.sh` and `restore.sh` require explicit operator configuration and target ownership proof; they are not scheduled or executed here. Approve RPO, RTO, frequency, offsite destination and retention before scheduling. An example policy discussion may consider daily/full plus WAL/PITR for tighter RPO, but neither is an approved company target. Retention deletion must be configured and tested at the selected offsite provider; local scripts do not delete backups automatically.

Preserve database, private business documents/media, the original APP_KEY/secret escrow, exact release manifest, schema version and rendered server configuration as one recovery set. Database-only restoration cannot recover private files or encrypted values. Encrypt before leaving the server; store the recovery private key separately from the VPS. Confirm decrypt/integrity, not only successful upload.

The scripts require `age`, OpenSSL and an externally configured rclone remote. Backup sets carry an Ed25519 signature over SHA256SUMS; keep the private signing key in a restricted secret store and pin its public-key fingerprint separately on recovery. The encrypted environment copy is escrow only: never restore it unchanged over a recovery environment, because its DB endpoints may name production. A restricted read-only backup role needs access to source tables and the `data_directory` setting; the recovery writer role must belong only to the separately provisioned empty recovery database. These roles/keys/remote and their actual access receipts are external prerequisites, not created here.

Rehearse restore to an empty, explicitly marked isolated target: exact DB identity plus live data_directory, correct extensions/schema, file hashes and original encryption key; then login/scopes/doc downloads, pending queue recovery, stock/FIFO/GL/AR/AP/advance/GRNI reconciliation and source history. Measure actual timestamps and data cutoff to calculate achieved RPO/RTO. Keep target-dependent status BLOCKED until real receipts and owner acceptance exist.

## Monitoring and escalation

Use target-native metrics/alerts for external HTTPS readiness and certificate expiry, PHP-FPM saturation/5xx/latency, disk/inodes, CPU/RAM, PostgreSQL connection/lock/wait/replication/WAL state, queue oldest-job age/depth/failed jobs, scheduler last successful run, application exception rate, backup age/upload/decrypt verification and offsite retention. Keep SQL bind values, credentials and private documents out of monitoring. Choose alert recipients/escalation windows with the owner; no delivery is claimed here.

On incident: freeze affected writes, preserve correlated logs/audit/source references, compare financial/stock controls, assess data/queue/privacy impact, and assign a named incident owner. Reconcile/replay only through owning Actions. No balancing plugs, posted-row edits, automatic demo seed, destructive database reset or unreviewed production restore. Record the actual fix, operator decision, recovery point and verification evidence.
