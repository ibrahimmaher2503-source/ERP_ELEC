# Capacity harness

This prepares a configured target run; it does not create test data, approve routes, provision staging, or establish business integrity. The repository currently has no staging target, so capacity acceptance remains **BLOCKED**. Existing `bounded-get-load.json` is a historical local PHP built-in-server read-only experiment and does not qualify.

Copy `target-fixture.example.json` outside Git, fill it only from an approved target and fixture plan, and place synthetic request files below a sibling `fixtures/` directory. The manifest requires an exact HTTPS origin, a live non-built-in web-stack assertion, exact method/path allowlist, 100 distinct synthetic actor identities, explicit operation methods, non-empty semantic JSON assertions, and a live preflight that matches the exact DB name, user, port, data directory and ownership-marker SHA-256. Cookies and optional CSRF values are read only from named environment variables. The live preflight URL and all business endpoints are deliberately left unset because no target or accepted endpoint contract exists.

Run the harness self-check without network or database access:

```powershell
node tests/Capacity/run-capacity.cjs --self-check
```

Once staging and its operations have been approved and configured, invoke each cohort separately so each immutable private raw receipt is reviewable:

```powershell
node tests/Capacity/run-capacity.cjs .tmp/capacity/target-fixture.json 5
node tests/Capacity/run-capacity.cjs .tmp/capacity/target-fixture.json 20
node tests/Capacity/run-capacity.cjs .tmp/capacity/target-fixture.json 50
node tests/Capacity/run-capacity.cjs .tmp/capacity/target-fixture.json 100
```

The harness refuses loopback and PHP built-in-server targets. A cohort PASS means each configured request returned its exact expected status and JSON semantic assertions; it does not certify financial/stock integrity. Keep raw receipts private because failed response bodies are retained. CPU, memory, database waits and queue lag are emitted as `NOT TESTED`; no sampler is configured. Confirm every submitted business transaction, resulting ledgers, cleanup, and host/database/queue telemetry through target-owned evidence before capacity acceptance.
