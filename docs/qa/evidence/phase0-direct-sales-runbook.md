# Phase 0 direct sales-order approval acceptance

Run against the isolated PostgreSQL restore only: `127.0.0.1:55433/erp_elec_new_test`, schema migration 171. The probe requires the already activated synthetic recovery fixture, approved dated prices, customer, quantity products, branch, and warehouse.

```powershell
$env:DB_CONNECTION='pgsql'; $env:DB_DATABASE='erp_elec_new_test'; $env:DB_URL=''
$env:DB_HOST='127.0.0.1'; $env:DB_PORT='55433'
php artisan --env=testing migrate --force
php tests/Support/check-phase0-direct-sales.php
```

The probe guards the active PostgreSQL connection and runs its synthetic actors, grants, role assignment, policy change, price source change, orders, decisions, official documents, and reservation actions inside one outer transaction that is rolled back after assertions. The run does not leave test decisions, privilege grants, approval rows, price changes, or inventory reservations in the restored fixture.

The compliant direct order used the existing approved catalog price and active terms; the script saved, reopened, submitted, independently approved, officially issued, and reserved it. A separate order with a requested price change, fixed line discount, and custom payment term was rejected by normal approval without effects. Its configured exception-role actor recorded an immutable review decision while the order remained pending; the ordinary independent approval then issued the order and reservation.

Negative cases covered self-approval, a user with exception permission but the wrong configured role, stale order version/content review, immutable review/decision history, stale policy version, a changed dated price source, and missing warehouse scope. Result: `phase0-direct-sales.json`, PASS.
