# Deployment preflight - the full range the live box is about to meet

Live box: `0935a7f7a07c97cc4c0c076b3ee97b931fd6a1a7` (read on the box, 2026-10-16). The range
this file was written against: `0935a7f7..f4ab0ad8` - **29 commits, 115 files (+17,363 /
−640)**, linear and fast-forward, `git merge-base --is-ancestor <live> <target>` answering 0,
which is checklist §0.2's pass condition.

**Re-measured 2026-10-17 against the current runtime head `1e33de76`: `git rev-list --count
0935a7f7..1e33de76` = 54 commits, `git diff --shortstat` = **180 files, +28,669 / −967**, split by
the rule printed in checklist A3 into **30 runtime + 24 documentation/CI-only**. Still linear,
still a fast-forward** (`git merge-base --is-ancestor 0935a7f7 1e33de76` answers 0). Sections 2
through 8 below describe the 29 commits the owner reviewed; **section 2b is the diff this file had
not seen when it was written**, section 2c the granular-permission commit, and **section 2d the
two runtime commits that are now the head** - and nothing in sections 2-8 is contradicted by them.

**Remote state, re-read 2026-10-17 with `git ls-remote origin`:** the review branch head is
`94c5b4b71e827f42708bd49db281266c28abe916`, which **contains** `6f14c4e8` and the documentation
commits above it, so those are pushed and reviewable against `origin`. `main` is untouched at
`17cb6eec82cd037d191c7965a90dd86a6777e3f9`. The two runtime commits `ee3bfef9` and `1e33de76` -
the deposit-recording fix and the button-hiding fix - are **local only, unpushed and unread by the
owner**; `git pull` on any other machine still tops out at `94c5b4b7`, whose runtime head is
`6f14c4e8`. Nothing in this file is authorized to deploy.

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
that batch for independent review and testing, so the remote review branch head was
`e32744d45d10ccec3cdeb5cdcfea4db6e94ff288`: `46f0d8c3 → 3d7deacb` (docs) `→ f2d40bd2 → 3be00d41 →
69418773` (runtime) `→ e32744d4` (docs, 8 files, all under `docs/`), which made **`69418773` the
runtime head on the remote** and left `main` untouched at `17cb6eec…`. Those three runtime commits
became reviewable; they are still **not reviewed as a deploy target and not approved for one**, and
this file describes them rather than certifying them. Section 2b records what they are. **The remote
has moved twice since that sentence was written** - it is `94c5b4b7` today, see the next two
paragraphs.

**And a fourth has landed since.** `6f14c4e8` (granular administrator
permissions + the manual-settlement declaration + the corrected `config/audit.php` comment, 44 files,
+3,756 / −152, **two migrations**) sits above a documentation commit `98c96cb1`. Section 2c records
it. **This paragraph's own remote claim is now stale and is corrected here rather than left
standing:** as it was written, `6f14c4e8` was unpushed. It is **pushed now** - the owner authorized
the development-only batch on 2026-10-17, and the remote review head is `94c5b4b7`, a descendant of
`6f14c4e8` (`git merge-base --is-ancestor 6f14c4e8 94c5b4b7` answers 0). So the runtime head
reachable by `git pull` is `6f14c4e8`, not `69418773` - and that tree is still **not** the one rows
A3, A4 and A6 of the approval checklist now describe, because two further runtime commits sit above
it unpushed: `ee3bfef9` and `1e33de76`, recorded in section 2d.

**The two commits that make up the head today.** `ee3bfef9` (5 files, +697 / −24) closes the three
silent-loss arrival paths in the funding service, and `1e33de76` (7 files, +340 / −1) stops the admin
screens offering a button the server will refuse. Neither adds a migration and neither touches CSS,
JS or a compiled asset. Section 2d holds the reading order, the two defects found in the first
attempt at this fix, and the cases that must not break.

## 1. The range at a glance

| What | In this range |
|---|---|
| Migrations | **ten** as of 2026-10-17 (the first seven below, all additive with two data backfills, plus `admin_activity_logs` from section 2b and, from `6f14c4e8` described in section 2c, `wallet_settlement_links` and `admin_permission_grants` - the second of them carries a backfill of its own) |
| Composer / npm dependencies | **none changed** - §1's "no composer install unless the lock moved" holds with nothing to check: `git diff --name-only 0935a7f7..HEAD -- composer.json composer.lock package.json package-lock.json vite.config.js tailwind.config.js postcss.config.js` returns **nothing**. The one file in that old list that is no longer identical is `phpunit.xml`, changed by `6f14c4e8`; it is read by the test runner only, never by the running site, and it adds no dependency |
| `public/` outside `build/` | **nothing changed** - verified the same way as the dependency list, `git diff --name-only 0935a7f7..HEAD -- public` returns only `public/build/...`, so §3's docroot copy loop has nothing new to copy this time |
| Compiled assets | 2 new hashed files (`app-k_-jzmjF.css`, `app-DXuAyQnv.js`), the 2 live ones **removed by the checkout** (`app-BIpqTlIl.css`, `app-BXQwVUL5.js`), `manifest.json` rewritten (section 5) |
| Scheduled jobs | exactly one added: `manual:recover-documents`, every 5 minutes (section 6) |
| Saved settings and prices | **untouched** - no migration writes a settings row, no seeder runs during deploy, and `app/Helpers/settings.php` is unchanged in the range |
| Config and routes | **changed, and this corrects an earlier "unchanged" in this table**: `config/audit.php` and `config/admin.php` are new, `config/services.php` gained one key (`bvn.retrieve_agent_code`, env `BVN_RETRIEVE_AGENT_CODE`, default empty), `routes/web.php` gained 47 route definitions - **44 of them carry a `can_admin:<slug>` gate**, which is the whole point of `6f14c4e8` - and `bootstrap/app.php` registers the `audit_owner` and `can_admin` aliases, prepends `CorrelationId` to the `web` group and adds a validation-failure render hook. **`.env.example` is no longer unchanged**: it gains two documented, deliberately empty lists (`AUDIT_OWNER_USER_IDS`, `MASTER_ADMIN_USER_IDS`) and no secret, no email and no id value. The seeders are still unchanged |

Checklist staleness this preflight corrects in passing: §0.1's printed sha is
`afb3260d…` "or a descendant the owner has read" - the descendant being read now is
`f4ab0ad8`, which the owner has reviewed by name. §7.4 expects **three** money migrations to
show `Ran`; this range adds **ten** - all ten must say `Ran` (section 2). §9 item 4 says
"ten" MySQL concurrency tests; the suite has been twelve since the held-deposit proof went in.

## 2. The ten migrations - what each one does to existing records

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
| 9 | `2026_10_18_000001_create_wallet_settlement_links_table` (arrives with `6f14c4e8`) | `wallet_settlement_links`: unique `charge_token` (191) plus `charge_token_kind` (`charge_id` or `flw_ref`, the only two keys the money path will read), nullable `tx_ref` kept **for the human at the screen and never matched on**, `wallet_id`, `manual_transaction_id`, `amount_kobo`, `status` default `active`, `reason` (500), `declared_by_user_id` + `declared_at`, `retracted_by_user_id` + `retracted_at` + `retraction_note`, timestamps | none - the table arrives empty, and it reads no existing row. A declaration can only exist after an admin types one on the new Settlement Links screen, so nothing about today's money changes on migrate day | drops the table, which erases every signed statement about which charge was paid by hand - the audit trail still says a declaration was made, but not what it named |
| 10 | `2026_10_18_000002_create_admin_permission_grants_table` (arrives with `6f14c4e8`) | `admin_permission_grants`: `user_id` indexed, `permission` (64) indexed, unique on `(user_id, permission)`, `granted_by_user_id` nullable, `note` (500) nullable, timestamps | **backfill**: 27 rows - one per permission slug - for **every account with `is_admin = true`**, except the ids configured as Master, each with `granted_by_user_id = NULL` and the baseline note, inserted `insertOrIgnore` in 200-row chunks. It writes no other table and edits no existing row; its size is 27 × the administrator count, so a box that wants that number first can take it as one read-only `COUNT(*)`, the same discipline as every other pre-migrate read in [production-checks.md](production-checks.md). So the deploy **starts** with every current administrator holding everything they hold today, and narrowing anyone is a later, deliberate act on the permissions screen | drops the table: the recorded decision of who holds which permission disappears, and it cannot be rebuilt from anything else in the database - only its audit history would remain |

All ten are additive: no column is narrowed, no row is deleted, no existing row is rewritten.
Rows 2 and 6 read existing rows to derive new ones, and row 10 does the same for administrators;
that is the only contact any of them has with data that exists today. That is what makes
checklist §10.3's "do not roll the database back" true for this range - and row 10 is the
sharpest case of it, because its `down()` discards decisions no other table records.

After checklist §1 runs, `php artisan migrate:status` gains **ten** `Ran` rows. None may
show `Pending`, and none may be absent.

**Row 10 has an order dependency the box must not miss.** Its backfill calls
`AdminPermissions::masterUserIds()` while it runs, which reads `MASTER_ADMIN_USER_IDS` and falls
back to `AUDIT_OWNER_USER_IDS`. Name the owner's id in `.env` **before** `migrate --force`, and
the owner's account is skipped as a Master; run `migrate` first and the owner's own account gets
27 baseline grant rows. Functionally that changes nothing - a Master holds all 27 implicitly
whatever the pivot says, and the screen refuses to edit a Master's rows - but it writes grant
history the permissions screen can never act on, and it is the kind of untidy first record the
owner would have to live with.

**The trap is the config cache, and it was measured here on 2026-10-17, not recalled.** This
host keeps `bootstrap/cache/config.php` between deploys (checklist §4 says so), and Laravel does
not read `.env` at all while that file exists - `migrate` included. Proof taken on the dev box
with a backup of its own `.env` and restored afterwards: with the cache present and
`AUDIT_OWNER_USER_IDS` **emptied in `.env`**, `config('audit.owner_user_ids')` still answered
`[1]`; only after `config:cache` rebuilt the file did it answer `[]`. So the value has to be in
`.env` **and the config cache has to be rebuilt before `migrate --force` runs**, or the backfill
reads the previous deploy's list. Checklist §1.3 now carries that one line; §4's closing
`optimize` is still what makes the value live for the site.

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
(approval row A6). It has been done once more since, **on `6f14c4e8`**, on the same isolated 8.4.3
harness: **12 passed / 110 assertions / 18.29 s**, 27 tables all InnoDB, every one of the tree's 35
migrations applied, scratch database counted back to zero - and a third time since, **on the runtime
head `1e33de76`**, at **12 passed / 110 assertions / 12.77 s** (§2d carries that run, including the
way the first attempt failed and what it exposed about a stale scratch datadir). What remains open is the same twelve on **MariaDB 10.6.28**, the version the
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
   on this head: `config/audit.php:49` declares the flag, and **no code anywhere reads it** -
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
   change, not a `.env` edit. The stale comment in `config/audit.php` saying the migration
   installs triggers **was corrected in `6f14c4e8`** (section 2c), which is what the owner's §4
   instruction asked for; the file is under `config/`, so that commit is runtime-touching.
   Re-measured on this head: `grep -rn database_triggers app config database tests routes` matches
   only the inert declaration in that file and nothing that reads it.
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

## 2c. `6f14c4e8` - the granular permission set, the settlement declarations, and the phone-board evidence

Written 2026-10-17, the day it was committed. **One commit, 44 files (+3,756 / −152), 42 of them
under the runtime prefixes, plus `.env.example` and `phpunit.xml`.** It was **pushed for review the
same day** - `git ls-remote origin` answers `94c5b4b71e827f42708bd49db281266c28abe916` for the review
branch and `git merge-base --is-ancestor 6f14c4e8 94c5b4b7` exits 0 - which makes the diff readable,
not approved: it has still not been reviewed as a deploy target or run against anything but synthetic
data. It is one commit rather than three because
`routes/web.php` carries both the permission gates and the declaration endpoints - splitting it
would leave an intermediate commit whose router names classes that do not exist yet.

Six things in it are deploy decisions rather than code details.

1. **Administrative access stops being one flag.** 27 permission slugs (`app/Support/AdminPermissions.php`),
   an `admin_permission_grants` pivot (§2 row 10), and a `can_admin:<slug>` route middleware
   registered in `bootstrap/app.php`. **44 route definitions in `routes/web.php` now name the power
   they need**, which is the owner's rule that hiding a button is not sufficient: a typed URL and a
   replayed form post are refused by the same check the button would have been, with `403`, and each
   refusal is written into the activity trail as `admin.permission_denied` or `admin.access_denied`
   (`EnsureAdminPermission:48-74`). Four screens take an ANY-of list because one page really does
   hold several powers - the wallet adjustment route accepts `wallet.debit`, `wallet.credit` or
   `wallet.balance.set`; `/settings` accepts any of `prices.edit`, `provider.config`,
   `settings.change`.
2. **Nothing narrows on migrate day.** The backfill gives every account that already has
   `is_admin = true` all 27 slugs, so no administrator loses a screen they can reach today and the
   support-alert routing below keeps its current recipients. Removing a power becomes a visible act
   on the permissions screen, per account, recorded with who made it. That is the owner's "keep
   existing functionality working while preparing the migration", and the box should read it as
   **the deploy grants no restraint by itself** - the restraint begins the first time an edit is
   saved on that screen.
3. **Master Admin comes from configuration, not a column.** `config/admin.php`'s
   `master_user_ids` reads `MASTER_ADMIN_USER_IDS` and **falls back to `AUDIT_OWNER_USER_IDS`** when
   unset, so one `.env` value establishes both roles; when both are empty the layer fails closed and
   nobody is Master. A Master holds all 27 implicitly with zero grant rows, cannot be edited or
   demoted on the permissions screen, and is never replaced automatically - which is the mechanism
   behind the owner's "prevent accidental lockout": the door is held by `.env`, and `.env` is not
   reachable from the website.
4. **A manual settlement is now a signed statement about one charge.** §2 row 9's table holds the
   charge's own `id` or `flw_ref`, the hand credit it stands for, and who declared it. The money
   path reads it **before** the ownership gate (`WalletFundingService::chargeDealtWithBefore()`), so
   a declared charge that comes back is held as `manual_settlement_declared` and paid to nobody, and
   `creditHeldDepositTo()` refuses to release a hold while its declaration stands - only an explicit,
   audited retraction opens it. What the desk has to be told: this is the closed form of the gap
   approval row C9 recorded, and it closes it the way the owner's brief demanded - "any
   manual-settlement relationship must be explicitly declared and audited rather than inferred",
   never inferred from an amount, an account number or a customer match. The same commit fixes the
   other half of that gap: the older hand-settled marker matched *any* marked request on the wallet
   when the arrival carried no `tx_ref`, which was holding genuine new deposits behind unrelated
   help given months earlier. It now matches only when the arrival really carries the reference.
5. **Support alerts follow the permission, with a fallback.**
   `SupportBotController::supportRecipients:334-346` notifies the admins holding `support.manage`
   instead of every admin account; if **nobody** holds it, it still notifies every admin and logs a
   warning, so a permission the owner forgot to grant cannot make a customer's complaint vanish.
6. **No compiled asset changes, and the CSS is untouched.** `git diff --name-only 6f14c4e8^..6f14c4e8
   -- resources/css/app.css public/build` returns **nothing**, so checklist row A5's hashes
   (`app-k_-jzmjF.css`, `app-DXuAyQnv.js`) still describe what ships and §2 needs no rebuild. The
   phone-board work in this commit is **tests only** (`MobileAdminRecordsTest`, 6 methods / 100
   assertions) against the markup and stylesheet `69418773` already shipped.

Two smaller things the box should not be surprised by. `config/audit.php`'s comment claimed the
`admin_activity_logs` migration installs `BEFORE UPDATE` / `BEFORE DELETE` triggers; it does not and
nothing reads that flag, and the comment now says so - the correction the owner's §4 asked for, and
a text change to a `config/` file, so it makes this commit runtime-touching. And `phpunit.xml` pins
`AUDIT_OWNER_USER_IDS` and `MASTER_ADMIN_USER_IDS` empty for the same reason it already pins the
provider keys: in a fresh test database the factory's first user carries id 1, so a developer's own
`.env` value would silently make that user the owner and let the tests that prove the owner-only
boards are shut pass for the wrong reason. **Test file only - the running site never reads it.**

Evidence on **`6f14c4e8`** (the head this section describes, not the branch head), run 2026-10-17:
sqlite **561 passed / 12 skipped / 4,144 assertions / 0
failures** (`artisan test` prints passed and skipped separately, so the run is 573 tests in total -
state it that way rather than calling all 573 a pass; the 12 skips are the two-process InnoDB
proofs, which skip by design off MySQL), and those
12 re-run on the isolated MySQL **8.4.3** scratch instance - **12 passed / 110 assertions / 18.29 s**,
with `wallet_settlement_links`, `admin_permission_grants` and `admin_activity_logs` among **27 tables,
all 27 InnoDB**, `migrations` holding **35 rows against the 35 migration files in the tree**, and the
scratch database exact-counted (`COUNT(*)`, not the `information_schema` estimate) back to zero rows in
`users`, `orders`, `wallet_transactions`, `wallet_settlement_links`, `admin_permission_grants` and
`admin_activity_logs`. The instance was stopped and its datadir deleted. `gh`/Actions could not be
reached from this machine, so
the **MariaDB 10.6.28** run the live engine reports is still owed for `6f14c4e8` - see rows A6 and C5,
and no MariaDB figure may be quoted for `6f14c4e8`.

## 2d. `ee3bfef9` and `1e33de76` - the deposits that were being thrown away, and the buttons that were being shown

Written 2026-10-17, the day they were committed. Both are **local only**: `git rev-list --count
94c5b4b7..HEAD` answers 2 (plus this document's commit, making 3), and no push is authorized for
them. Neither runs anything on the live box, and neither was reached by a real provider call - every
test below fakes the gateway and spends money only in a synthetic database.

### `ee3bfef9` - 5 files, +697 / −24 (`app/Services/WalletFundingService.php`, two controllers, one admin view, one new test file)

This is the commit that answers the complaint "transfers arrived but balances did not update", and it
is a **behaviour change to the credit path**, so it is the one the owner should read first.

What was measured before it was written: three arrivals ended with a `Log::warning` and nothing a
human reads. (1) A transfer that landed **short** of what the funding request asked for returned 0
without recording anything; (2) a transfer the funding fee consumes entirely returned 0 - and before
the fee floor was traced, that same arrival wrote a `wallet_transactions` row with `status=success`
and a **zero** credit, which marks money banked that no wallet received; (3) a verified webhook for a
charge carrying no `tx_ref`, or for a virtual account no lookup recognises, was dropped in
`FlutterwaveController` before it reached the service at all.

Read the diff in this order, because the interesting part is the ordering inside the DB transaction:

```
git show ee3bfef9 -- app/Services/WalletFundingService.php
```

- the short-arrival refusal and the new fee-floor refusal sit **after** the already-success refusal
  and **before** `chargeDealtWithBefore()` / `depositAttribution()` / `claimCharge()` - so a held
  arrival never consumes its own global claim, which is what still lets an admin pay it later;
- both refusals **hold** (write `flutterwave_held_deposits`) only when `!$ownershipApproved`; the
  fee-floor refusal itself is unconditional. Those two gates are the two defects found in this own
  change while the tests were being written, and they are pinned individually: an admin-approved
  release of a landed-short transfer now banks what actually landed (245,000 kobo of a ₦2,500
  arrival against a ₦3,000 request), and an approval can no longer resurrect the zero-credit success
  row, because an approval names a customer, it does not create kobo;
- every held write goes through one new private `writeHeldDeposit()`, keyed on the existing
  `charge_token` unique index with `insertOrIgnore`, so webhook + sweep + a direct call for the same
  charge leave **one** row. Four new `reason` values: `amount_below_requested`, `amount_below_fee`,
  `no_matching_customer`, `no_reference_in_webhook`.

What must **not** change, and is proven not to: a genuine full-size deposit still credits 295,000 of a
300,000 gross charge with the held board **empty**; a second transfer into a permanent virtual account
whose earlier transfer is held still credits in full; and a short arrival leaves
`flutterwave_charge_claims` at 0 rows, so the deposit can still be finished by the ordinary path. That
is the owner's rule made executable - *do not solve duplicate credits by breaking genuine new wallet
funding*. `tests/Feature/FlutterwaveDepositRecordingTest.php`, 11 tests / 73 assertions, gateway
faked throughout.

Consequence for the admin screens: a hold with **no** candidate can now be resolved by naming a
customer, because there is no candidate list to constrain - the record says `named_without_candidates`
in its audit `changes` so the trail distinguishes it from releasing to a proven holder. A name that
matches no user is refused (`Rule::exists('users','id')`), and pressing *Credit this customer* on a
sub-fee hold credits nobody and tells the admin not to press it again.

### `1e33de76` - 7 files, +340 / −1 (six `admin/` views, two test files)

View markup only: no route, no middleware, no permission definition, no controller and no CSS changed.
Each screen now asks the same question the route already asks before it renders the action, so an
administrator who holds `customers.view` stops seeing *Fund Wallet*, *Reset Password*, *Admin
Controls* and the discount form - all four of which the server was already refusing with a 403 and an
`admin.permission_denied` row. Hiding is presentation; enforcement stays where it was.

```
git show 1e33de76 --stat
git show 1e33de76 -- resources/views/admin/users.blade.php
```

The reviewable part is the test, not the markup:
`tests/Feature/AdminUnavailableControlsAreHiddenTest.php` (7 tests / 46 assertions) walks every file
under `resources/views/admin`, extracts every `@can` / `@canany` slug and fails if any names a
permission that does not exist, then does the same for every `can_admin:` slug in `routes/web.php`. A
guard written against a typo'd slug is a gate that never opens, and that is the failure this catches -
27 guards and every route slug currently name real slugs.

Two readings the owner should have before approving: **denial audit is throttled, the refusal is not**
(`AdminActivityRecorder:110-114` caches per action+result+IP for 300 s, so four refused POSTs write
exactly one row - the test asserts one and says why), and **an admin made admin by writing
`is_admin` directly holds zero grants** while one created through `UserFactory` holds all 27. That
second fact is the real behaviour of `admin_permission_grants`, and it is why migration §10's baseline
backfill matters: without it, every existing administrator would deploy able to see nothing.

`tests/Feature/IdentityProfitTest.php` is the only pre-existing test edited, and only its slice
sentinel: the dashboard section it used as an end marker (`Reset Totals`) is now permission-gated, so
the slice ran to the foot of the page. It moves to `Admin Profit Calculator`, which is not gated. No
assertion about money changed.

### Evidence on this head

sqlite full suite: **579 passed / 12 skipped / 4,263 assertions**. The twelve InnoDB proofs on an
isolated MySQL 8.4.3 (port 3307, own datadir, `vtu_money_tests`, synthetic rows only): **12 passed /
110 assertions / 12.77 s**, with `information_schema` reading 27 tables and **all 27 InnoDB**, 35 rows
in `migrations` against the 35 files in the tree, and `COUNT(*) = 0` on every money table afterwards.
**No MariaDB number exists for this head** - that run is the owner's to start (approval row C5), and
pushing this code does not start it, because the workflow's push trigger is filtered to its own file.

## 3. The three backfills, in detail

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
this guard closes - are kept exactly as they are and claimed once.

**Administrator grants** (`2026_10_18_000002`). Reads `users` where `is_admin = true`, skips the
ids `AdminPermissions::masterUserIds()` names as Master, and writes **27 rows per remaining
administrator** - one for every slug - with `granted_by_user_id = NULL` and the baseline note, so
the record says "this came from the deploy, not from a person's decision". It creates nothing in
any other table and changes nothing in `users`. Its purpose is the opposite of a lockdown: without
it, every admin on the box would wake up with zero permissions and the site would be undriveable.
All three backfills chunk at 200
rows, so the run is seconds, not minutes, at this site's volume.

Checklist §8.3 already owns the verification: `flutterwave_charge_claims` must count **greater
than zero** on a box with funding history the moment `migrate` finishes. Zero means the
backfill found nothing to read and only new deposits are guarded. The grants row count is
checked the same way in §7.4: `admin_permission_grants` must be **27 × (the administrator count
minus any configured Master)**, and §2 row 10 explains why an owner id in `.env` before
`migrate` changes that product.

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
ten migrations `Ran`, §8.3's claims count greater than zero, and §7's smoke table.
