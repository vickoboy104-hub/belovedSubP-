# Deployment approval checklist - the one document the release decision reads

Everything the go/no-go needs, each row with a pass/fail state, and nothing re-asked: facts
the owner has already verified are recorded as PASS with their reading, and the only open
rows are the ones still genuinely unknown. Command blocks live where they were proven - in
[deploy-checklist.md](deploy-checklist.md) and [production-checks.md](production-checks.md) -
and are referenced here, never copied.

**State at 2026-10-17: NOT APPROVED.** Nothing has been deployed, migrated, rebuilt or
changed on production. This document exists so the decision is a reading of rows, not a
re-investigation.

## A. The build

| # | Row | State | Evidence |
|---|---|---|---|
| A1 | Deploy target is the reviewed branch head | **PASS** | remote `f2cfaa5f5351d5a28958b2265ee45900eaf99fc0`, ls-remote verified 2026-10-17 (pushed under the owner's per-batch approval); the three commits after the reviewed `6db7a49b` touch only docs and the CI workflow - `git diff --name-status 6db7a49b..f2cfaa5f` shows no application code; any later commit re-pins checklist §0.1 by sha |
| A2 | Fast-forward from what is live | **PASS** | `0935a7f7..8992cbaa` linear; `merge-base --is-ancestor` exits 0 |
| A3 | Range content known and reviewed | **PASS** | 33 commits (29 runtime + 4 documentation-only), 117 files, +18,126 / −640, re-measured 2026-10-17; the runtime content is [deploy-preflight.md](deploy-preflight.md)'s 29-commit review |
| A4 | All seven migrations present in the range | **PASS** | `git diff --name-status 0935a7f7..4daaaee9 -- database/migrations`: seven `A` rows, the exact set of preflight §2 |
| A5 | Compiled assets present and understood | **PASS** | 2 new hashed bundles (`app-D-vIop72.css`, `app-DXuAyQnv.js`) + rewritten `manifest.json`; the 2 old hashed files are **removed by the checkout** - preflight §5 (corrected 2026-10-17) covers the straggler case and the window that closes it |
| A6 | Suite green on the machine that built it | **PASS** | 481 passed / 12 skipped / 3,615 assertions on sqlite; the 12 MySQL-only proofs passed on real InnoDB (MySQL 8.4.3, 110 assertions); the same 12 proofs passed on MariaDB 10.6.28 - GitHub Actions `mariadb:10.6` service container, run 38999509204, 2026-10-17, zero skips (row C5) |

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
| B11 | 30-day funding ledger | **PASS** | 36 successful, 1 pending, 3 failed - the §6 baseline the post-deploy audit compares against |
| B12 | Untracked paths | **PASS** | `server.js`, `tmp/` preserved; no collision with the range (preflight §6); never `git clean` |

## C. Open before GO

| # | Row | State | What closes it |
|---|---|---|---|
| C1 | Credential rotation (checklist §0.4) | **FAIL until done** | owner rotates GitHub, cPanel, FTP, DB, GSUBZ, ConfirmIdent, Flutterwave, SMTP - the one hard red gate |
| C2 | Remaining key presences (production-checks §3) | **PASS** | `provider_gsubz_api_key`, `nin_api_key`, `bvn_api_key`, `flutterwave_public_key`, `fixed_account_bvn` all present (owner-read 2026-10-17) |
| C3 | Log counts (production-checks §8) | **PASS** | Flutterwave webhook 28, `user not found` 7, `held for reconciliation` 0 (owner-read 2026-10-17) |
| C4 | The 1 pending + 3 failed credits characterized | **PENDING - PLAN READY** | production-checks §11's read-only plan is corrected (same reference / same wallet+amount are clues, not proof; only matching charge identity is proof; old rows are not backfilled into held deposits). §11.6's one-shot command was corrected 2026-10-17: its match loop exited the whole targets loop (`break 3`), truncating the report after the first verified match; it now breaks only the inner loops, and a verified match credited to a **different wallet** buckets as `ownership-review` instead of `settled`. The corrected logic was proven on four synthetic rows - all four lines print, one per bucket. The actual ledger output has not been supplied yet, so the per-row buckets in the summary below are still blank. Does not block the deploy by itself once the results are read |
| C5 | MariaDB proof run | **PASS** | Closed 2026-10-17 by the owner-approved GitHub Actions route: a `mariadb:10.6` service container, disposable empty database `vtu_money_tests`, synthetic credentials only, `permissions: contents: read`, workflow fails itself on skips. Run 2 ([38999509204](https://github.com/vickoboy104-hub/belovedSubP-/actions/runs/38999509204), commit `f2cfaa5f`): `MariaDB version: 10.6.28-MariaDB-ubu2204` - the same 10.6.28 the live box reports - driver `mysql`, `Tests: 12 passed (110 assertions)`, zero skips. Run 1 failed on workflow ordering (artisan before `composer install`) and was repaired workflow-only, exactly as pre-authorized; no application code was changed |
| C6 | ConfirmIdent billing for a failed check | **ADVISORY** | does not block the deploy; every ambiguous NIN lookup costs ₦160 until answered (checklist §9 item 3) |

### C4 summary - the one pending and three failed credits

Run the three commands in [production-checks.md](production-checks.md) §11.1, §11.2 and §11.3, then fill this table. Nothing here changes a row; it only records the pre-deploy picture.

| Ledger id | Status | Amount | Wallet user | Account holders | Duplicate reference? | Same wallet+amount twin? | Same charge identity success? | Log lines present | Bucket |
|---|---|---|---|---|---|---|---|---|---|
| | | | | | | | | | |
| | | | | | | | | | |
| | | | | | | | | | |
| | | | | | | | | | |

**Bucket meanings.** `settled` = verified charge identity match to a successful row on the **same wallet**, nothing owed. `ownership-review` = verified identity match but the successful credit sits on a **different wallet** - the money was banked, and who owns it is the owner's decision, never the command's. `dashboard-check` = a strong clue (same reference or same wallet+amount within a day) but no proven identity; the owner checks the Flutterwave dashboard. `manual-reconcile` = failed, no proven match and no strong clue; if the dashboard shows the charge completed, resolve after deploy by ordinary admin credit with an audit note (not through Held Deposits, which does not backfill old rows). `pending-watch` = still pending with no proven match; post-deploy audit re-run decides it, and the new code can only credit a provable owner or hold a **new** arrival.

## D. The deploy, executed as written (deploy-checklist.md owns every block)

| Step | What | Pass looks like |
|---|---|---|
| 0 | Gates §0.1-§0.5 | all PASS - including 0.5's backup, made and verified in §1.0 **before the window opens** |
| 1 | Backup §1.0 | `mysqldump --version` answers MariaDB Dump 10.6.x; the dump command exits zero (1.0's guard deletes the file when it does not - a gzip filename alone never passes); live-vs-dump table counts equal; completion line present; scratch-DB restore with matching money-table row counts, scratch dropped after; filename recorded beside `last-good-sha.txt` |
| 2 | Window §1.1 | `down --retry=15`; opening time recorded; skipped only by an explicit owner choice |
| 3 | Pull §1.2 | `git status` clean except the two known `??` paths (`server.js`, `tmp/`) |
| 4 | Review + migrate §1.3 | `migrate:status` shows exactly seven Pending; `--pretend` read against preflight §2 (five `create table`, two `alter table orders`; the backfills are PHP logic and do not appear); `migrate --force` leaves seven `Ran`; `flutterwave_charge_claims` count > 0 - roughly B11's 36 successful rows expected, greater than zero required |
| 5 | Docroot §3 | copy loop prints nothing new to copy - nothing outside `build/` changed under `public/` this range |
| 6 | Finish §4 | `optimize`, then `up`; `/settings` answers 302, `/up` answers 200 |
| 7 | Smoke §7.1-§7.9 | every row PASS - including 7.8's exactly three schedule jobs and 7.9's offline audit matching B11's baseline (36 / 1 / 3) |

## E. The decision rule

**GO requires, all at once:** every row of A and B PASS (already true as of 2026-10-17);
C1 done; C2 and C3 answered and acceptable; C5 closed - **closed 2026-10-17**: the MariaDB
run executed on GitHub Actions (`mariadb:10.6` service container) and passed, 12 tests /
110 assertions / zero skips, on the same 10.6.28 the live box reports (row C5); C4's results
read and the pending row's disposition
understood; and the owner's explicit go-ahead. Anything less is **NO-GO**, and the response is
this document plus checklist §10 (rollback) - never an improvised fix on the box.

## What this checklist deliberately does not do

- **Re-ask anything in section B.** Those rows are closed with the owner's own readings;
  the standing instruction to stop requesting completed hosting checks is honoured here.
- **No provider calls, no test payments, no refunds or credits, no live row edits.** The
  investigation of the four ledger rows is production-checks §11, read-only by
  construction, and its resolution paths run after the deploy through the screens and
  sweeps the code provides.
- **No new feature work.** This document is the end of the milestone, not the start of
  one; the only commits after it should be documentation corrections and the rows above
  turning from PENDING to PASS.
