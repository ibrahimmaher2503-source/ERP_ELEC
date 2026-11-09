# Phase 3 final regression runbook

Run these checks only against the named isolated PostgreSQL clones. Set connection credentials in the local shell; do not add them to this file or evidence logs.

## Laravel suite

The suite uses `RefreshDatabase`, so run it on a disposable UTF-8 PostgreSQL clone. The accepted run cloned source port `55443` to a temporary PostgreSQL server on `55446`, leaving the source and financial/browser fixtures unchanged. The temporary server used `initdb --encoding=UTF8 --locale=C`; the suite then rebuilt only that clone.

```powershell
$env:DB_PORT = '55446'
$env:DB_URL = ''
php -d opcache.enable_cli=0 artisan test --env=testing
```

Receipt: `phase3-final-laravel-suite.txt` — 41 tests, 106 assertions passed.

## M08 regression checks

```powershell
$env:PHASE3_EDGE_PORT = '55443'
php -d opcache.enable_cli=0 tests/Support/check-phase3-maintenance-edge-cases.php

php -d opcache.enable_cli=0 tests/Support/check-phase3-maintenance-livewire-exceptions.php

$env:PHASE3_FINANCIAL_PORT = '55441'
$env:PHASE3_FINANCIAL_COMMIT_FIXTURES = '0'
php -d opcache.enable_cli=0 tests/Support/check-phase3-maintenance-financial.php

php -d opcache.enable_cli=0 tests/Support/check-phase3-maintenance-accounting-contract.php
```

All four checks guard their expected database/loopback port and roll back their fixture transaction. The financial check requires the pre-existing activated isolated accounting fixture on `55441`; leave `PHASE3_FINANCIAL_COMMIT_FIXTURES` set to `0`. That script writes `phase3-maintenance-financial.json`; save and restore the canonical receipt around reruns when its committed concurrency fixture evidence must be preserved.

Receipts: `phase3-final-maintenance-edge-cases.{txt,json}`, `phase3-final-maintenance-livewire-exceptions.txt`, `phase3-final-maintenance-financial-cancellation.{txt,json}`, and `phase3-final-maintenance-accounting-contract.txt`.

## Aggregate regressions and formatting

```powershell
$env:DB_PORT = '55442'
$env:DB_URL = ''
php -d opcache.enable_cli=0 .tools/run-safe-qa-checks.php

php vendor/bin/pint --test tests/Support/check-phase3-maintenance-financial.php tests/Support/check-phase3-maintenance-accounting-contract.php tests/Support/check-phase3-maintenance-livewire-exceptions.php
```

The aggregate runner requires the exact PostgreSQL database `erp_elec_new_test` on isolated port `55442`. Receipt: `phase3-final-aggregate.txt` and its timestamped `safe-check-run-*.json` result (21/21 scripts, 873 assertions).
