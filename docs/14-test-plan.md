# Risk-based checks
Foundation: PostgreSQL migrations, auth/registration/privacy tests, own-settings flow, Arabic RTL rendering, Vite production build, Pint, desktop/mobile browser smoke.
Per business slice: current scope/denial, trust-boundary validation, decimal accuracy and posting/retry/concurrency tests only where exposed. Use real PostgreSQL for transaction and lock evidence.
No broad coverage target. Record commands actually run in .ai/TEST_RESULTS.md. Use only synthetic local accounts and an isolated test database.
