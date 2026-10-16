# Wallet funding and deposit reconciliation - what this path really does

Reviewed 2026-10-16 by reading the code, not by moving money. Every claim below carries a
file and line so it can be checked in a minute. Nothing here was changed by this review; the
one code change it argues for is a live-box setting, not a commit.

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
- a short transfer is **held, not credited** (`WalletFundingService.php:82-91`); an
  overpayment credits what actually arrived.

## 3. Can one transfer credit a wallet twice?

Three things stand between a replay and a second credit, and only two of them are strong:

1. `creditFlutterwaveCharge()` runs the whole decision inside `DB::transaction` and takes
   `Wallet:: ... ->lockForUpdate()->first()` (`WalletFundingService.php:52,64`) before it
   looks at anything;
2. `arrivalRow()` (`:255`) locks the matching credit rows and a `success` +
   `isSameCharge()` guard returns `0` for a replay (`:71-80`);
3. `wallet_transactions.reference` is `unique()`
   (`database/migrations/2026_01_14_190722_create_wallet_transactions_table.php:20`), so an
   insert of the same charge reference is refused by the database.

**The gap to know about:** there is **no unique index on the Flutterwave charge id**. A
pending row that the credit path *adopts* is UPDATEd in place (`:129-135`), not inserted, so
route 3 never fires for it and the only thing preventing a double credit on that row is
guards 1 and 2. On MySQL/InnoDB with `lockForUpdate` that is a real serialisation, and the
ten concurrency proofs in `tests/Feature/MysqlMoneyPathConcurrencyTest.php` exercise the
debit side of the same ledger. But it is a lock, not a constraint - it protects what runs
through this code and would not protect a hand-edited row or a future path that forgets the
lock. Worth a migration when this table is next touched: a unique index on
`(channel, meta charge id)` or a dedicated `flw_charge_id` column, so the database refuses
the second credit no matter who writes it.

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
(`WalletFundingService.php:396-407`) - and the webhook still answers `200`. A short transfer
is a warning only (`:83`). **There is no admin screen for either.** The only surface is the
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
`WalletFundingService.php:105` calls `$this->ledger->shift(...)`, and the balance is moved by
the database, not by PHP arithmetic - `WalletLedger.php:60`
`'balance' => DB::raw("balance + {$amountKobo}")` - inside the caller's transaction, together
with the `wallet_transactions` row. The `$lockedWallet->balance = $afterKobo` at
`WalletFundingService.php:149` is in-memory bookkeeping for the response, not the write.

## 7. What this review did not change, and why

- **No admin "stuck deposits" screen.** It is the right next feature, and it is a feature;
  the brief for this pass was to finish what exists, not to add surfaces.
- **No charge-id unique index.** Adding a column to a live money table is a migration, and
  a migration on this path wants the owner's explicit go-ahead and a deploy window.
- **No change to the 500-on-permanent-failure behaviour** (`:280`): a non-NGN event will be
  retried by Flutterwave forever. It is noise, not loss, and changing which status a provider
  retries on is exactly the kind of quiet behaviour change that should not ride along with an
  unrelated deploy.

## 8. The three live-box reads, in order, before trusting a missed deposit

All read-only. Do them before anyone is told a deposit will self-heal.

1. `grep -E '^FLUTTERWAVE_SECRET_HASH=|^FLUTTERWAVE_' .env | sed 's/=.*/=<present>/'` - and
   compare the value's length with the "Webhook verification hash" in the Flutterwave
   dashboard. Empty here means **no webhook credits anything** (section 2).
2. `crontab -l | grep -v '^#'` - a `schedule:run` line must exist. Without it the sweep in
   section 4 never runs and the webhook is the only route, which is the unreliable one.
3. `php artisan deposits:audit --days=30` - then read "Written off as never arriving". Any
   row whose account number nobody holds is money that cannot self-heal and needs a human
   with Flutterwave's dashboard open.
