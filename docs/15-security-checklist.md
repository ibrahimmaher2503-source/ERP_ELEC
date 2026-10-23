# Affected-surface security
- Fortify authentication, CSRF, throttling, password hashing, 2FA and verification retained.
- Disable public registration and self-service user deletion; no seeded default password.
- Authenticated settings affect the current user only. Business authorization is a future owner gate, never inferred from login.
- Escape output; validate on server; private local storage/mail logs; secrets ignored.
- Local services bind loopback. Isolate test database; no destructive database refresh in setup.
- Uploads, exports, grants, posting, audit and production hardening must be reviewed with their future slices.
