# Deployment approval checklist - the one document the release decision reads

Everything the go/no-go needs, each row with a pass/fail state, and nothing re-asked: facts
the owner has already verified are recorded as PASS with their reading, and the only open
rows are the ones still genuinely unknown. Command blocks live where they were proven - in
[deploy-checklist.md](deploy-checklist.md) and [production-checks.md](production-checks.md) -
and are referenced here, never copied.

**State at 2026-10-17: NOT APPROVED.** Nothing has been deployed, migrated, rebuilt or
changed on production. This document exists so the decision is a reading of rows, not a
re-investigation.

**What changed in section A, and why it is still open.** After this checklist was finalized, four
**runtime** commits landed under the owner's approvals: `f2d40bd2` (a charge the wallet already dealt
with is held instead of credited twice), `3be00d41` (the append-only administrator activity trail, with
a migration) and `69418773` (the phone-usable admin boards plus the read-side figure mask) - those
three under Part 1, Part 2 and Part 3 - and `6f14c4e8` (the granular administrator permission system,
the explicit manual-settlement declaration, and the corrected `config/audit.php` comment) under the
§1-§8 brief. On 2026-10-17 the owner authorized a **development-only push** of the first three plus
their documentation: `git ls-remote origin` answers `e32744d45d10ccec3cdeb5cdcfea4db6e94ff288` for the
review branch, `main` is unchanged at `17cb6eec…`, and nothing was merged, deployed, migrated or
touched on the live box. **`6f14c4e8` is not in that push, and no push is authorized for it.** A push
makes diffs reviewable; it does not make them approved. Every number in section A was re-measured
against the new head rather than adjusted by hand, and the honest result is that A1, A3 and A4 read
PENDING and A6 is half true: what is live is 48 commits and **ten** migrations behind, not 45 and
eight, and the MariaDB run that closed C5 was executed on a tree that none of these four commits is
in. The twelve InnoDB proofs **have** now been re-run **on `6f14c4e8`** on MySQL 8.4.3 - 12 passed /
110 assertions, 27 tables and all 27 InnoDB, all 35 of the tree's migrations applied, the scratch
database counted back to zero with `COUNT(*)` - so that half of A6 is fresh; the live engine's own
version is not. The re-measurement also caught a stale pin in A1: the row used to call `6db7a49b` the
reviewed *runtime* commit when it is a documentation commit, and the true runtime anchor is
`cd19810c`, which A1 now carries with its diff printed. Separately, the owner and ChatGPT asked whether
**both** manual-credit paths are covered by the hold; measured against the live code they were not, and
`6f14c4e8` closes that with an admin-declared link - row **C9** carries the closed design and the
residue that stays by policy, and [wallet-funding-review.md](wallet-funding-review.md) §3d the
measurement behind it. The owner has also named his Master Admin account, which turns row C8 from an
unanswered question into a named id plus one read-only check on the live box.

## A. The build

| # | Row | State | Evidence |
|---|---|---|---|
| A1 | Deploy target is the reviewed branch head | **PENDING - four runtime commits sit above the code the owner reviewed, and the newest of them is not even pushed** | the last **runtime** commit the owner reviewed as a deploy target is `cd19810c123c57e4cc53b122502d5527c30f8fe7`. (This row once pinned `6db7a49b`; that was wrong - `6db7a49b` is documentation-only - and `cd19810c` is the anchor used everywhere since.) Measured 2026-10-17 against the local head: `git diff --name-only cd19810c..HEAD` lists **84** files, of which **72** carry one of the runtime prefixes `app tests database config routes resources bootstrap public`, 9 are under `docs/`, and 3 are `phpunit.xml`, `.env.example` and the MariaDB workflow file - the rule reads that as a **stop**. Four runtime commits are above the anchor: `f2d40bd2` (a charge the wallet already dealt with is held, never credited twice - 5 runtime files), `3be00d41` (append-only administrator activity trail - 33), `69418773` (phone-usable admin boards plus the read-side figure mask - 23) and `6f14c4e8` (the granular permission system and the manual-settlement declaration - 44 files, +3,756 / −152). Remote state re-read the same day: `git ls-remote origin` answers `e32744d45d10ccec3cdeb5cdcfea4db6e94ff288` for `codex/identity-first-jhtech-experience` and `main` is untouched at `17cb6eec82cd037d191c7965a90dd86a6777e3f9`; everything between `69418773` and `e32744d4` is documentation, so **the runtime head on the remote is still `69418773`** and `6f14c4e8` exists **only on this machine** (`git rev-list --count origin/codex/identity-first-jhtech-experience..HEAD` = 2: `98c96cb1` docs, `6f14c4e8` runtime). A push makes a diff reviewable and does not make it approved; no push has happened for this commit and none is authorized. **PASS again when the owner has read those four diffs - above all `6f14c4e8`, the one that decides who may touch a wallet - and this cell records the new reviewed runtime SHA** |
| A2 | Fast-forward from what is live | **PASS as a graph property, and re-run it on the box** | `git merge-base --is-ancestor 0935a7f7 HEAD` exits 0 and `0935a7f7..HEAD` is **linear**, re-measured 2026-10-17: `git rev-list --count` and `git rev-list --count --first-parent` both answer **48**, and `git rev-list --merges --count` is **0** - the checkout on the box moves history forward rather than merging, which is what makes the deploy order in checklist §4 safe to follow. The live box still runs `0935a7f7`. Two things qualify the row: the current head `6f14c4e8` is **not pushed**, so anyone deploying from `origin` today lands on `69418773` and gets a different tree than section A describes; and the standing rule is that a further commit re-measures this cell instead of inheriting it |
| A3 | Range content known and reviewed | **PENDING - known, partly reviewable, still not reviewed as a deploy target** | measured 2026-10-17 for `0935a7f7..HEAD` (`HEAD` = `6f14c4e8`): **48 commits, 177 files, +27,440 / −965**. The split, with the rule printed so it reproduces - a commit counts as runtime-touching when `git show --pretty=format: --name-only <sha>` names at least one file under `app tests database config routes resources bootstrap public`: **28 runtime + 20 documentation/CI-only**. [deploy-preflight.md](deploy-preflight.md) §1-8 review the 24 runtime commits that were in the range when it was written; **§2b** covers `f2d40bd2`, `3be00d41` and `69418773`, and **§2c** covers `6f14c4e8` - described there, not reviewed as a deploy target. Only the three in §2b are on the remote review branch, so reading `6f14c4e8` means reading it on this machine or after a separate authorization to push it. The earlier figures in this cell ("45 commits, 152 files, +23,389 / −898" for `0935a7f7..69418773`, and before that "33 commits, 117 files, +18,126 / −640") were correct for their own narrower ranges; each is now replaced by the measurement above, not adjusted by hand |
| A4 | All migrations in the range accounted for | **PENDING - ten, not eight** | `git diff --name-status 0935a7f7..HEAD -- database/migrations` is **ten `A` rows**, measured 2026-10-17. Eight call `Schema::create` (`service_forms`, `purchase_intents`, `document_filings`, `flutterwave_charge_claims`, `flutterwave_held_deposits`, `admin_activity_logs`, and the two from `6f14c4e8`: `wallet_settlement_links` and `admin_permission_grants`); one calls `Schema::table` on `orders` (`2026_10_15_000001_add_assignment_to_orders_table`); and `2026_10_15_000002_retire_saved_customer_notes_fields` contains **no DDL at all** - it rewrites the saved `service_forms` definitions, so reading it as "nothing to do" would leave retired questions on screen. Three of the ten carry a data step and are itemised in preflight §3. `migrate:status` on the box must therefore show **ten** Pending, not eight (section D step 4), and `migrate --pretend` must print **eight `create table`** statements plus those data steps (checklist §1.3). All ten are additive and forward-only; none edits a money row or a customer balance. The one order-dependency is `admin_permission_grants`: its backfill reads the Master Admin list, so the owner's user id must be in `.env` **and the config cache cleared before `migrate --force`** - proved, not recalled, in preflight §2 row 10 |
| A5 | Compiled assets present and understood | **PASS - restated with the current hashes** | the range **swaps** the hashed pair rather than adding files: `app-BIpqTlIl.css` and `app-BXQwVUL5.js` are **removed by the checkout**, `app-k_-jzmjF.css` (187,639 bytes) and `app-DXuAyQnv.js` (106,512 bytes) are **added**, and `manifest.json` is rewritten. The CSS hash moved once more since the anchor (`app-D-vIop72.css` → `app-k_-jzmjF.css`) because `resources/css/app.css` gained the mobile rules; the JS bundle is byte-for-byte the file already accounted for. Same two-file shape, so preflight §5's straggler case and the window that closes it still apply unchanged. **What the new CSS actually delivers is not admin-only, and the owner should read this cell with that in mind:** the rules landed on shared classes and the shared `records-table` component, so three customer pages (`resources/views/vtu/manual-service.blade.php`, `resources/views/vtu/orders.blade.php`, `resources/views/wallet/transactions.blade.php`) and the customer drawer/modal chrome render at new dimensions on a phone - 44 px tap targets and a computed table `min-width`. No price, form, wording or money path is touched; `git diff --numstat cd19810c..HEAD` is **109/7** on `resources/css/app.css` and **44/9** on that component. **One customer view file is edited in the range, and `6f14c4e8` is the commit that does it:** `resources/views/profile/show.blade.php` gains one line showing the signed-in customer their own `users.id` as "Your User ID" (owner §3), inside markup that already exists - no CSS, no layout, no field the customer can edit, and no other customer view changed (`git diff --name-status cd19810c..HEAD -- resources/views` lists eleven `admin/` files, three components and that one profile view). Covered by `tests/Feature/TransactionHistoryPresentationTest.php` (8 methods) on the customer side and `tests/Feature/Admin/UserIdentityDisplayTest.php` on the id display; [mobile-admin-audit.md](mobile-admin-audit.md) §1 states the widened scope |
| A6 | Suite green on the machine that built it | **PASS on sqlite, PASS on MySQL 8.4.3, still open on the live engine's own version** | sqlite on this head (`6f14c4e8`), re-run 2026-10-17: **561 passed / 12 skipped / 4,144 assertions / 0 failures** - 573 tests in total, and `artisan test` prints passed and skipped separately, so this row says 561 rather than calling all 573 a pass. The 12 skips are the two-process InnoDB proofs, which by design refuse to run on sqlite. Those twelve were then re-run **on this same head** rather than on an older one: isolated second instance, MySQL **8.4.3**, port 3307, own datadir, database `vtu_money_tests`, synthetic rows only - **12 passed, 110 assertions, 18.29 s**. An independent `information_schema` read afterwards showed **27 tables and all 27 InnoDB**, including the three tables this batch introduces (`admin_activity_logs`, `wallet_settlement_links`, `admin_permission_grants`), with **35 rows in `migrations` against the 35 migration files in the tree**, so every migration on this branch has been applied on real InnoDB; and exact `COUNT(*)` = **0** on `users`, `orders`, `wallet_transactions`, `wallet_settlement_links`, `admin_permission_grants` and `admin_activity_logs` - the scratch database was left as empty as it found it, counted with `COUNT(*)` rather than the `information_schema` estimate. The instance was then stopped (0 `mysqld` processes, 0 listening sockets on 3307, only `TIME_WAIT` residue) and its disposable datadir deleted, which deletes the one-time password with it. **What is still missing is the run on MariaDB 10.6.28**, the version the live box reports. Pushing will not produce it and never did: the workflow's push trigger is filtered to its own file (`.github/workflows/money-path-mariadb.yml`, `on.push.paths`), so an application-code push - even this one - starts nothing. `workflow_dispatch` is declared in that file and is the clean route needing no new commit, but pressing it needs a signed-in GitHub session, and neither route is available from this machine: the only `gh` on it (`C:\Users\vicko\bin\gh.exe`) is exactly the Grenam payload size and will not be executed, and the connected browser is signed out of GitHub. So this row waits on an owner action, not on a push - row C5 carries the two ways to start it. Until it runs, **no MariaDB number may be quoted for this head**, and the figures earlier versions of this cell carried (481 / 3,615, then 516 / 3,846) are superseded |

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
| C5 | MariaDB proof run | **PASS for the commit it ran, and that commit is no longer the target** | Closed 2026-10-17 by the owner-approved GitHub Actions route: a `mariadb:10.6` service container, disposable empty database `vtu_money_tests`, synthetic credentials only, `permissions: contents: read`, workflow fails itself on skips. Run 2 ([38999509204](https://github.com/vickoboy104-hub/belovedSubP-/actions/runs/38999509204), commit `f2cfaa5f`): `MariaDB version: 10.6.28-MariaDB-ubu2204` - the same 10.6.28 the live box reports - driver `mysql`, `Tests: 12 passed (110 assertions)`, zero skips. Run 1 failed on workflow ordering (artisan before `composer install`) and was repaired workflow-only, exactly as pre-authorized; no application code was changed. **Read the commit with the result:** `f2cfaa5f` is before `f2d40bd2`, which rewrote part of `WalletFundingService.php`. The engine and the version are proven adequate - that is what this row was for - but the run does not demonstrate anything about the tree now proposed for deployment. Row A6 carries the re-run as an open GO condition. **How to get that re-run** (2026-10-17, after the authorized push proved the path filter): the workflow is at `Actions → Money-path MariaDB 10.6 concurrency tests`; either press **Run workflow** and choose the branch (`workflow_dispatch` is already declared in the file, so this needs no commit and changes no code), or authorize one CI-only commit whose only change is that workflow file, which fires the push trigger by matching its path. Both start the identical job: `mariadb:10.6` service container, disposable `vtu_money_tests`, synthetic rows only, `permissions: contents: read`. Neither touches the live box. This machine can start neither - see A6 |
| C6 | ConfirmIdent billing for a failed check | **ADVISORY** | does not block the deploy; every ambiguous NIN lookup costs ₦160 until answered (checklist §9 item 3) |
| C7 | BVN retrieve-by-BMS agent code is configured on the box | **OPEN - a gap in the review, not new code** | The change itself is inside the reviewed range (`8eddc5a7`); what is new is that nobody had written down the live-box dependency it creates, so this row is the review catching up, not a new risk arriving. The live code (`0935a7f7`) took `agent_code` from the **customer's form** (`VtuController` validated it as `nullable\|string\|max:80` and passed it into `retrieveByBms`). The branch stops asking the customer and reads `setting('bvn_retrieve_agent_code')`, falling back to env `BVN_RETRIEVE_AGENT_CODE`, defaulting to empty - and an empty value means the field is **omitted from the provider payload**, not sent blank (`app/Services/BvnApi.php:42` reads it, `:79-80` omits it). Nobody has read the live setting, so it is unknown whether a value is stored there. **Until it is confirmed present, the first retrieve-by-BMS after the deploy is a provider call our side has never shaped.** Closure is one owner action, no money: open `/admin/settings` on the box, confirm the BVN agent-code field holds the account's own code (or put it in `.env`), and say so here. The reason this row exists at all is that a docs-only turn cannot see the live settings table, and a silent behaviour change on a paid flow must not be inferred from a diff |
| C8 | Who is allowed to read the activity trail | **DECIDED by the owner on 2026-10-17 - and the deploy still ships the screens dark until his id is in `.env`** | The owner named the account: Master Admin is `users.id` **1** (Victor Paul), with the rule attached that if any detail does not match, stop and report the mismatch rather than move the privilege - and the primary identifier is the database id, email being only a secondary check. **The mismatch exists on this development box and is reported, not resolved:** here id 1 is the seeder's placeholder account and the address the owner quoted belongs to a different row, which is expected in a copied fixture database but must not be assumed away on the live one. So the GO condition is one read on the production database before anything is written: `SELECT id, name, email, is_admin FROM users WHERE id = 1;` - if the name or email differs, stop and re-ask; never assign Master Admin to another account automatically. The owner's id is **not** put in a source file: `config/audit.php` ships `owner_user_ids` from `AUDIT_OWNER_USER_IDS` (empty) and `config/admin.php:43` takes Masters from `MASTER_ADMIN_USER_IDS`, falling back at call time to that same owner list, so the value goes in the box's `.env` only. Until it is there, `AuditOwnerAccess` answers **403 for every account including the first admin** and the two audit screens stay dark while the trail keeps being written - the safe deploy leaves them dark. **Two mechanics the owner's id now depends on:** the value must be in `.env` **before** `migrate --force`, because the `admin_permission_grants` backfill (`2026_10_18_000002`) reads the Master list to decide whom to skip, and **before** checklist §4's `optimize`, because while `bootstrap/cache/config.php` exists Laravel never reads `.env` again - measured: a live config cache made the `.env` value invisible (`[]`), `config:clear` restored `[1]`; see preflight §2 row 10. And `AUDIT_DATABASE_TRIGGERS` is corrected per §4 of the brief: `config/audit.php:49` declares the flag and **no code reads it** (a grep of `app config database tests routes` finds only the declaration), so it is not a switch awaiting approval - this batch's append-only guarantee is application-level only (`AdminActivityLog:60-69` throws on any Eloquent update or delete; a raw SQL statement by someone holding the database password is outside it, so row C1 is the only real protection there), and no database-level tamper protection is claimed anywhere. `6f14c4e8` carried the corrected comment |
| C9 | Are BOTH admin credit channels covered by the duplicate-credit hold | **CLOSED on the branch by `6f14c4e8` for any settlement whose charge identity can be named; one residue left and stated below. Local only - not pushed, not deployed** | The question was whether `admin_manual_credit` **and** `admin_wallet_adjustment` credits are both accounted for. Measured answer: **neither is visible to the hold, and the hold cannot be made to see them from the money side.** `chargeDealtWithBefore()` matches only `meta.manually_settled` on this wallet (`WalletFundingService:694-711`) or a `failed` gateway row carrying this charge's own identity (`:718-738`); `Admin/FundingRequestController::record:87-91` refuses to write that marker on anything except an open `flutterwave…` request row, and an admin credit is `status=success` the moment it is created, so the marker can never land on one. **The exact conditions under which an arrival is held rather than credited - there are only these two, plus two pre-existing refusals:** (1) this wallet holds a credit row carrying `manually_settled` and, when the charge has a `tx_ref`, that row's `reference` or `meta.tx_ref` equals it; (2) this wallet holds a `status=failed` credit row whose `flutterwave_charge_id` / `flutterwave_payload.id` / `flutterwave_id` / `flw_ref` equals this charge's - never matched on account number or funding reference. In both cases the money is written to `flutterwave_held_deposits`, paid to nobody, and the global claim is left free so an admin release still pays once. Proven on a throwaway sqlite (harness created, run, deleted): an adjustment credit already on the wallet → the same charge credited again, 295,000 kobo, 0 held; a hand credit → same; **a genuinely new charge into a wallet that had received an unrelated adjustment → still credited in full, which any fix must preserve**; a marked request → 0 credited, 1 held, reason `already_settled_manually`. The proposed closure is an **explicit admin-declared link** from a manual credit to the specific open request it settled - stored, audited, and then read exactly like the marker today - because matching on amounts or account numbers is forbidden by the standing rule and would block legitimate new deposits. §11.7 was widened the same day to count both channels separately, and its self-test with all nine printed lines is recorded there. Not a deploy blocker by itself: this batch does not make the manual path worse than live `0935a7f7` already is. **Closed on the branch by `6f14c4e8`, in exactly the shape this row proposed.** `wallet_settlement_links` (preflight §2 row 9) plus a Settlement Links screen let an admin holding `wallet.settlement.declare` name **one** charge by its own `id` or `flw_ref`, point it at the manual credit that already paid it - **either** `admin_manual_credit` or `admin_wallet_adjustment`, which is the coverage this row said was missing - record why, and the money path reads that signed statement before the ownership gate. Proven by `tests/Feature/ManualSettlementDeclarationTest.php` (**14 methods / 84 assertions**), holding both halves at once: a declared charge reported again is **held under `manual_settlement_declared` and credits nobody**, and a genuinely new charge into a wallet that received an unrelated hand credit **is still credited in full**. A declaration itself moves no money, cannot be signed against this site's own `reference`, is limited to one per charge, blocks that charge's held release while it stands, and its retraction is an audited act that returns the charge to the normal funding path. **The residue is deliberate and should be read as policy, not as a bug left in:** a declaration needs the charge's own id or `flw_ref` to be readable somewhere. Where the desk has only an amount and a date, no statement can be signed - matching on an amount or an account number is precisely what the standing rule forbids, because it would start holding genuine new deposits. That money goes to Held Deposits for a human, which is the path that already exists and is tested |

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
| 4 | Review + migrate §1.3 | **the owner's `users.id` is in `.env` and `bootstrap/cache/config.php` is absent before this step runs** - `migrate --force` is what executes the `admin_permission_grants` backfill, and it reads the Master Admin list from config, which a live config cache freezes (preflight §2 row 10, measured: cached `[1]` beat `.env`'s `[]`); then `migrate:status` shows exactly **ten** Pending (A4 - the ninth and tenth, `wallet_settlement_links` and `admin_permission_grants`, arrive with `6f14c4e8`); `--pretend` read against preflight §2 (**eight `create table`**, and the data steps are PHP logic that does not appear in `--pretend` output); `migrate --force` leaves ten `Ran`; `flutterwave_charge_claims` count > 0 - roughly B11's 36 successful rows expected, greater than zero required |
| 5 | Docroot §3 | copy loop prints nothing new to copy - nothing outside `build/` changed under `public/` this range |
| 6 | Finish §4 | `optimize`, then `up`; `/settings` answers 302, `/up` answers 200 |
| 7 | Smoke §7.1-§7.9 | every row PASS - including 7.8's exactly three schedule jobs and 7.9's offline audit matching the B11 baseline read **immediately before the window opens** (2026-10-17 read: 1 pending + 4 failed; the successful count was last read as 36 on 2026-10-16 and has not been re-read) |

## E. The decision rule

**GO requires, all at once:** every row of A and B PASS - **which as of 2026-10-17 they are
not**: B is closed, A2, A5 and the sqlite/MySQL half of A6 are, and A1, A3, A4 are open because
**four** runtime commits sit on top of the reviewed code and **three migrations** arrive with them
(`admin_activity_logs` with `3be00d41`; `wallet_settlement_links` and `admin_permission_grants` with
`6f14c4e8`). The first three of those four are **on the remote review branch** (pushed 2026-10-17,
development-only), so they can be reviewed against `origin`; `6f14c4e8` is **not pushed at all** and
can only be read on this machine unless the owner authorizes a push. Review of all four has not
happened, and the MariaDB 10.6.28 half of A6 waits on a
**hand-started Actions run**, not on a push, because that workflow's push trigger is filtered to its
own file (row C5 carries both routes); C1 done; C2 and C3 answered and acceptable; C7
answered (one setting on the box, no money); C8 - the owner has named the account (`users.id` 1), so
what is left is the read-only production check that row 1 on the live box really is his, then his id
in `.env` with the config cache rebuilt **before `migrate --force`**, and never a guess or a
privilege moved to another account; **C9's design is now on the branch as `6f14c4e8` and has to be
read and approved as a deploy target** - a declared link names one charge by its own id or `flw_ref`,
covers both admin credit channels, moves no money itself, and holds rather than credits a re-reported
charge, while a genuinely new deposit into a wallet that once received unrelated manual help must keep
crediting; C5 closed for the engine and
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
  and Part 3 approvals, and a fourth (`6f14c4e8`, the granular permissions plus the settlement
  declarations) under his §1-§8 brief - which is why A1, A3, A4 and the MariaDB half of A6 are
  open again rather than closed. The rule
  still holds in the direction that matters - nothing in this file authorises code. Rows turn
  from PENDING to PASS by being **read and measured**, and the commits below them turn into a
  deploy target only by the owner naming the SHA in A1.
