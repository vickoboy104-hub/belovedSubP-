# AGENTS.md — working notes for AI coding agents

BelovedSubP is a Laravel 12 VTU (virtual top-up) platform: members fund a wallet and buy
airtime, data, cable TV, electricity, exam pins and premium apps. Orders are fulfilled
through the GSUBZ API; wallet funding runs through Flutterwave; NIN/BVN identity checks
are separate provider integrations.

## Where work happens

- `main` — stable line.
- `codex/*` — feature branches. Current active branch: `codex/identity-first-jhtech-experience`.
  Most recent work lands here first; merge to `main` deliberately, not by habit.
- `.env` is never committed. The provider keys in it (Flutterwave, GSUBZ, NIN, BVN, mail,
  AWS) are intentionally left empty in new checkouts — the owner supplies real values
  themselves. Do not invent, copy from another machine, or commit key values.

## Setup (fresh clone)

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
npm run build
php artisan serve   # http://127.0.0.1:8000
```

Defaults: SQLite (`DB_CONNECTION=sqlite`), database sessions/cache/queue — no MySQL or
Redis needed locally. Seeders create the service catalogue (ServiceSeeder) and a test user.

Windows/Laragon quirk: Laragon's PHP binaries may be named `vphp.exe` etc. If `php` is not
on PATH, call the binary directly, e.g.
`C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/vphp.exe artisan ...`, and run Composer as
`php composer.phar` if no global `composer` exists.

## Verification loop

Run before declaring any change done:

```bash
php artisan test          # full suite (91 tests at last count)
npm run build             # required — several tests assert on the compiled stylesheet
```

`/public/build` is gitignored, but the built `app-*.css` / `app-*.js` pair and
`manifest.json` are deliberately force-tracked so deployments don't need a build step.
After a rebuild, `git add -f` the new hashed pair and `git rm --cached` the stale one.

## UI conventions (enforced by tests)

All shared chrome is token-driven and theme-aware — two themes exist, navy (default) and
ember (`html[data-theme="ember"]`). Never hand-paste Tailwind palette utilities
(`bg-slate-50`, `bg-amber-100`, hex golds) onto shared components; extend the classes in
`resources/css/app.css` so both themes stay correct:

- Confirm sheets / flash toasts: `.app-modal-overlay`, `.app-modal-panel`, `.app-modal-btn*`.
- Notification cards and flags: `.app-note-card`, `.app-flag*` (one unread/critical language).
- Suggestion chips (recent numbers, quick amounts): `.app-choice-chip`, `.app-choice-caption`.
- Theme tokens: `--brand-navy`, `--brand-accent`, `--brand-line`, `--surface-*` (defined
  per theme in `resources/css/app.css`).

`tests/Feature/PopupStyleUnificationTest.php` guards these rules — if you add a new shared
surface, extend it there and pin the class in the built-stylesheet test.
