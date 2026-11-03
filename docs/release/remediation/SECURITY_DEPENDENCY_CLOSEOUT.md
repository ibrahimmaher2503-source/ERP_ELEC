# Security and dependency remediation

PR-SEC-001 and PR-SEC-002: **VERIFIED FIXED within locked dependency/advisory and focused regression scope**. Livewire4.4.7→4.4.8 fixes GHSA-mhf7-xp6r-6qrc; Symfony MIME8.1.7→8.1.10 fixes CVE-2026-45070 (MIME parameter-name/header injection). Only those two packages changed in the targeted Composer update. Original advisory JSON and failed regression attempts are retained.

Official references: https://github.com/livewire/livewire/security/advisories/GHSA-mhf7-xp6r-6qrc and https://symfony.com/blog/cve-2026-45070-email-header-injection-via-non-token-characters-in-mime-parameter-names.

`php -d opcache.enable_cli=0 artisan test --filter=DependencySecurityRegressionTest`:3 tests/14 assertions PASS. Raw reordered integer payload is rejected before authorization; named payload is authorized in parameter-name order; forbidden actions have zero effects; invalid MIME parameters are rejected. `evidence/gate6/dependency-regressions-20261101-165756.stdout.txt` preserves the passing run.

Fresh locked Composer audit: `evidence/gate6/composer-audit-final.json`, no advisories/abandoned packages, empty stderr, exit0. The earlier after-update scan used cached metadata after a network error; it is preserved separately and is not the final freshness evidence. npm audit `npm-audit-final.json`: zero vulnerabilities.

PR-SEC-003 remains **NOT TESTED in full**. `rbac-stateful-20261101T150104Z-1e6f4e.json` verifies real M10 Action denials for disabled Administrator, removed grant, branch/object mismatch, ordinary-role and warehouse boundaries, plus rollback. The 37-assertion browser run includes account-disabled/revoked/read-only behavior. These bounded cases are not the full every-module/action/object/state matrix. New advance/dashboard permissions and final candidate regression still require acceptance.
