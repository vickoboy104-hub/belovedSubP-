# Deployment approval checklist - the one document the release decision reads

Everything the go/no-go needs, each row with a pass/fail state, and nothing re-asked: facts
the owner has already verified are recorded as PASS with their reading, and the only open
rows are the ones still genuinely unknown. Command blocks live where they were proven - in
[deploy-checklist.md](deploy-checklist.md) and [production-checks.md](production-checks.md) -
and are referenced here, never copied.

**State at 2026-10-17: NOT APPROVED.** Nothing has been deployed, migrated, rebuilt or
changed on production. This document exists so the decision is a reading of rows, not a
re-investigation.

**What changed in section A on 2026-10-17, and why four rows went back to open.** After this
checklist was finalized, three **runtime** commits landed under the owner's Part 1/Part 2/Part 3
approvals: `f2d40bd2` (a charge the wallet already dealt with is held instead of credited
twice), `3be00d41` (the append-only administrator activity trail, with a migration) and
`69418773` (the phone-usable admin boards plus the read-side figure mask). They are local to
this machine and unpushed. Every number in section A was re-measured against the new head
rather than adjusted by hand, and the honest result is that A1, A3 and A4 no longer say PASS
and A6 is only half true: what is live is 45 commits and eight migrations behind, not 33 and
seven, and the MariaDB run that closed C5 was executed on a tree that none of those three
commits is in. The twelve InnoDB proofs **have** been re-run on this head since, on MySQL
8.4.3 - that part of A6 is fresh; the live engine's own version is not. The
re-measurement also caught a stale pin in A1 - the row called `6db7a49b` the reviewed *runtime*
commit when it is a documentation commit; the true runtime anchor is `cd19810c`, and A1 now
carries it with both diffs printed.

## A. The build

| # | Row | State | Evidence |
|---|---|---|---|
| A1 | Deploy target is the reviewed branch head | **PENDING - three runtime commits sit above the reviewed code** | the last **runtime** commit on the reviewed, pushed branch is `cd19810c123c57e4cc53b122502d5527c30f8fe7`, and the rule holds for the remote head: `git diff --name-only cd19810c..46f0d8c3` lists **7 files, 0 of them outside `docs/` and `.github/`** (measured 2026-10-17; `46f0d8c3` is what `git ls-remote` answers). The row used to name `6db7a49b` as the runtime anchor - **that was wrong, and the error was harmless in one direction only**: `6db7a49b` is itself documentation-only, so the rule it carried still passed, just against a looser pin. The pin that matters is `cd19810c`. Above it sit three runtime commits the owner approved as work but has not yet reviewed as a deploy target: `f2d40bd2` (a charge the wallet already dealt with is held, never credited twice - 5 runtime files), `3be00d41` (append-only administrator activity trail - 33), `69418773` (phone-usable admin boards plus the read-side figure mask - 23); `git diff --name-only cd19810c..HEAD` therefore lists **49** non-docs files, which the rule reads as a **stop**. All three are **local and unpushed** - nothing left this machine without per-batch approval. **PASS again when the owner has read those three diffs and this cell records the new reviewed runtime SHA** |
| A2 | Fast-forward from what is live | **PASS, and re-run it on the box** | `git merge-base --is-ancestor 0935a7f7 HEAD` exits 0 and `0935a7f7..69418773` is linear (45 commits, measured 2026-10-17). Two conditions on that: the box can only fast-forward to what has been **pushed**, and `69418773` has not been (A1); and if any further commit lands, re-measure rather than trust this cell |
| A3 | Range content known and reviewed | **PENDING - known, not reviewed** | measured 2026-10-17 for `0935a7f7..69418773`: **45 commits, 152 files, +23,389 / −898**. The split, with the rule printed so it reproduces - a commit counts as runtime-touching when `git diff --name-only <sha>^..<sha>` names at least one file under `app tests database config routes resources bootstrap public`: **27 runtime + 18 documentation/CI-only**. [deploy-preflight.md](deploy-preflight.md) sections 1-8 review the 24 runtime commits that were in the range when it was written; **its new section 2b is the three that arrived after** (`f2d40bd2`, `3be00d41`, `69418773`), which are described there but not yet reviewed as a deploy target. The previously recorded "33 commits, 117 files, +18,126 / −640" was accurate for the narrower range up to `6db7a49b` - it had been labelled with `8992cbaa`, which is 32 commits; the number was right, the label was one commit short |
| A4 | All migrations in the range accounted for | **PENDING - eight, not seven** | `git diff --name-status 0935a7f7..69418773 -- database/migrations`: eight `A` rows. The seven preflight §2 describes are unchanged (five `create table`, two `alter table orders`). The eighth is `2026_10_17_000001_create_admin_activity_logs_table.php` - a sixth `create table`, additive and forward-only: it touches no money table, edits no existing row, stores no credential by design, and has no backfill. `migrate:status` on the box must therefore show **eight** Pending, not seven (section D step 4). Preflight §2 has been updated to name it |
| A5 | Compiled assets present and understood | **PASS - restated with the current hashes** | the range **swaps** the hashed pair rather than adding files: `app-BIpqTlIl.css` and `app-BXQwVUL5.js` are **removed by the checkout**, `app-k_-jzmjF.css` (187,639 bytes) and `app-DXuAyQnv.js` (106,512 bytes) are **added**, and `manifest.json` is rewritten. The CSS hash moved once more since the anchor (`app-D-vIop72.css` → `app-k_-jzmjF.css`) because `resources/css/app.css` gained the mobile rules; the JS bundle is byte-for-byte the file already accounted for. Same two-file shape, so preflight §5's straggler case and the window that closes it still apply unchanged. **What the new CSS actually delivers is not admin-only, and the owner should read this cell with that in mind:** the rules landed on shared classes and the shared `records-table` component, so three customer pages (`resources/views/vtu/manual-service.blade.php`, `resources/views/vtu/orders.blade.php`, `resources/views/wallet/transactions.blade.php`) and the customer drawer/modal chrome render at new dimensions on a phone - 44 px tap targets and a computed table `min-width`. No price, form, wording or money path is touched; `git diff --numstat cd19810c..HEAD` is **109/7** on `resources/css/app.css` and **44/9** on that component, and no customer view file itself is edited in the range. Covered by `tests/Feature/TransactionHistoryPresentationTest.php` (8 methods) on the customer side; [mobile-admin-audit.md](mobile-admin-audit.md) §1 states the widened scope |
| A6 | Suite green on the machine that built it | **PASS on sqlite, PASS on MySQL 8.4.3, still open on the live engine's own version** | sqlite on this head: **516 passed / 12 skipped / 3,846 assertions** (220.13 s, 2026-10-17). The 12 skips are the two-process InnoDB proofs, and they were **re-run on this head the same day**: isolated second instance, MySQL **8.4.3**, port 3307, database `vtu_money_tests`, synthetic rows only - **12 passed, 110 assertions, 28.57 s**, `evidence.php` afterwards showing 25 tables with 33 rows in `migrations` and **zero everywhere else**, including the new `admin_activity_logs`, so the eighth migration is exercised on real InnoDB too. The instance was stopped and port 3307 confirmed closed. What is still missing: the run that matters most is the one on **MariaDB 10.6.28**, the version the live box actually reports, and that route is the push-triggered GitHub Actions workflow - so it happens when and if this batch is pushed (row C5). Until then no MariaDB number may be quoted for this head, and the 481 / 3,615 figure this row used to carry is superseded |

## B. Production facts - owner-verified, closed, never re-asked

| # | Row | State | Value |
|---|---|---|---|
| B1 | Live commit | **PASS** | `0935a7f7a07c97cc4c0c076b3ee97b931fd6a1a7` |
| B2 | PHP (CLI) | **PASS** | 8.3.35 |
| B3 | Database engine | **PASS** | MariaDB 10.6.28, autocommit 1 |
| B4 | Isolation / lock timeout | **PASS** | REPEATABLE-READ / 50 s - the pair the concurrency proofs ran under (MySQL 8.4's defaults are the same two values) |
| B5 | Money-table engines | **PASS** | `users`, `orders`, `wallets`, `wallet_transactions` all InnoDB |
| B6 | Wallet integrity | **PASS** | 201 wallets, zero negative balances |
| B7 | Duplicate virtual account numbers | **PASS** | zero - no ambiguity queue for the held-deposits path on day one |
| B8 | Saved modes | **PASS** | `provider` = gsubz; NIN = automatic; BVN = automatic (₦160 / ₦80 per answered check - live money) |
| B9 | Flutterwave credentials | **PASS** | secret key present; secret hash present in Laravel `config()` |
| B10 | Scheduler | **PASS** | cron every minute; `wallet:sync-deposits` observed executing and completing |
| B11 | 30-day funding ledger | **PASS** | read 2026-10-16: 36 successful, 1 pending, 3 failed - the §6 baseline the post-deploy audit compares against. Re-read 2026-10-17: **1 pending + 4 failed**; the successful count was not re-read |
| B12 | Untracked paths | **PASS** | `server.js`, `tmp/` preserved; no collision with the range (preflight §6); never `git clean` |

## C. Open before GO

| # | Row | State | What closes it |
|---|---|---|---|
| C1 | Credential rotation (checklist §0.4) | **FAIL until done** | owner rotates GitHub, cPanel, FTP, DB, GSUBZ, ConfirmIdent, Flutterwave, SMTP - the one hard red gate |
| C2 | Remaining key presences (production-checks §3) | **PASS** | `provider_gsubz_api_key`, `nin_api_key`, `bvn_api_key`, `flutterwave_public_key`, `fixed_account_bvn` all present (owner-read 2026-10-17) |
| C3 | Log counts (production-checks §8) | **PASS** | Flutterwave webhook 28, `user not found` 7, `held for reconciliation` 0 (owner-read 2026-10-17) |
| C4 | The open Flutterwave credits characterized | **RESULTS IN - RUN 1 NOT DECISIVE** | The owner ran §11.6 read-only on 2026-10-17 over 1 pending + 4 failed rows; the five rows are recorded in the C4 summary below. Run 1 settled none of them, and §11.6 was widened the same day for three reasons: its charge-identity list omitted `flutterwave_id` and the stored `flutterwave_payload`, so a success row written under those names was invisible to it; it printed neither the channel nor the amount unit (`wallet_transactions.amount` is kobo), so a card failure and a bulk-closed virtual-account failure looked identical; and it answered only `yes`/`no`, when the honest answer for a row that never stored an identity is `not determinable`. `tx_ref` and `flutterwave_reference` stay **out** of the proof list, because a permanent virtual account reuses them for every transfer into it (`app/Services/WalletFundingService.php:624-629`). All three §11 commands were then re-proven verbatim as extracted from the document, against seven synthetic open rows and four success rows: all seven lines printed (the first row being a verified match, so the `break 2` rule holds), every bucket appeared, and a row sharing only account-scoped tokens came out as a clue rather than `settled`. Closing C4 needs one more read-only run of §11.6, plus the provider dashboard for any row still `not determinable` - an owner action outside the site, and not part of the deploy. Not a deploy blocker by itself: the new code can only credit a provable owner or hold a new arrival |
| C5 | MariaDB proof run | **PASS for the commit it ran, and that commit is no longer the target** | Closed 2026-10-17 by the owner-approved GitHub Actions route: a `mariadb:10.6` service container, disposable empty database `vtu_money_tests`, synthetic credentials only, `permissions: contents: read`, workflow fails itself on skips. Run 2 ([38999509204](https://github.com/vickoboy104-hub/belovedSubP-/actions/runs/38999509204), commit `f2cfaa5f`): `MariaDB version: 10.6.28-MariaDB-ubu2204` - the same 10.6.28 the live box reports - driver `mysql`, `Tests: 12 passed (110 assertions)`, zero skips. Run 1 failed on workflow ordering (artisan before `composer install`) and was repaired workflow-only, exactly as pre-authorized; no application code was changed. **Read the commit with the result:** `f2cfaa5f` is before `f2d40bd2`, which rewrote part of `WalletFundingService.php`. The engine and the version are proven adequate - that is what this row was for - but the run does not demonstrate anything about the tree now proposed for deployment. Row A6 carries the re-run as an open GO condition |
| C6 | ConfirmIdent billing for a failed check | **ADVISORY** | does not block the deploy; every ambiguous NIN lookup costs ₦160 until answered (checklist §9 item 3) |
| C7 | BVN retrieve-by-BMS agent code is configured on the box | **OPEN - a gap in the review, not new code** | The change itself is inside the reviewed range (`8eddc5a7`); what is new is that nobody had written down the live-box dependency it creates, so this row is the review catching up, not a new risk arriving | The live code (`0935a7f7`) took `agent_code` from the **customer's form** (`VtuController` validated it as `nullable|string|max:80` and passed it into `retrieveByBms`). The branch stops asking the customer and reads `setting('bvn_retrieve_agent_code')`, falling back to env `BVN_RETRIEVE_AGENT_CODE`, defaulting to empty - and an empty value means the field is **omitted from the provider payload**, not sent blank (`app/Services/BvnApi.php:42` reads it, `:79-80` omits it). Nobody has read the live setting, so it is unknown whether a value is stored there. **Until it is confirmed present, the first retrieve-by-BMS after the deploy is a provider call our side has never shaped.** Closure is one owner action, no money: open `/admin/settings` on the box, confirm the BVN agent-code field holds the account's own code (or put it in `.env`), and say so here. The reason this row exists at all is that a docs-only turn cannot see the live settings table, and a silent behaviour change on a paid flow must not be inferred from a diff |
| C8 | Who is allowed to read the activity trail | **OPEN by design - and the safe deploy leaves it open** | `config/audit.php` builds `owner_user_ids` from `AUDIT_OWNER_USER_IDS`, ships it empty, and `AuditOwnerAccess` answers **403 for every account including the first admin** until a user id is named. That is the standing instruction honoured in code ("do not guess which account belongs to the website owner"), so nothing here is broken - the trail is written regardless, the two screens are simply dark. It is listed because it is a decision the owner has not made, and because of two mechanics: the value must be in `.env` **before** checklist §4's `optimize` or the cached config keeps the empty list; and `AUDIT_DATABASE_TRIGGERS`, if the owner ever wants the database-level `BEFORE UPDATE`/`BEFORE DELETE` guards, has to be set **before** `migrate --force`, because a migration that already says `Ran` will not run again (preflight §2b) |

### C4 summary - the open Flutterwave credits, owner's read-only live run 2026-10-17

The baseline moved: the 2026-10-16 read was 1 pending + 3 failed, the **2026-10-17 read is 1
pending + 4 failed** (production-checks §6 and §11). Why the failed count moved is not
established from the two reads - the 30-day window slides, and nothing here claims a cause.

Five rows are open. Nothing about them was resolved by this run; the run is the record of what
the site's own database and logs can and cannot say. **No row below is evidence that money was
received, and no row is evidence that money was not received.**

| Ledger id | Status | Amount as reported | Wallet user | Same wallet+amount success? | Verified charge identity? | Log mentions | Bucket under the widened rules | What is still open |
|---|---|---|---|---|---|---|---|---|
| 4801 | failed | ₦500 (unit unconfirmed - see below) | 4 | **yes, 1** | not determinable (run 1's key list) | not quoted | `dashboard-check (clue)` | Is the success row this charge, or a separate retry by the same customer? Only a shared charge-scoped identity says "same money" |
| 4846 | failed | ₦1,500 (unit unconfirmed) | 4 | no | not determinable (run 1's key list) | 2 | `not determinable locally` or `manual-reconcile candidate`, depending on whether the row stores a charge-scoped token - run 1 did not print that | What the 2 log lines actually say (§11.3, read-only); then the provider dashboard |
| 5013 | **pending** | ₦1,000 (unit unconfirmed) | 112 | no | not determinable (run 1's key list) | not quoted | `pending-watch` | The account number on the row has **no holder today**, so a new arrival there becomes a held deposit, not a credit. Whether the sweep ever got an answer for this reference is a separate question (§11 note below) |
| 5055 | failed | ₦10,000 (unit unconfirmed) | 154 | no | not determinable (run 1's key list) | 1 | as 4846 | log line content, then the provider dashboard |
| 5064 | failed | ₦100,000 (unit unconfirmed) | 154 | no | not determinable (run 1's key list) | 0 | `not determinable locally` (no clue of any kind) | provider dashboard only - the site has nothing left to ask |

Three distinct customers are involved: wallet users **4** (two rows), **112** (one) and **154**
(two, and the two largest figures). No row was credited by this investigation, no refund was
made, nothing was edited, and no provider call was made.

**Why run 1 could not settle any row, and what run 2 fixes.** Run 1's command was corrected for
its loop bug on 2026-10-17 but still compared only `flutterwave_charge_id`, `id` and `flw_ref`.
The arrival code also writes `flutterwave_id` and stores the charge payload, whose `id` and
`flw_ref` sit **inside** `meta.flutterwave_payload`
(`app/Services/WalletFundingService.php:151-170`, compared at `:654-689`). A success row written
under those names was therefore invisible to run 1: its `no verified match` was partly an
artifact of the key list, not a finding about the money. Run 1 also printed neither the
**channel** nor the unit of the amount, and a `failed` row on `flutterwave` (a card attempt the
customer abandoned, rejected by the callback, or rejected by the site's own checks) is a
different event from a `failed` row on `flutterwave_virtual_account`, most of which the site
writes in bulk when a customer asks for a new account number
(`app/Http/Controllers/VirtualAccountController.php:311-318`) or 60 minutes after Flutterwave
answered "no transfer" (`app/Services/WalletDepositSync.php:270-280`).

**The amount unit is unresolved and matters.** `wallet_transactions.amount` is **kobo**
(`database/migrations/2026_01_14_190722_create_wallet_transactions_table.php:19`) and the funding
minimum is ₦100 (`app/Http/Controllers/FlutterwaveController.php:26`). The five figures above are
quoted as the owner reported them; whether each was read from the raw column or after dividing by
100 is not established by the report text, so none of them is written here as a confirmed naira
value. §11.1 and §11.6 now print `kobo 150000 = N1,500.00` on every line so run 2 cannot be
ambiguous. A release document should not quote any of these figures as money until then.

**Run 2 - what to do, and what it can decide.** Re-run §11.6's one command (read-only, no
provider call, no write). It now prints channel, both amount units, whether the sweep is still
asking, holder attribution, three kinds of clue, and a three-state identity verdict:
`yes` / `no (N checked)` / `not determinable`. It can close a row as **settled** (same-wallet
verified identity) or route it to **ownership-review** (verified identity credited elsewhere). It
cannot turn a `not determinable` row into a settled row - for those, the only remaining evidence
is the provider's own dashboard, which is an owner action outside the site and is not part of the
deploy.

**Bucket meanings.** `settled` = verified charge-scoped identity match to a successful row on the
**same wallet**, nothing owed. `ownership-review` = verified identity match but the successful
credit sits on a **different wallet** - the money was banked, and who owns it is the owner's
decision, never the command's. `dashboard-check (clue)` = a clue (same reference, same
wallet+amount within a day, same account number + amount, or a success sharing the row's
**account-scoped** `tx_ref`/`flutterwave_reference`) but no proven identity; the owner checks the
Flutterwave dashboard. `manual-reconcile candidate` = failed, the row does carry its own
charge-scoped identity, and no success row has it - the strongest negative the local records can
give; if the dashboard shows the charge completed, resolve after deploy by ordinary admin credit
with an audit note (not through Held Deposits, which does not backfill old rows). `not
determinable locally` = failed and the row never stored a charge identity, so the site can neither
prove nor disprove an arrival; **this is not a finding that nothing is owed**. `pending-watch` =
still pending; post-deploy audit re-run decides it, and the new code can only credit a provable
owner or hold a **new** arrival.

Account-scoped tokens are never treated as proof anywhere in this plan: a permanent virtual
account is issued once and every later transfer into it carries the same `tx_ref` and gateway
`reference` (`app/Services/WalletFundingService.php:624-629`), so matching them would call a
customer's second payment a replay of their first.

## D. The deploy, executed as written (deploy-checklist.md owns every block)

| Step | What | Pass looks like |
|---|---|---|
| 0 | Gates §0.1-§0.5 | all PASS - including 0.5's backup, made and verified in §1.0 **before the window opens** |
| 1 | Backup §1.0 | `mysqldump --version` answers MariaDB Dump 10.6.x; the dump command exits zero (1.0's guard deletes the file when it does not - a gzip filename alone never passes); live-vs-dump table counts equal; completion line present; scratch-DB restore with matching money-table row counts, scratch dropped after; filename recorded beside `last-good-sha.txt` |
| 2 | Window §1.1 | `down --retry=15`; opening time recorded; skipped only by an explicit owner choice |
| 3 | Pull §1.2 | `git status` clean except the two known `??` paths (`server.js`, `tmp/`) |
| 4 | Review + migrate §1.3 | `migrate:status` shows exactly **eight** Pending (A4 - the eighth, `admin_activity_logs`, arrives with `3be00d41`); `--pretend` read against preflight §2 (six `create table`, two `alter table orders`; the backfills are PHP logic and do not appear); `migrate --force` leaves eight `Ran`; `flutterwave_charge_claims` count > 0 - roughly B11's 36 successful rows expected, greater than zero required |
| 5 | Docroot §3 | copy loop prints nothing new to copy - nothing outside `build/` changed under `public/` this range |
| 6 | Finish §4 | `optimize`, then `up`; `/settings` answers 302, `/up` answers 200 |
| 7 | Smoke §7.1-§7.9 | every row PASS - including 7.8's exactly three schedule jobs and 7.9's offline audit matching the B11 baseline read **immediately before the window opens** (2026-10-17 read: 1 pending + 4 failed; the successful count was last read as 36 on 2026-10-16 and has not been re-read) |

## E. The decision rule

**GO requires, all at once:** every row of A and B PASS - **which as of 2026-10-17 they are
not**: B is closed, A2, A5 and the sqlite/MySQL half of A6 are, and A1, A3, A4 are open because
three runtime commits sit on top of the reviewed code, one of them a migration, while the
MariaDB 10.6.28 half of A6 waits on a push; C1 done; C2 and C3 answered and acceptable; C7
answered (one setting on the box, no money); C8 either consciously left empty or filled with the
user id the owner has named - an owner decision, never a guess; C5 closed for the engine and
re-run for the head (row A6 carries that); C4 read once more with the widened §11.6 command and
the disposition of every open row understood - which for a row that comes back `not determinable`
means recorded as an owner-reconciliation item with its bucket, not silently treated as nothing
owed; the pushed branch actually containing whatever head this document pins; and the owner's
explicit go-ahead. Anything less is **NO-GO**, and the response is
this document plus checklist §10 (rollback) - never an improvised fix on the box.

## What this checklist deliberately does not do

- **Re-ask anything in section B.** Those rows are closed with the owner's own readings;
  the standing instruction to stop requesting completed hosting checks is honoured here.
- **No provider calls, no test payments, no refunds or credits, no live row edits.** The
  investigation of the open ledger rows is production-checks §11, read-only by
  construction, and its resolution paths run after the deploy through the screens and
  sweeps the code provides.
- **No new feature work from this document.** It was written as the end of a milestone, and the
  milestone then grew: three further runtime commits arrived under the owner's Part 1, Part 2
  and Part 3 approvals, which is why four A rows are open again rather than closed. The rule
  still holds in the direction that matters - nothing in this file authorises code. Rows turn
  from PENDING to PASS by being **read and measured**, and the commits below them turn into a
  deploy target only by the owner naming the SHA in A1.
