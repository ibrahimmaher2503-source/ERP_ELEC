# Decisions — 2026-10-21
- PostgreSQL overrides legacy SQLite text for this new checkout, per user instruction.
- Current official Livewire starter source, stable-only Composer dependencies and locked npm packages. See .tools/starter-commit.txt for downloaded source revision.
- Retain authentication; disable public registration and account deletion for ERP history.
- Default file cache/database queue; Redis via Predis only when needed.
- Shared shell and three small display components first; extract further reuse from actual screenshot pages.
- No complete business migrations, synthetic financial metrics or AI implementation in the foundation milestone.
