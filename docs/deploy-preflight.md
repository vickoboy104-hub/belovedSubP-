# Deployment preflight - the full range the live box is about to meet

Live box: `0935a7f7a07c97cc4c0c076b3ee97b931fd6a1a7` (read on the box, 2026-10-16). The range
this file was written against: `0935a7f7..f4ab0ad8` - **29 commits, 115 files (+17,363 /
−640)**, linear and fast-forward, `git merge-base --is-ancestor <live> <target>` answering 0,
which is checklist §0.2's pass condition.

**Re-measured 2026-10-17 against the current branch head `69418773b3621b0eec19ec4119c8a8dc19cb504d`:
45 commits, 152 files, +23,389 / −898, still linear and still a fast-forward.** Sections 2
through 8 below describe the 29 commits the owner reviewed; **section 2b is the diff this file
had not seen when it was written - three further runtime commits, one of them a migration** -
and nothing in sections 2-8 is contradicted by them.

The shorthand that got us here - "two new tables and no changed assets" - is wrong for this
range, and this file is the correction. It invents no procedure: every command block that gets
run lives in [deploy-checklist.md](deploy-checklist.md) and is run there exactly as written.
What this file holds is what to **expect**, what to **verify**, and what **cannot be undone**.
No step in this file has been run against production; it was assembled from the range itself
(`git diff 0935a7f7..f4ab0ad8`) and the migrations' own code.

Since this file was written the branch has moved again. Documentation-only commits followed
`f4ab0ad8` - the last **runtime** commit the owner reviewed is
`cd19810c123c57e4cc53b122502d5527c30f8fe7`, and when this paragraph was first written the remote
head above it, `46f0d8c3`, was documentation and CI only on top of it (7 files, none outside `docs/`
and `.github/`). Then three **runtime** commits landed under the owner's later approvals, with two
documentation commits around them. On 2026-10-17 the owner authorized a **development-only push** of
that batch for independent review and testing, so the remote review branch head is now
`e32744d45d10ccec3cdeb5cdcfea4db6e94ff288`: `46f0d8c3 → 3d7deacb` (docs) `→ f2d40bd2 → 3be00d41 →
69418773` (runtime) `→ e32744d4` (docs, 8 files, all under `docs/`), which makes **`69418773` the
runtime head on the remote** and leaves `main` untouched at `17cb6eec…`. Those three runtime commits
are now reviewable; they are still **not reviewed as a deploy target and not approved for one**, and
this file describes them rather than certifying them. Section 2b records what they are.

## 1. The range at a glance

| What | In this range |
|---|---|
| Migrations | **eight** as of 2026-10-17 (the first seven below, all additive with two data backfills, plus `admin_activity_logs` from section 2b) |
| Composer / npm dependencies | **none changed** - §1's "no composer install unless the lock moved" holds with nothing to check (`composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.js`, `tailwind.config.js`, `postcss.config.js`, `phpunit.xml` are all identical between the live box and this head) |
| `public/` outside `build/` | **nothing changed** - verified the same way as the dependency list, `git diff --name-only 0935a7f7..HEAD -- public` returns only `public/build/...`, so §3's docroot copy loop has nothing new to copy this time |
| Compiled assets | 2 new hashed files (`app-k_-jzmjF.css`, `app-DXuAyQnv.js`), the 2 live ones **removed by the checkout** (`app-BIpqTlIl.css`, `app-BXQwVUL5.js`), `manifest.json` rewritten (section 5) |
| Scheduled jobs | exactly one added: `manual:recover-documents`, every 5 minutes (section 6) |
| Saved settings and prices | **untouched** - no migration writes a settings row, no seeder runs during deploy, and `app/Helpers/settings.php` is unchanged in the range |
| Config and routes | **changed, and this corrects an earlier "unchanged" in this table**: `config/audit.php` is new, `config/services.php` gained one key (`bvn.retrieve_agent_code`, env `BVN_RETRIEVE_AGENT_CODE`, default empty), `routes/web.php` gained 14 route definitions including an `audit_owner` middleware group, and `bootstrap/app.php` registers that alias, prepends `CorrelationId` to the `web` group and adds a validation-failure render hook. `.env.example` and the seeders are still unchanged |

Checklist staleness this preflight corrects in passing: §0.1's printed sha is
`afb3260d…` "or a descendant the owner has read" - the descendant being read now is
`f4ab0ad8`, which the owner has reviewed by name. §7.4 expects **three** money migrations to
show `Ran`; this range adds **seven** - all seven must say `Ran` (section 2). §9 item 4 says
"ten" MySQL concurrency tests; the suite has been twelve since the held-deposit proof went in.

## 2. The eight migrations - what each one does to existing records

| # | Migration | Schema change | Existing records | `down()` |
|---|---|---|---|---|
| 1 | `2026_10_13_000001_create_service_forms_table` | `service_forms` table | none - table arrives empty (section 4) | drops the table |
| 2 | `2026_10_15_000001_add_assignment_to_orders_table` | `orders.assigned_to`, `orders.assigned_at`, both nullable, plus an index (all guarded by hasColumn/hasIndex) | **backfill** (section 3) | drops the columns and index |
| 3 | `2026_10_15_000002_retire_saved_customer_notes_fields` | none | edits **saved** `service_forms` rows only - a no-op on this box (section 4) | deliberately empty |
| 4 | `2026_10_15_000003_record_purchase_intents` | `purchase_intents` table with a unique `(user_id, token)`, plus nullable `orders.intent_token` and its index | none - old orders keep `NULL`, and a null never collides | drops both |
| 5 | `2026_10_15_000004_record_document_filings` | `document_filings` table | none | drops the table |
| 6 | `2026_10_16_000001_create_flutterwave_charge_claims_table` | `flutterwave_charge_claims` with a unique `charge_token` | **backfill** (section 3) | drops the table |
| 7 | `2026_10_16_000002_create_flutterwave_held_deposits_table` | `flutterwave_held_deposits` with a unique `charge_token` | none - forward-only by design, because a credit that already happened cannot be un-paid by a migration | drops the table |
| 8 | `2026_10_17_000001_create_admin_activity_logs_table` (arrives with `3be00d41`, not part of the 29 the owner reviewed) | `admin_activity_logs`: correlation id, actor, category, action, description, target, reference, result, reason, `changes` json, ip, salted 32-char `session_hash`, user agent, unique `dedupe_key`, `created_at`. No `updated_at` | none - it stores no history that exists today, and inventing one was rejected; the trail starts at the deploy. Writes no other table | drops the table |

All eight are additive: no column is narrowed, no row is deleted, no table is rewritten. That
is what makes checklist §10.3's "do not roll the database back" true for this range.

After checklist §1 runs, `php artisan migrate:status` gains **eight** `Ran` rows. None may
show `Pending`, and none may be absent.

Two live reads (2026-10-16) sharpen this table. **Zero virtual account numbers are shared
by two customers today**, so the ambiguity migration 7 exists to hold starts empty - the
first day after the deploy holds no resolution queue unless a new duplicate is created (an
admin typing a number onto a second account, or Flutterwave reissuing one), which is
exactly why the guard exists anyway. And **the live engine is MariaDB 10.6.28** - see
section 8 for what that changes and does not change.

## 2b. The three runtime commits this file had not seen

Sections 2-8 were written against `0935a7f7..f4ab0ad8`. These three commits came after, under
the owner's approvals for the duplicate-credit fix, the administrator audit trail and the
mobile admin work. They are on the **development review branch only** - pushed on 2026-10-17 for
review and testing, not merged, not deployed, and this section is a description, not the review.
Nothing here was run against production.

**`f2d40bd2` - hold a charge the wallet already dealt with.** `WalletFundingService` gains
`chargeDealtWithBefore()`, which runs **before** the ownership gate and before `claimCharge` and
pays nobody in two cases: a funding row an admin marked settled by hand
(`already_settled_manually`), and a failed row that already carries this charge's identity
(`charge_previously_reported_failed`, matched only on charge-scoped tokens). Either way the
arrival goes to `flutterwave_held_deposits` under its own reason and the global claim stays
free, so an admin release still pays exactly once. Plus `Admin/FundingRequestController`'s
`mark-settled` / `unmark-settled` routes, which move no money. **No schema change.** What the
box needs to know: the trade the owner accepted is that a marked request also **holds new
transfers arriving under that same account-scoped reference** until an admin releases or reopens
it - so a customer whose request was closed by hand and who then pays into their permanent
account lands in Held Deposits rather than their wallet. That is deliberate, and an admin has to
be told it exists. **And because this commit edits the money path itself, the twelve two-process
InnoDB proofs had to be re-run on this head.** That re-run happened on 2026-10-17 on real MySQL
**8.4.3** - **12 passed / 110 assertions / 28.57 s**, scratch database verified empty afterwards
(approval row A6). What remains open is the same twelve on **MariaDB 10.6.28**, the version the
live box reports. That workflow's push trigger is filtered to its own file, so the 2026-10-17 push
did not start it and no code push will; it has to be started from `workflow_dispatch` on GitHub,
which this machine cannot do (row C5 carries both routes).
**What this commit does not cover, measured the same day at the owner's question:** the
`already_settled_manually` branch only ever reads a row that can carry the marker, and
`Admin/FundingRequestController::record:87-91` refuses to put that marker on anything except an open
`flutterwave…` request. So neither `admin_manual_credit` nor a credit written by the balance
adjustment screen (`admin_wallet_adjustment`) can hold a later arrival - the duplicate-credit
possibility the commit was written for is closed only on the Flutterwave-request side. See
[wallet-funding-review.md](wallet-funding-review.md) §3d for the four measured cases, including the
one that must keep working: a genuinely new charge into a wallet that received an unrelated manual
credit is still credited in full.

**`3be00d41` - the append-only administrator trail.** One migration (row 8 above), 17 admin
controllers instrumented, `CorrelationId` prepended to the `web` middleware group (it only sets
a per-request uuid on the request attributes - no session, no query, no write), a
`RecordAuthenticationActivity` listener, an `audit_owner` middleware, and two new screens.
Four things about it are deploy decisions, not code details:

1. **`AUDIT_OWNER_USER_IDS` ships empty, and empty means 403 for everyone** - including the
   first admin - on `/admin/administrators` and `/admin/activity-log`. That is the fail-closed
   behaviour the owner's standing instruction requires ("do not guess which account belongs to
   the website owner"), so the safe deploy is to set nothing; the trail is written either way.
   The screens only become readable when the owner names their own user id in `.env`, and
   `php artisan config:cache` at the end of checklist §4 must run **after** that edit or the
   cached config keeps the empty list.
2. **`AUDIT_DATABASE_TRIGGERS` does nothing yet, and the docs used to say otherwise.** Measured
   on this head: `config/audit.php:45` declares the flag, and **no code anywhere reads it** -
   `grep -rniE "create trigger|before update|before delete" app config database tests` finds the
   string only in that config comment and in two unrelated tests that build triggers to prove a
   database guard *would* work (`WalletLedgerSafetyTest:393`, `ManualOrderAnswerSafetyTest:403`).
   The `admin_activity_logs` migration contains no trigger DDL. So the append-only guarantee in
   this batch is **application-level only**: `AdminActivityLog::booted()` throws on `updating` and
   `deleting` (`:60-69`), which stops every Eloquent write, while the recorder itself inserts
   through `DB::table(...)->insertOrIgnore()` (`AdminActivityRecorder:146`), a path the model guard
   does not - and cannot - bind. Anyone holding the database password can still change the table.
   Turning the flag on would not change that, because there is nothing behind it to turn on; the
   missing piece is trigger DDL plus the `TRIGGER` privilege, which is a decision and a separate
   change, not a `.env` edit. The stale comment in `config/audit.php:33-41` saying the migration
   installs triggers is listed as a one-line correction the owner has not yet approved, because
   that file is under `config/` and would make this documentation batch runtime-touching.
3. **A manual wallet movement now requires a note.** `Admin/UsersController` validates `note`
   as `required|string|min:10|max:255` on the hand credit (`:279`) and on the
   credit/debit/set adjustment (`:399`), and the balance only moves if that write and the log
   row commit together. An admin who used to press Save with the reason blank will now get a
   validation error on a flow that used to accept it - intended, but worth saying out loud
   rather than discovering on the first adjustment after the deploy.
4. **Two recording forms, and the difference matters on the box.** `recordQuietly()` swallows
   its own failure - a page view, a refusal, an auth event can lose a line rather than show a
   customer a 500. Anything that **moved a balance uses the throwing `record()`** (the class
   docblock says so at `app/Services/AdminActivityRecorder.php:65-84`), which means the log row
   and the action commit or roll back together. Consequence for the deploy: if
   `admin_activity_logs` does not exist while this code is live, a manual wallet credit does not
   degrade - it fails. That makes checklist step order (checkout, **then** `migrate --force`,
   then `optimize`, then `up`) load-bearing and row 7.4 a real gate rather than a formality.
   `record()` inside the caller's transaction also means a rolled-back action leaves no phantom
   entry.

**`69418773` - the phone-usable boards and the read-side mask.** `resources/css/app.css` and
the built bundle change (the CSS hash moved to `app-k_-jzmjF.css`, **109 lines added / 7
rewritten**), a shared
`components/pagination.blade.php` becomes `Paginator::defaultView` for **every** `->links()`
call site on the site and `components/records-table.blade.php` (**44 / 9**) is rendered by
three customer pages - `resources/views/vtu/manual-service.blade.php`,
`resources/views/vtu/orders.blade.php`, `resources/views/wallet/transactions.blade.php` - which
is the customer-facing surface of this batch, together with 44 px tap targets on the shared
drawer and modal classes. No customer view file is edited and no price, form or wording
changes; the customer delta is dimensions only, and it is covered on the customer side by
`tests/Feature/TransactionHistoryPresentationTest.php` (8 methods). Three admin boards mask NIN/BVN/phone/virtual-account to the last
four digits while still searching on the whole figure, and `/admin/activity-log` masks
description, reason and changes on the way out while leaving `reference` whole, because the
writer keeps it as the trail's search key. **`public/build` is force-tracked**: the bundle in
this commit is what ships, and a rebuild on the box would produce a different hash and a
manifest that disagrees with the docroot copy (checklist §3).

What this section does **not** do: it does not certify these three commits. The owner's
approval of the work and approval of a deploy target are different readings, and row A1 stays
PENDING until the diffs above are read as a target.

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

Two new content-hashed files (`app-k_-jzmjF.css`, `app-DXuAyQnv.js`) and a rewritten
`manifest.json`, all inside `public/build/`, all committed in-range on the development
machine (the build rule: force-tracked, view cache warm, byte sizes compared). The server
never rebuilds. Measured against the trees rather than against this document: `git ls-tree -l
0935a7f7 public/build/assets/` is exactly `app-BIpqTlIl.css` (174,257 B) and `app-BXQwVUL5.js`
(103,579 B), and `git ls-tree -l 69418773 public/build/assets/` is exactly `app-k_-jzmjF.css`
(187,639 B) and `app-DXuAyQnv.js` (106,512 B). An earlier draft of this section named
`app-D-vIop72.css`, which is the build carried at the previous anchor `cd19810c` and is
**superseded inside `69418773`** - it is in neither of the two trees above, so the box never
holds it and the checkout never touches it.

**Correction, 2026-10-17 - this range also removes the two old hashed files.** The live
tree at `0935a7f7` carries exactly `app-BIpqTlIl.css` and `app-BXQwVUL5.js`, and the
target tree carries the new pair, so an earlier draft of this section wrongly called all
four names "new". What the removal means on the box:

- the checkout **deletes** the old pair from `vtu/public/build/assets/`, and because
  `public_html/build` is a **symlink** into the repo, they leave the docroot with the same
  checkout - no copy step either way, and `git status` stays clean, because the removals
  are committed, not working-tree edits;
- a returning visitor is covered: `sw.js` serves cached static assets cache-first, so a
  browser still holding an old URL gets its cached copy, and checklist §6 needs no
  `CACHE_VERSION` bump because `sw.js` and every un-hashed asset are unchanged;
- the one real exposure is a browser that never cached an old asset but still asks for one
  from a stale page - it 404s until it loads a fresh page. The maintenance window (§1.1)
  closes exactly this, which is why taking the window for this range does real work beyond
  the checkout-to-migrate seconds;
- a rollback restores the old pair by the same mechanism (they are tracked at `0935a7f7`),
  so §10.4's "the old build's entries are still there" holds after going back.

Checklist §3's copy loop (which excludes `build/` and `storage/`) has nothing new to copy
this time, because nothing outside `build/` changed under `public/`; running it remains a
harmless confirmation that nothing is missing.

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
which is checklist §5's requirement - and the sweep itself was observed executing and
completing (2026-10-16), which is stronger than the crontab line: the job not only fires
but finishes. `schedule:list` shows two jobs today only because the box
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
- PHP on the host is 8.3.35 CLI (owner-read 2026-10-16, superseding an earlier 8.3.33
  read - the host moved, or a different binary was read; both satisfy the code's `^8.3`);
  the range adds no dependency and was exercised on 8.3 here.
- **The live database engine is MariaDB 10.6.28** (autocommit 1; `users`, `orders`,
  `wallets`, `wallet_transactions` all InnoDB - owner-read 2026-10-16). Two consequences.
  First, `$table->json()` columns (`service_forms.definition`, the claims and held-deposits
  payload columns) become `LONGTEXT` with a `json_valid` check under MariaDB - Laravel's
  MariaDB grammar handles the mapping, so nothing in section 2 changes. Second, the twelve
  money-path concurrency proofs ran on **MySQL 8.4.3**, and MariaDB is a different engine
  however similar its InnoDB: one suite run against a scratch database on the host - the
  `howToRun()` block inside `tests/Feature/MysqlMoneyPathConcurrencyTest.php` - is what
  turns "proved on an engine like it" into "proved on the engine that runs the money
  path". Creating that database is not a read-only act, so it sits outside
  production-checks as an owner decision: checklist §9 item 4. The live isolation level
  (**REPEATABLE-READ**) and lock timeout (**50 s**), read 2026-10-16, match the pair the
  proofs ran under - MySQL 8.4's defaults are the same two values - so the engine
  implementation is the only remaining delta.
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

**Update (2026-10-17):** the owner has made the backup a required part of the procedure,
and the checklist now owns it as §1.0 with gate row 0.5 - export the whole database once
before §1's pull (`mysqldump` from the account shell if allowed; cPanel's phpMyAdmin
Export is the always-available route), verify the copy reads back, keep it outside
`public_html` with `600` permissions, download a copy off the server, and record the
filename beside `last-good-sha.txt`. What §1.0's blocks have not had is a run on this host:
its availability check decides the route.

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
box as it stands at `0935a7f7` - and as of 2026-10-16, §1-§5 and §7 are **answered** (its
§0 carries the recorded set); what is still out from that document is §3's remaining key
presences and §8's log counts. **§6's audit command is not** - on the old code the audit's
`--references=0` still clamps up to one real Flutterwave call (the defect `cd19810c` fixed),
so the audit runs only once the new code is live. The pre-deploy ledger baseline comes from
the count query §6 now carries. Post-deploy adds: §6's audit (now genuinely offline, and the
orphan-check it exists for), `schedule:list` showing three rows, `migrate:status` showing all
eight migrations `Ran`, §8.3's claims count greater than zero, and §7's smoke table.
