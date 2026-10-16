# Deployment preflight - the full range the live box is about to meet

Live box: `0935a7f7a07c97cc4c0c076b3ee97b931fd6a1a7` (read on the box, 2026-10-16). Target:
`f4ab0ad83baad411ca8b9942c0aa6aecd278335d`. Between them: **29 commits, 115 files changed
(+17,363 / -640)**, linear and fast-forward - `git merge-base --is-ancestor <live> <target>`
answers 0, which is checklist §0.2's pass condition.

The shorthand that got us here - "two new tables and no changed assets" - is wrong for this
range, and this file is the correction. It invents no procedure: every command block that gets
run lives in [deploy-checklist.md](deploy-checklist.md) and is run there exactly as written.
What this file holds is what to **expect**, what to **verify**, and what **cannot be undone**.
No step in this file has been run against production; it was assembled from the range itself
(`git diff 0935a7f7..f4ab0ad8`) and the migrations' own code.

## 1. The range at a glance

| What | In this range |
|---|---|
| Migrations | **seven**, all additive; two carry data backfills (section 2) |
| Composer / npm dependencies | **none changed** - §1's "no composer install unless the lock moved" holds with nothing to check |
| `public/` outside `build/` | **nothing changed** - §3's docroot copy loop has nothing new to copy this time |
| Compiled assets | 4 new hashed files plus `manifest.json`, all committed inside the range (section 5) |
| Scheduled jobs | exactly one added: `manual:recover-documents`, every 5 minutes (section 6) |
| Saved settings and prices | **untouched** - no migration writes a settings row, no seeder runs during deploy, and `app/Helpers/settings.php` is unchanged in the range |
| `.env.example`, routes file, seeders | unchanged |

Checklist staleness this preflight corrects in passing: §0.1's printed sha is
`afb3260d…` "or a descendant the owner has read" - the descendant being read now is
`f4ab0ad8`, which the owner has reviewed by name. §7.4 expects **three** money migrations to
show `Ran`; this range adds **seven** - all seven must say `Ran` (section 2). §9 item 4 says
"ten" MySQL concurrency tests; the suite has been twelve since the held-deposit proof went in.

## 2. The seven migrations - what each one does to existing records

| # | Migration | Schema change | Existing records | `down()` |
|---|---|---|---|---|
| 1 | `2026_10_13_000001_create_service_forms_table` | `service_forms` table | none - table arrives empty (section 4) | drops the table |
| 2 | `2026_10_15_000001_add_assignment_to_orders_table` | `orders.assigned_to`, `orders.assigned_at`, both nullable, plus an index (all guarded by hasColumn/hasIndex) | **backfill** (section 3) | drops the columns and index |
| 3 | `2026_10_15_000002_retire_saved_customer_notes_fields` | none | edits **saved** `service_forms` rows only - a no-op on this box (section 4) | deliberately empty |
| 4 | `2026_10_15_000003_record_purchase_intents` | `purchase_intents` table with a unique `(user_id, token)`, plus nullable `orders.intent_token` and its index | none - old orders keep `NULL`, and a null never collides | drops both |
| 5 | `2026_10_15_000004_record_document_filings` | `document_filings` table | none | drops the table |
| 6 | `2026_10_16_000001_create_flutterwave_charge_claims_table` | `flutterwave_charge_claims` with a unique `charge_token` | **backfill** (section 3) | drops the table |
| 7 | `2026_10_16_000002_create_flutterwave_held_deposits_table` | `flutterwave_held_deposits` with a unique `charge_token` | none - forward-only by design, because a credit that already happened cannot be un-paid by a migration | drops the table |

All seven are additive: no column is narrowed, no row is deleted, no table is rewritten. That
is what makes checklist §10.3's "do not roll the database back" true for this range.

After checklist §1 runs, `php artisan migrate:status` gains **seven** `Ran` rows. None may
show `Pending`, and none may be absent.

## 3. The two backfills, in detail

**Order assignments** (`2026_10_15_000001`). Reads `orders` where `status = pending` and
`meta.work_state = in_progress`, and writes `assigned_to = meta.work_state_by`,
`assigned_at = meta.work_state_at` (or now). It carries forward exactly the requests an admin
had marked as started, so nobody loses a job mid-flight to this deploy. Decided row by row in
PHP because comparing inside a JSON column is not answered the same way by both engines.

**Flutterwave charge claims** (`2026_10_16_000001`). Reads every **successful** credit in
`wallet_transactions`, extracts the charge's own identifiers from `meta`
(`flutterwave_charge_id`, the stored payload's `id`, `flutterwave_id`, `flw_ref` - never the
customer's `tx_ref` or the account number, which repeat by design), and inserts one claim row
per unique token, attributed to the first ledger row that carried it. Deposits already banked
are thereby claimed before the guard goes live, so a transfer credited last month cannot be
credited to somebody else after the deploy. Existing duplicates in the ledger - the very thing
this guard closes - are kept exactly as they are and claimed once. Both backfills chunk at 200
rows, so the run is seconds, not minutes, at this site's volume.

Checklist §8.3 already owns the verification: `flutterwave_charge_claims` must count **greater
than zero** on a box with funding history the moment `migrate` finishes. Zero means the
backfill found nothing to read and only new deposits are guarded.

## 4. Saved service-form definitions - what actually happens to them

The live box has **none**: the service-forms editor arrived inside this range, so no admin has
ever saved a row there. The table is created empty, and migration 3 - which removes the retired
`notes` ("Anything else we should know") and `agent_code` ("Agent Code (Optional)") questions
from **saved** definitions, but only where the label still matches the shipped wording, so an
owner's reworded question survives - finds nothing to do. It is a no-op on this box by
construction, not by luck.

The hub cannot come up empty either: `ServiceFormRepository::all()` starts from the catalogue
in code (`ManualFulfilmentService::legacyCatalogue()`) and overlays whatever rows exist, so a
service nobody has saved inherits the shipped shape, and a service the owner saves after the
deploy overrides exactly the keys it stores. No seeder runs during the deploy; none is needed.

## 5. Compiled assets and the docroot

Four new content-hashed files (`app-BIpqTlIl.css`, `app-BXQwVUL5.js`, `app-D-vIop72.css`,
`app-DXuAyQnv.js`) plus a rewritten `manifest.json`, all inside `public/build/`, all committed
in-range on the development machine (the build rule: force-tracked, view cache warm, byte sizes
compared). The server never rebuilds.

`public_html/build` is a **symlink** into the repo, so these files are served the moment the
checkout lands - no manual copy involved. Checklist §3's copy loop (which excludes `build/`
and `storage/`) has nothing new to copy this time, because nothing outside `build/` changed
under `public/`; running it remains a harmless confirmation that nothing is missing.

Checklist §6 needs no action: `sw.js` is unchanged, no un-hashed asset changed, so no
`CACHE_VERSION` bump. Vite's hashed names mean the new files are fetched fresh by every page
load. Old hashed files remain on disk - additive, as §3 demands.

## 6. Untracked paths on the box - `server.js` and `tmp/`

Both are expected to appear in `git status --porcelain` on the box as `??` entries, and that
is all they are: the owner's known untracked paths, **not** hand-edits to app code. Neither
path exists anywhere in `f4ab0ad8`'s tree, so:

- the fast-forward checkout **cannot collide** with them - git refuses a checkout only when an
  incoming tracked file would overwrite an untracked one, and no tracked file lands at either
  path;
- checkout will not touch, move or delete them;
- neither is in `.gitignore`, which is why they surface in `git status` at all. Reading §1's
  status output on the box must treat exactly these two entries as expected.

No step in the checklist runs `git clean`, and none should: that is the one command family
that would remove them.

## 7. The scheduler - one job appears, nothing else changes

The cron is already right: it exists and fires every minute (read on the box, 2026-10-16),
which is checklist §5's requirement. `schedule:list` shows two jobs today only because the box
runs `0935a7f7`; `bootstrap/app.php` in the range adds exactly one - `manual:recover-documents`,
every 5 minutes, `withoutOverlapping()` - and leaves `prices:sync` (hourly) and
`wallet:sync-deposits` (every 5 minutes) as they are. After the deploy, `schedule:list` must
show **three** rows and nothing else.

One window worth naming: checklist §1 sequences checkout then migrate, and the cron fires
every minute, so a tick that lands between them runs new code against the old schema and logs
a missing-table error rather than corrupting anything (`withoutOverlapping()` keeps them from
piling up; a webhook in the same window 500s and is re-found by the sweep once the tables
exist). §1 run as written keeps that window to seconds. If the owner wants it gone entirely,
the `down`/`up` pair §10 already uses, taken before §1 and after §4, closes it - that is an
owner decision, not a change to the written blocks.

## 8. Hosting configuration

- **One new env-backed key**: `BVN_RETRIEVE_AGENT_CODE` (in `config/services.php`, defaulting
  to empty). Checklist §8's table already lists it - required only if BVN phone/BMS retrieval
  is offered. The repository's `.env` and `.env.example` are unchanged; the live `.env`
  decides, and a presence-only check (never a value) settles it. Because config is cached on
  this host, the key only takes effect after checklist §4's `optimize`.
- PHP on the host is 8.3.33 CLI (checklist header); the range adds no dependency and was
  exercised on 8.3 here.
- Everything else in §8's integration table is unchanged by the range: admin settings still
  beat `.env`, the webhook route and its hash rule are untouched, and the three saved modes
  read on 2026-10-16 (`provider = gsubz`, `nin = automatic`, `bvn = automatic`) are carried
  across untouched.
- §0.4 (credential rotation) is still the standing gate: it is not cleared, so the deploy is
  not cleared, whatever this preflight says about the code.

## 9. Database backup - the one step the checklist does not have

§10.0 records the sha being left, and §10.3 forbids rolling the database back - but nothing in
the checklist takes a copy of the database, and this range is the first to contain
**data-writing** migrations (the two backfills in section 3). A migration that fails halfway
leaves a schema half-applied, and "go back to the old code" does not undo the half that ran.

Before checklist §1, export the whole database once - cPanel's phpMyAdmin Export is the
always-available route on this host; `mysqldump` if the account's shell allows it. This is a
recommendation added by this preflight, not a block proven on this box: the owner should read
it, decide, and treat the export's whereabouts as part of the rollback record alongside
`last-good-sha.txt`.

## 10. Rollback limits specific to this range

§10's doctrine holds - checkout back, never `migrate:rollback` - because every migration is
additive and old code ignores what it does not know. What a code-only rollback leaves behind,
table by table:

- **`purchase_intents` held rows** - §10.6 already owns this: resolve through
  [held-purchase-runbook.md](held-purchase-runbook.md), never by deleting the row.
- **`flutterwave_held_deposits` rows created while the new code ran** - old code has no screen
  for them. Held money should be resolved **before** any rollback; if the box has already gone
  back, the rows survive and are findable by SQL, they are just invisible to the old UI.
- **`document_filings` rows awaiting recovery** - old code has no `manual:recover-documents`,
  so recovery pauses until the code returns; the rows survive.
- **`orders.assigned_to` written by the new code** - old code reads the `meta.work_state`
  marker, which the new code no longer writes. Claims made while the new code ran revert to
  looking unclaimed after a rollback; an admin re-claims them. The columns and their audit
  values survive untouched.
- **`flutterwave_charge_claims`, including the backfill** - old code never reads the table;
  harmless either way.
- **Migration 3's edit to saved definitions** - irreversible by design (empty `down()`), and a
  no-op on this box besides.

And §10.6's blanket rule unchanged: wallet movements made while the new code ran are ledger
rows and stay.

## 11. Which read-only checks run before the deploy, and which after

[production-checks.md](production-checks.md) §1-§5, §7 and §8 are pre-deploy: they read the
box as it stands at `0935a7f7`. **§6's audit command is not** - on the old code the audit's
`--references=0` still clamps up to one real Flutterwave call (the defect `cd19810c` fixed),
so the audit runs only once the new code is live. The pre-deploy ledger baseline comes from
the count query §6 now carries. Post-deploy adds: §6's audit (now genuinely offline, and the
orphan-check it exists for), `schedule:list` showing three rows, `migrate:status` showing all
seven migrations `Ran`, §8.3's claims count greater than zero, and §7's smoke table.
