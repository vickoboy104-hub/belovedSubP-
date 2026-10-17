# Wallet funding and deposit reconciliation - what this path really does

Reviewed 2026-10-16 by reading the code, not by moving money. Every claim below carries a
file and line so it can be checked in a minute. Section 3 was a finding that has since been
fixed and tested; everything else describes behaviour as it stands, and the two things it
argues for that are *not* commits are live-box settings (section 8).

A customer's bank transfer becomes wallet balance by one of two routes:

- **the webhook** - Flutterwave tells this site the money landed
  (`/wallet/flutterwave/webhook-v2`, `routes/web.php` with CSRF exempted);
- **the sweep** - `php artisan wallet:sync-deposits`, every five minutes
  (`bootstrap/app.php:18`), which asks Flutterwave about transfers the webhook never reported.

The sweep exists because the webhook is not reliable: a 500 window on the site takes the
webhook down with it. So the second route is the safety net, and the safety net needs a cron
that nobody has yet proved exists on the live host (see
[deploy-checklist.md](deploy-checklist.md) section 5).

## 1. Two ways in, and what each one writes

| | card checkout | dedicated virtual account |
|---|---|---|
| started by | `FlutterwaveController::initialize` (`:23`) | `VirtualAccountController::assignTemporary` (`:41`) |
| reference | `FLW_` + 12 random chars (`:51`) | `FLW_TMP_` + 14 random chars |
| first row | `wallet_transactions`, `status = pending`, `channel = flutterwave` (`:53-60`) | `recordPendingTransfer()` - fails the customer's older pending VA row first (`:311-318`), then `pending`, `channel = flutterwave_virtual_account` (`:323-341`) |
| amount | **typed by the customer**, `min:100` naira, fee taken off at `:50` | `temporary_amount` (`:26`) |

The amount being customer-typed is not a hole: nothing is credited from this row. It is a
promise of what the customer means to send, and the credit only ever follows an event
Flutterwave says is `charge.completed` and that this site re-verifies (section 2).

## 2. What actually moves money

`FlutterwaveController::webhook()` (`:232`):

- **no `FLUTTERWAVE_SECRET_HASH`, no crediting.** `:234-237` answers `500 {'ok': false}` to
  every hook when the hash is unset. This is a configuration fact about the live box, and it
  is the single most likely reason a deposit would sit pending.
- the hash is accepted either as the `verif-hash` header or as an HMAC-SHA256
  `flutterwave-signature` (`FlutterwaveService.php:73-92`); a mismatch is `401` (`:243`),
  which makes Flutterwave retry.
- only `charge.completed` with a successful status moves money (`:254-256`); every other
  event is logged and answered `200` (`:267-272`).
- the payload is never trusted as-is: `resolveVerifiedWebhookChargeData()` (`:430`) asks
  `/v3/transactions/{id}/verify` and re-checks status and NGN currency (`:442-460`).
- a short transfer is **held, not credited** (`WalletFundingService.php:83-93`); an
  overpayment credits what actually arrived.

## 3. Can one transfer credit a wallet twice?

Three things stood between a replay and a second credit before this section, and only two of
them were strong:

1. `creditFlutterwaveCharge()` runs the whole decision inside `DB::transaction` and takes
   `Wallet:: ... ->lockForUpdate()->first()` (`WalletFundingService.php:60,73`) before it
   looks at anything;
2. `arrivalRow()` (`:594`) locks the matching credit rows and a `success` guard returns `0`
   for a replay (`:80-89`);
3. `wallet_transactions.reference` is `unique()`
   (`database/migrations/2026_01_14_190722_create_wallet_transactions_table.php:20`), so an
   insert of the same charge reference is refused by the database.

**The hole this section used to call "a gap worth a migration" was measured on 2026-10-16,
and it was worse than a race.** All three guards are scoped to *the wallet being credited*:
`arrivalRow()` searches `$wallet->transactions()` (`WalletFundingService.php:340`). The
wallet a charge is routed to is chosen by `handleVirtualAccountTransfer()`'s six lookups
(`FlutterwaveController.php:366-395`), and the ones keyed on an account number are `->first()`
on a column that is indexed but **not unique**
(`database/migrations/2026_04_06_000007_add_generic_virtual_account_fields_to_users_table.php:31`,
and editable on any customer by an admin at `app/Http/Controllers/Admin/UsersController.php:90`).
Two customers can hold one number, and then nothing anywhere says "this transfer has already
been paid out".

Measured, before the fix: one ₦3,000 transfer credited once by the webhook and once by a
second customer's own **Check deposit** button put **₦5,900** into two wallets. That is two
ordinary endpoints and no attacker; the test that caught it is now
`test_a_transfer_that_could_belong_to_either_customer_credits_neither` in
`tests/Feature/FlutterwaveChargeIdempotencyTest.php` - the guard has since been tightened so
that such a transfer pays nobody automatically (section 3b).

**What closes it.** `flutterwave_charge_claims`
(`database/migrations/2026_10_16_000001_create_flutterwave_charge_claims_table.php`) is one
row per charge identity with a **unique index**, taken inside the credit transaction before
the balance moves (`claimCharge()` at `WalletFundingService.php:319`, called at `:132`). Both
`id` and `flw_ref` are claimed, in a single insert statement, so an attempt that loses the
race leaves no half-claim behind that would lock a genuine deposit out of the wallet that
owns it. A payload Flutterwave described with neither identity is still credited - the claim
is a duplicate check, never a precondition for banking somebody's money. Only the unique
index is allowed to refuse: any other failure - a missing table, a dead link - is rethrown
(`isAlreadyTaken()` `:386`, the rethrow at `:371`), because filing a broken guard as
"somebody already had this money" would lose a real deposit inside an info log.
Deliberately **not** claimed: `tx_ref` and the account number, which repeat by design
(section 4).

Why a new table rather than a unique index on `wallet_transactions`: that ledger legitimately
holds many rows sharing one `tx_ref` for a permanent account, and an index added blind to live
data either fails to build or refuses real deposits. So the migration *reads* the ledger and
backfills one claim per identity it finds, which is what keeps a deposit banked before the
deploy from being banked again after it. Duplicates already in the ledger are left exactly as
they are and claimed once.

**Proofs.** sqlite: `tests/Feature/FlutterwaveChargeIdempotencyTest.php` - duplicate webhook
delivery, webhook then reconciliation, two wallets, one account number shared by two
customers, two genuine transfers into one permanent account, a payload with no charge
identity, a bad `verif-hash`, the backfill over rows that predate the guard, and a claims
table that cannot be reached - which fails the request loudly instead of filing a real
deposit as already paid. InnoDB: `test_one_verified_transfer_cannot_fund_two_wallets_at_the_same_time`,
where a **second operating-system process** (`tests/Concurrency/MysqlRivalChargeCredit.php`)
tries to credit the same charge into a different wallet while the first credit is still
uncommitted - MySQL makes it wait at the index and then refuses it. Whole suite on MySQL
8.4.3: **12 passed, 110 assertions** (re-run 2026-10-16 after section 3b went in), database
empty again afterwards. The backfill on real
MySQL json was checked separately, including a debit, a NULL meta and a duplicate token.

## 3b. Being paid once is not the same as being paid to the right customer

The claim in section 3 answers "has this charge already been banked?". It cannot answer "is
this the person the money belongs to?", because the wallet is chosen *before* the claim, by
`handleVirtualAccountTransfer()`'s six sequential `->first()` lookups
(`FlutterwaveController.php:367-394`). Four of them key on an account number, and
`users.virtual_account_number` is indexed but not unique and admin-editable
(`app/Http/Controllers/Admin/UsersController.php:90`). The sweep has the same exposure:
`WalletDepositSync::isOurDeposit()` (`app/Services/WalletDepositSync.php:495`) accepts a
charge whose account number matches the candidate even when the `tx_ref` differs.

**One gate, at the funnel.** Every arrival path - checkout callback, webhook, the customer's
Check deposit button, and the scheduled sweep - ends in
`WalletFundingService::creditFlutterwaveCharge()`, so the ownership decision lives there
rather than being copied into six lookups (`:108-126`, immediately **before** the claim at
`:132`). Before anything is banked, `depositAttribution()` (`:422`) asks who can prove this
arrival:

- the wallet's own credit rows carrying the charge's `tx_ref`, or the owner's own
  virtual-account `tx_ref` (`ownsReference()` `:486`) - a direct request is a
  tie the customer owns, so a shared number never freezes money its owner asked for;
- otherwise every user holding that account number (`accountHolders()` `:455`, read from the
  column, the temporary-account metadata, and ledger rows tagged with the number). More than
  one holder ⇒ `shared_account_number`; exactly one, and it is not this wallet's owner ⇒
  `wrong_wallet`.

When ownership is provable-but-not-this-wallet, or genuinely shared, **nobody is credited**
and the arrival is written whole to `flutterwave_held_deposits`
(`database/migrations/2026_10_16_000002_create_flutterwave_held_deposits_table.php`,
`holdUnattributedCharge()` `:517`) with the full charge payload, gross amount, account
number, reason, and the candidate user ids. The row is keyed by the same charge identity token
as the claim, with `insertOrIgnore`, so a webhook and a sweep competing over one transfer
leave **one** held record, not two. The ordering matters: a hold is taken **before** the
global claim, so a deposit a human later releases is not locked out by its own claim row.

**Reconciliation is a human action, on the recorded candidates only.**
`Admin/HeldDepositController` (`routes/web.php`, `admin.wallet.deposits.held`) lists held
deposits with the candidate customers and their balances; crediting requires choosing one of
those candidates and a note of at least 10 characters, and runs through the ordinary credit
path (`creditHeldDepositTo()` `:210`) - same fee, same ledger row, same notification, same
claim enforcement - with `ownershipApproved` set. That flag exists solely for this path: no
customer-facing or gateway-facing route can pass it. Dismissing writes `status = dismissed`
with a mandatory note and keeps the record; nothing is deleted. The queue count is a badge on
the admin quick-nav so pending volume is visible without leaving the page an admin is on.

`flutterwave_held_deposits` has **no backfill**, deliberately: a migration cannot un-pay a
credit that already happened, and inventing holds for historical rows would put money that is
already spent on an admin's to-do list.

**Proven on the engine money actually moves on.**
`MysqlMoneyPathConcurrencyTest::test_two_processes_hold_one_ambiguous_transfer_once` keeps the
first credit open and uncommitted while a **second operating-system process**
(`tests/Concurrency/MysqlRivalAmbiguousCredit.php`) arrives at the same charge for the other
candidate; the test asserts the rival was really seen standing at the held record, then that
one held row survives, no charge claim was taken, and neither balance moved. `INSERT IGNORE`
did not deadlock or double-write under that collision, which is the behaviour sqlite cannot
show.

**What this gate does not do.** It acts on *provable* ambiguity. A charge that carries no
account number and no reference this wallet can claim is not provably ambiguous, so it still
goes through the ordinary path - which is why making `users.virtual_account_number` unique (a
data decision for the owner, section 7) is the real end of this problem, not the gate.

### 3c. The gate for money that was already dealt with by hand (added 2026-10-17, owner-approved)

Both gates above answer "has this charge been **banked** before?". Neither can see a manual
settlement: `Admin/UsersController::fundWallet:176-215` books a credit with channel
`admin_manual_credit`, reference `ADMINFUND…` and no gateway identity at all, and it takes no
charge claim. So when a support desk pays a customer for a stuck transfer and the gateway later
reports that transfer as successful, every existing check reads "not yet paid" and the customer is
paid a second time. That is the one duplicate-credit path the code review found, and it needed a
behavior change, which the owner approved on 2026-10-17.

`WalletFundingService::chargeDealtWithBefore()` now runs before the ownership gate and before
`claimCharge`, and pays nobody in two cases:

- **`already_settled_manually`** - this wallet holds a credit row that an admin marked as settled
  (`meta.manually_settled`) and the arriving charge names that row's funding reference. The marker
  is written by `Admin/FundingRequestController` (`POST /admin/wallet-requests/{id}/mark-settled`,
  and `unmark-settled` to reverse it) with a mandatory note of at least ten characters, under a row
  lock. **The marking moves no money.**
- **`charge_previously_reported_failed`** - this wallet already holds a `failed` credit row
  carrying the same charge identity (`meta.flutterwave_charge_id` / `flutterwave_payload.id` /
  `flw_ref`). A charge that was once reported failed and now reports success is a historical
  failure, and the owner's rule is that historical failures are never replayed into a wallet
  automatically.

Either way the arrival goes to `flutterwave_held_deposits` with its own reason and nobody is paid,
and because the hold happens **before** `claimCharge` it leaves the global claim free - so the
admin can release the same held record later through the ordinary path and it pays exactly once.
Proven by `tests/Feature/FlutterwavePriorSettlementHoldTest.php` (10 tests, 50 assertions),
including the three the change must not break: a brand-new charge still funds with nobody consulted,
a second real transfer into a permanent account is still credited even when the first request closed
as failed, and the check only ever reads this wallet's own rows.

**The trade, stated plainly.** A permanent virtual account repeats its one funding reference, so a
marked request also holds genuinely new transfers into that account until an admin releases or
reopens it. Holding was chosen over crediting because a held deposit is written down, visible and
one click from correct, while a second credit is neither. Ordinary, unmarked funding is untouched.

## 4. What the sweep looks for, and when it stops

`WalletDepositSync::sweep()` (`app/Console/Commands/WalletDepositSync.php:69`):

- candidates are users with **pending** `flutterwave%` credits, plus **failed within 14
  days** (`ABANDONED_LOOKBACK_DAYS`, `:40,118-122`), then holders of rotated dedicated
  accounts (`:140`);
- per user it asks `/v3/transactions?tx_ref=...&status=successful`
  (`FlutterwaveService.php:56-60`, `settledCharges` `:422`) with a
  `/v3/transactions/{id}/verify` fallback (`:450`);
- a charge is only banked when it proves ownership - matching `tx_ref`, or an account number
  this site handed out (`isOurDeposit` `:495-503`);
- **`--limit=10` users per run, oldest first** (`:125`), so ten users every five minutes is
  the recovery rate;
- a lookup that succeeds but finds nothing, on a request older than 60 minutes, is written
  off as failed (`:49,270-280`), and a failed row leaves the candidate list after 14 days
  (`:383`).

**The two consequences that matter.** Pending rows never expire out of the window, so if the
cron has simply never run, the day it starts it will still find everything that is still
pending - that is the good news. The bad news is the 14-day edge: a deposit into a
one-time/rotated-out account that was written off and not carried in
`previous_virtual_accounts` metadata becomes invisible to the sweep after two weeks. That
money is not gone at Flutterwave; it is only unreachable by this safety net.

## 5. Where the drift goes, and what an admin can see

Two kinds of drift still stop at the log:

- a transfer into an account number **no user row holds at all** is logged and dropped -
  `Log::warning('Flutterwave virtual account transfer user not found.')`
  (`FlutterwaveController.php:397`) - and the webhook still answers `200`. There is no wallet
  to attach it to, so section 3b cannot hold it; it stays a log line plus a ledger gap.
- a transfer that arrives **short** of what the deposit asked for is a warning only
  (`WalletFundingService.php:92`).

What section 3b added is the admin screen for the third case - money that matched somebody,
but not provably the right somebody: `admin.wallet.deposits.held`, badged in the quick-nav.
The remaining surface is still the read-only CLI:

```bash
cd /home/beloveds/vtu
php artisan deposits:audit --days=30 --references=0
```

which cross-checks the ledger against Flutterwave and greps the log for the phrases above,
including `webhook: hash not configured` and `webhook: signature refused`
(`app/Console/Commands/AuditWalletDeposits.php:252,303`). Verified working on the development
copy on 2026-10-16; on that copy Flutterwave is unconfigured, so it printed the ledger half
and said so plainly rather than guessing.

## 6. The ledger itself is sound

Every credit goes through the same mechanism as every debit:
`WalletFundingService.php:148` calls `$this->ledger->shift(...)`, and the balance is moved by
the database, not by PHP arithmetic - `WalletLedger.php:60`
`'balance' => DB::raw("balance + {$amountKobo}")` - inside the caller's transaction, together
with the `wallet_transactions` row. The `$lockedWallet->balance = $afterKobo` at
`WalletFundingService.php:192` is in-memory bookkeeping for the response, not the write.

## 7. What this review did not change, and why

- **No unique index added to `wallet_transactions`.** The charge guard went into a new table
  (section 3) instead, because a real ledger already holds many rows that share one `tx_ref`.
  For the same reason the guard does not claim `tx_ref` or the account number.
- **No unique index on `users.virtual_account_number`.** The ownership gate (section 3b)
  refuses to guess, but the column itself still allows two customers to hold one number, and
  existing live data may already contain duplicates. Making it unique is a data migration the
  owner has to look at first - `docs/production-checks.md` section 7 measures how many rows
  would be affected. Until then the gate is the safety, not the fix.
- **No rewrite of `handleVirtualAccountTransfer()`'s six lookups.** They still pick the first
  user row they find, and they still decide which wallet is *asked about*; what they can no
  longer do on their own is decide who gets *paid*, because the gate re-checks ownership
  against all holders before the balance moves.
- **No change to the 500-on-permanent-failure behaviour** (`:280`): a non-NGN event will be
  retried by Flutterwave forever. It is noise, not loss, and changing which status a provider
  retries on is exactly the kind of quiet behaviour change that should not ride along with an
  unrelated deploy.

## 8. The five live-box reads, in order, before trusting a missed deposit

All read-only. Do them before anyone is told a deposit will self-heal.

1. `grep -E '^FLUTTERWAVE_SECRET_HASH=|^FLUTTERWAVE_' .env | sed 's/=.*/=<present>/'` - and
   compare the value's length with the "Webhook verification hash" in the Flutterwave
   dashboard. Empty here means **no webhook credits anything** (section 2).
2. `crontab -l | grep -v '^#'` - a `schedule:run` line must exist. Without it the sweep in
   section 4 never runs and the webhook is the only route, which is the unreliable one.
3. `php artisan migrate:status | grep -E 'flutterwave_charge_claims|flutterwave_held_deposits'` -
   both must read `Run`. If the claims table is missing, the duplicate guard in section 3 is
   not in the database and never has been; if the held-deposits table is missing, the gate in
   section 3b cannot write its record, and the insert will fail the request loudly rather than
   credit silently - which is the intended behaviour, but it means deposits stop landing until
   the migration runs.
4. `sqlite3`/`mysql` read of `select status, count(*) from flutterwave_held_deposits group by
   status` (or just open **Admin → Held Deposits**) - a non-zero `held` count is money the
   site has deliberately refused to guess about, and it is only safe once a human has looked
   at it.
5. `php artisan deposits:audit --days=30 --references=0` - then read "Written off as never
   arriving". Any row whose account number nobody holds is money that cannot self-heal and
   needs a human with Flutterwave's dashboard open.
