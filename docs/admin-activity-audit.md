# Admin activity audit

What the trail records, what it deliberately does not, and where its protection is
only as good as the application.

Written for the owner, not for a compliance reader. Every claim below is backed by a
test in `tests/Feature/Admin/AdminActivityAuditTest.php` or by the code named in the
line.

## 1. What exists

| Piece | File |
| --- | --- |
| The table | `database/migrations/2026_10_17_000001_create_admin_activity_logs_table.php` |
| The model | `app/Models/AdminActivityLog.php` |
| The only writer | `app/Services/AdminActivityRecorder.php` |
| Owner gate | `app/Http/Middleware/AuditOwnerAccess.php`, `config/audit.php` |
| Request marker | `app/Http/Middleware/CorrelationId.php` |
| Login / logout / refused sign-in | `app/Listeners/RecordAuthenticationActivity.php` |
| Screens | `/admin/administrators`, `/admin/activity-log` |

Each row answers: who (`admin_user_id`, `admin_name`), what (`category`, `action`,
`description`), to which record (`target_type`, `target_id`, `reference`), how it
ended (`result` is one of `success`, `failed`, `denied`), why (`reason`), what
changed (`changes`), and the context (`correlation_id`, `ip_address`,
`session_hash`, `user_agent`, `created_at`).

`result` is the important column. A refusal and a failure are not folded into a
success, and a validation rejection is written as `failed` with only the names of the
fields that tripped it - so "the same admin five times in a minute on a money screen"
is visible as what it is.

## 2. What is never stored

Anything written through the recorder is dropped or masked before the INSERT, so the value never
reaches the table - not "stored then hidden", which would still leak with the database. Section 3
explains why a row can still get in without the recorder, and what the screen does about that;
the write-side rule below is the guarantee, and the read-side mask is a second net under it,
never a substitute for it.

- **Secrets by key name.** Any change key matching
  `password|secret|token|authorization|cookie|api[_-]?key|private[_-]?key|signature|pin|otp|cvv|cvc|session|remember|hash|payload`
  is removed. A settings save records the **keys** that changed, never their values;
  otherwise the trail would become a second copy of every gateway credential.
- **Long digit runs.** Nine or more digits, including grouped ones, are reduced to as many
  asterisks as there are hidden digits, then the last four: `12345678901` becomes `*******8901`
  and `5555 5555 5555 4444` becomes `************4444`. That covers NIN, BVN, card numbers
  written as `5555 5555 5555 4444`, and phone numbers written as `+234 801 234 5678`. This
  site's own references carry letters, so they survive intact and stay searchable - which is
  also why the rule cannot be applied to the `reference` column: a gateway id that ends in
  eleven digits would lose eight of them. Note that a space or a hyphen between digits does
  **not** end the run, so `20261017-000451` is treated as fourteen digits. That is the correct
  behaviour for a card number written in groups and the reason the exemption above exists.

**Where the rule is applied.** `AdminActivityRecorder` masks `description`, `reason` and
`changes` on the way in, and `activity-log.blade.php` applies the same call to the same three
columns on the way out. Two layers, one rule, so neither can hide what the other kept. The
read-side layer exists for rows that did not pass through the recorder at all - written straight
to the table by a migration, an import, or anyone holding the database password, which section 3
says is possible. `reference` is masked by neither, on purpose. The rule lives in one place,
  `App\Support\SensitiveFigures::mask`, and the recorder calls it rather than keeping
  its own copy - so the trail and the screen can never disagree about what counts as
  a secret. The same call now masks the admin boards that print many rows at once
  (see `docs/mobile-admin-audit.md`, section 4.5).
- **Typed search text.** Reading the ledger records which filters were used and how
  many rows came back, not what was typed into the box.
- **Session identifiers.** Only `HMAC-SHA256(session id, app key)` truncated to 32
  characters, enough to group one sitting's actions, useless as a cookie.
- **Passwords on a refused sign-in.** The listener never reads the event's
  credentials, so an attempt with a wrong password stores no address and no secret.
- Card keys for issued codes are counted, not copied.

## 3. Tamper protection: two separate layers

**Application level (in place).** `AdminActivityLog` sets `UPDATED_AT = null`, and its
`updating` / `deleting` model events throw. Writes go through
`DB::table(...)->insertOrIgnore()`, so the same request doing the same thing to the
same record twice cannot stack two entries - the unique `dedupe_key` swallows it.
Proven by `records cannot be edited or removed through the model` and
`the same event in one request is stored once`.

**Database level (NOT in place - decision required).** A model guard only binds code
that goes through the model. `UPDATE admin_activity_logs ...` in raw SQL, a migration,
a Tinker one-liner or any tool holding the database credentials bypasses it entirely,
and no PHP in this repository can prevent that. The real answers are database
triggers that reject `UPDATE`/`DELETE`, or a store the application's own credentials
cannot write to (an append-only replica, object lock on a daily export).

There is **no trigger DDL in this branch**, and this document previously said there was one
waiting behind a switch. Measured on this head: `config/audit.php:49` declares
`database_triggers` from env `AUDIT_DATABASE_TRIGGERS`, and **nothing reads it**. The
`admin_activity_logs` migration installs the table only. So the flag is a promise, not a feature,
and setting it in `.env` changes nothing - it is not a switch to approve, it is work not yet done
(trigger DDL, plus the `TRIGGER` privilege for the app's MySQL user, plus a deploy step a plain
`git pull` and `migrate` cannot reproduce). Until that work exists and is approved, the honest
statement is unchanged and now stated without the false comfort: **the trail cannot be edited by
anyone using the site, and it can be edited by anyone holding the database password.** The model
guard (`AdminActivityLog:60-69`) binds Eloquent writes; the recorder's own insert goes through
`DB::table()->insertOrIgnore()` (`AdminActivityRecorder:146`), which no model event sees. C1
(credential rotation) is therefore still the open security item on this release, and it is the only
current protection against a database-level rewrite of history.

## 4. Who can see it

`is_admin` is not enough. Any admin who could read the whole trail could learn exactly
what the others did and, if a bug ever let rows be hidden, would hold their history.
The two screens sit behind `audit_owner`, which allows only the ids listed in
`config/audit.php` → `owner_user_ids`, filled from `AUDIT_OWNER_USER_IDS`.

**That list ships empty, and it is empty on purpose.** Which account belongs to the
owner is a fact only the owner can state; guessing it from `is_admin`, the lowest id
or the first registration would hand the trail to the wrong person. Until the owner
names their user id, nobody can read the trail - including the account that writes
this code. Nothing is backfilled in the meantime: the rows accumulate and become
readable the moment the list is set.

Ordinary admins keep their own screens (orders, users, wallet, settings), and the
last-admin safeguard in `UsersController::updateAdmin` still refuses to demote the
only administrator. Self-promotion is refused too, so a member-level session cannot
walk into the admin area by editing its own row.

## 5. Money rules this module follows

- **No reason, no movement.** `fund-wallet` and `adjust-wallet` require
  `note` of at least 10 characters, validated before the balance is touched. Proven
  by the first three tests: the balance is unchanged and no ledger row exists.
- **The entry and the money commit together.** The audit write for a balance move
  happens **inside** the surrounding `DB::transaction`. If the trail cannot be
  written, the credit disappears with it - tested by dropping the table and showing
  the wallet never moves. The reverse is the bug this prevents: a row claiming money
  moved when it did not.
- **Refusals are written outside.** `failed` and `denied` rows use `recordQuietly()`
  after the rollback, and a recording failure can never break the action it
  describes.
- **This module moves nothing.** It reads, it writes its own table, and it changes no
  balance, no refund, no status and no historical reason. Every pre-existing ledger
  row and manual adjustment is left exactly as found. The five open gateway credits
  found in the C4 review are still open and still unpaid; nothing here pays them.
- **Hand credits now record how many gateway credits are still open on that wallet**
  (`open_gateway_credits`) so a reviewer can see the overlap. Changing the behaviour
  of that path is a money-path decision and needs the owner's approval first; it was
  not taken here.

## 6. Growth and limits

- Repeated identical observations are throttled (`throttle_seconds` per
  action/result/IP), so a crawler finding an admin URL or a page refresh loop cannot
  write a row per hit. The window is a recording rule, not a judgement - the attempts
  still happened.
- `changes` is capped at 40 keys, depth 4, and 300 characters per string value.
- Retention is **not** automated. Nothing deletes or archives these rows, because an
  automatic purge of evidence is worse than a big table. Setting a retention period is
  an owner decision; the table's indexes (`admin_user_id, created_at` and
  `category, created_at`) exist so an export by month is cheap.

## 7. The screens the trail is read on

Two rules shaped these pages, and both came from the owner's brief.

**A board that reads must not hold the decision.** The customer profile page used to carry the
"mark this gateway credit as settled" form inside its wallet ledger. That put a money decision
on a reading table, and only on whichever page of the ledger the admin happened to be looking
at. The decision now lives in its own shelf on the same page, "Open funding requests", which
lists **every** open credit on that wallet rather than the ones on the current page, says in
plain words that it credits nothing and debits nothing, and keeps the required 10-character
note. The ledger below it is read-only: no row on it can be clicked into a payment.

**One pager, one table generation.** `Illuminate\Pagination\Paginator::defaultView()` is set to
`components.pagination` in `AppServiceProvider`, so all 14 `->links()` call sites - including
the two audit screens - share a wrapping, 44px, brand-styled pager. The framework's own pager
put every page number on one unwrapping line, and the mobile shell hides sideways overflow, so
on a phone the later pages could not be reached at all. Both audit screens use
`x-records-table` (`components/records-table.blade.php`), the same markup as the Transaction
Explorer, so a wide board scrolls inside its own box instead of widening the page.

## 8. Known gaps, stated plainly

1. **`admin_activity_logs` has never been created on the production database.** The
   migration is prepared and proven against sqlite and against real InnoDB (MySQL
   8.4.3, 2026-10-17: the table exists on that engine and the scratch database was
   left empty); running it in production is the owner's call and part of the deploy
   checklist.
2. Broadcast's four "no usable channel" `422` aborts are HTTP aborts, not validation
   failures, so they are not recorded. The success and finish of a broadcast are.
3. A held-deposit release is recorded at the controller, one statement after the
   service's own transaction commits, rather than inside it. Reaching that
   transaction would mean editing the funding service, which the reviewed money-path
   proofs depend on untouched. The trade is documented rather than hidden: an
   astronomically unlikely crash between commit and record loses one line, and cannot
   move money.
4. A denied attempt from a signed-out prober is throttled per IP, so the first
   attempt per window per address is kept and the rest of the burst is not.
5. "Last activity" on the administrators page is the later of the recorded login and
   the newest trail row. Anything before the trail began shows `Not recorded`; the
   page never estimates.
