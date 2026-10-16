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
   `Wallet:: ... ->lockForUpdate()->first()` (`WalletFundingService.php:53,65`) before it
   looks at anything;
2. `arrivalRow()` (`:333`) locks the matching credit rows and a `success` +
   `isSameCharge()` guard returns `0` for a replay (`:72-81`);
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
ordinary endpoints and no attacker; the test that caught it is
`test_a_transfer_the_site_could_attribute_to_either_customer_credits_one_wallet` in
`tests/Feature/FlutterwaveChargeIdempotencyTest.php`.

**What closes it.** `flutterwave_charge_claims`
(`database/migrations/2026_10_16_000001_create_flutterwave_charge_claims_table.php`) is one
row per charge identity with a **unique index**, taken inside the credit transaction before
the balance moves (`claimCharge()` at `WalletFundingService.php:239`, called at `:98`). Both
`id` and `flw_ref` are claimed, in a single insert statement, so an attempt that loses the
race leaves no half-claim behind that would lock a genuine deposit out of the wallet that
owns it. A payload Flutterwave described with neither identity is still credited - the claim
is a duplicate check, never a precondition for banking somebody's money. Only the unique
index is allowed to refuse: any other failure - a missing table, a dead link - is rethrown
(`isAlreadyTaken()` `:306`, the rethrow at `:291`), because filing a broken guard as
"somebody already had this money" would lose a real deposit inside an info log.
Deliberately **not** claimed: `tx_ref` and the account number, which repeat by design
(section 4).

Why a new table rather than a unique index on `wallet_transactions`: that ledger legitimately
holds many rows sharing one `tx_ref` for a permanent account, and an index added blind to live
data either fails to build or refuses real deposits. So the migration *reads* the ledger and
backfills one claim per identity it finds, which is what keeps a deposit banked before the
deploy from being banked again after it. Duplicates already in the ledger are left exactly as
they are and claimed once.

**Proofs.** sqlite: `tests/Feature/FlutterwaveChargeIdempotencyTest.php`, 10 tests / 41
assertions - duplicate webhook delivery, webhook then reconciliation, two wallets, one
account number shared by two customers, two genuine transfers into one permanent account, a
payload with no charge identity, a bad `verif-hash`, the backfill over rows that predate
the guard, and a claims table that cannot be reached - which fails the request loudly
instead of filing a real deposit as already paid. InnoDB: `test_one_verified_transfer_cannot_fund_two_wallets_at_the_same_time`,
where a **second operating-system process** (`tests/Concurrency/MysqlRivalChargeCredit.php`)
tries to credit the same charge into a different wallet while the first credit is still
uncommitted - MySQL makes it wait at the index and then refuses it. Whole suite on MySQL
8.4.3: **11 passed, 95 assertions**, database empty again afterwards. The backfill on real
MySQL json was checked separately, including a debit, a NULL meta and a duplicate token.

**What the guard does not do.** It does not decide *which* customer a shared account number
belongs to. The wrong customer can still be paid - now only once, and the unpaid one is
visible to `deposits:audit`. That is an attribution problem for a human, not money printed.

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

## 5. Where the drift goes: nowhere an admin can see

An unattributable transfer into a virtual account is logged and dropped -
`Log::warning('Flutterwave virtual account transfer user not found.')`
(`FlutterwaveController.php:397`) - and the webhook still answers `200`. A short transfer
is a warning only (`WalletFundingService.php:84`). **There is no admin screen for either.** The only surface is the
read-only CLI:

```bash
cd /home/beloveds/vtu
php artisan deposits:audit --days=30
```

which cross-checks the ledger against Flutterwave and greps the log for the phrases above,
including `webhook: hash not configured` and `webhook: signature refused`
(`app/Console/Commands/AuditWalletDeposits.php:252,303`). Verified working on the development
copy on 2026-10-16; on that copy Flutterwave is unconfigured, so it printed the ledger half
and said so plainly rather than guessing.

## 6. The ledger itself is sound

Every credit goes through the same mechanism as every debit:
`WalletFundingService.php:114` calls `$this->ledger->shift(...)`, and the balance is moved by
the database, not by PHP arithmetic - `WalletLedger.php:60`
`'balance' => DB::raw("balance + {$amountKobo}")` - inside the caller's transaction, together
with the `wallet_transactions` row. The `$lockedWallet->balance = $afterKobo` at
`WalletFundingService.php:158` is in-memory bookkeeping for the response, not the write.

## 7. What this review did not change, and why

- **No admin "stuck deposits" screen.** It is the right next feature, and it is a feature;
  the brief for this pass was to finish what exists, not to add surfaces.
- **No unique index added to `wallet_transactions`.** The charge guard went into a new table
  (section 3) instead, because a real ledger already holds many rows that share one `tx_ref`.
  For the same reason the guard does not claim `tx_ref` or the account number.
- **No change to which customer a shared account number belongs to.** `handleVirtualAccount-
  Transfer()` still picks the first user row it finds. Fixing attribution means making the
  column unique or forcing a manual match, and both are a data decision for the owner, not a
  code decision.
- **No change to the 500-on-permanent-failure behaviour** (`:280`): a non-NGN event will be
  retried by Flutterwave forever. It is noise, not loss, and changing which status a provider
  retries on is exactly the kind of quiet behaviour change that should not ride along with an
  unrelated deploy.

## 8. The four live-box reads, in order, before trusting a missed deposit

All read-only. Do them before anyone is told a deposit will self-heal.

1. `grep -E '^FLUTTERWAVE_SECRET_HASH=|^FLUTTERWAVE_' .env | sed 's/=.*/=<present>/'` - and
   compare the value's length with the "Webhook verification hash" in the Flutterwave
   dashboard. Empty here means **no webhook credits anything** (section 2).
2. `crontab -l | grep -v '^#'` - a `schedule:run` line must exist. Without it the sweep in
   section 4 never runs and the webhook is the only route, which is the unreliable one.
3. `php artisan migrate:status | grep flutterwave_charge_claims` - must read `Run`. If it
   does not, the duplicate-charge guard in section 3 is not in the database yet and has
   never been asked to be.
4. `php artisan deposits:audit --days=30` - then read "Written off as never arriving". Any
   row whose account number nobody holds is money that cannot self-heal and needs a human
   with Flutterwave's dashboard open.
