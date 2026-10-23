# Database baseline
PostgreSQL 17, UTF-8, UTC. Only framework identity/session, cache/lock, queue/failed-job, 2FA and passkey migrations ship in this foundation. No business tables or posted history exist yet.
The [supplied dictionary](04-DATABASE-ARCHITECTURE.md) defines future tables. Central migrations follow real dependency order. Use NUMERIC(18,4) quantities, NUMERIC(19,2) money, NUMERIC(19,6) unit prices/costs and decimal-string calculations; RESTRICT deletion for business references.
Tests use erp_elec_new_test, separate from erp_elec_new. Never use migrate:fresh against a working database.
