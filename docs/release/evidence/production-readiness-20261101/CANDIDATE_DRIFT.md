# Unattributed concurrent UI candidate change

Detected by final preservation comparison,2026-11-01.

- Baseline public manifest SHA-256: `F024489FB0FC078F78EEB915C05871B61B09A9A235DBB1F6FD454CD97EABE804`.
- Final public manifest SHA-256: `876DBDDFC7EFB970633E0AD60B8B9E06FACAEC34520DE7E0F1C8BC6C017173DD`.
- Public tree SHA-256: `5C63CBCF084A205C6C9DECC8D28FFD90DE904DFC6844CE46011948974D6AAB7F` → `4DC16A3DBF39153A13147A5AA3EEAA6C0CD2386EB3C4853C97FA3FDAAFEB3F4F`.
- Actual CSS source `resources/css/app.css` last-write UTC01:06:54; public build files last-write UTC01:07:27. Final manifest points at `assets/app-BndKEvZV.css`.
- The lead's explicit audit build used `npm run build -- --outDir .tmp/production-readiness-20261101/vite-build`; its recorded output was `app-BJ12s52F.css`, not that final public build. Both Luna audit agents confirm they did not edit CSS or run a public build. Origin of the later mutation was not established; no other work was reverted.
- `.env`, `.env.testing`, `FinancialReports.php` and `JournalPosting.php` hashes match before/after receipts. The three current financial reproductions remain applicable to those unchanged source files.

The90 browser screenshots are a capture-time candidate receipt around00:56UTC, before this UI change. The bounded local load is after the change. Do not merge them into a frozen-candidate UI/performance acceptance or claim final public assets preserved. Later-current visual layout remains NOT TESTED by those earlier images. PR-REL-001 already tracks the unpinned/unfrozen release; no duplicate finding is added for this evidence.

Raw hashes: `preservation-before.json`, `preservation-after.json`; fresh CSS/manifest inventory may be supplemented by the browser evidence. This file records observed timestamps/hashes rather than assigning authorship.
