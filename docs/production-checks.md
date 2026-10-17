# Read-only production checks - for approval before anybody logs in

Every command below **reads**. Nothing here migrates, caches config, writes a setting,
touches a wallet row or prints a secret. Run them as the account user that owns the app,
from the app root (`~/vtu`), and paste the answers back. The point is to establish facts we
cannot determine from the development machine - not to change anything.

Two rules that these checks obey, and that any further checking must too:

1. **A value is never printed, only whether it exists.** Where a command could echo a key,
   it echoes the word `present` or `missing` instead.
2. **Nothing is "fixed" on the spot.** A check that shows a problem is a reason to come back
   here, not to improvise a command on a live server. The site went to HTTP 500 on 2026-10-11
   that way. See [deploy-checklist.md](deploy-checklist.md) §10 for the only approved change
   procedure.

---

## 0. Answers already received - the owner's live reads, 2026-10-16

Recorded so nobody re-asks them. Section references show where each question was asked.

| Fact | Answer |
|---|---|
| Live commit | `0935a7f7a07c97cc4c0c076b3ee97b931fd6a1a7` |
| `git status --porcelain` | the two known untracked paths, `server.js` and `tmp/`, and nothing else - preserved, never deleted or overwritten |
| `provider` | `gsubz` |
| `nin_verify_mode` | `automatic` |
| `bvn_verify_mode` | `automatic` |
| Flutterwave secret hash | present |
| Cron | `schedule:run` exists and fires every minute |
| `schedule:list` | `prices:sync`, `wallet:sync-deposits` - the third job arrives with the deploy |
| Engine | **MariaDB 10.6.28**, autocommit 1 |
| Money-table engines | `users`, `orders`, `wallets`, `wallet_transactions` all InnoDB |
| Duplicate virtual account numbers | **zero** |
| Isolation level | **REPEATABLE-READ** |
| Lock timeout | **50 seconds** |
| Wallets | **201, zero negative balances** |
| Flutterwave secret key | present |
| Flutterwave secret hash in `config()` | present - the cached-config failure mode in §3 does not apply |
| `wallet:sync-deposits` | observed executing and completing |
| 30-day Flutterwave credits | read 2026-10-16: 36 successful, 1 pending, 3 failed (§6's baseline). Re-read 2026-10-17: **1 pending + 4 failed** (§11); the successful count was not re-read |
| New migrations | not installed yet - expected before the deploy (§5) |
| PHP (CLI) | 8.3.35 |

**One of these answers still changes a plan.** The live engine is **MariaDB, not MySQL**: the
twelve money-path concurrency proofs passed against MySQL 8.4.3 on the development
machine. The follow-up reads closed most of the distance - isolation REPEATABLE-READ and a
50 s lock timeout are exactly the pair the proofs ran under - so the remaining delta is the
engine implementation itself. That delta is now closed (2026-10-17, approval row C5): the
owner approved a GitHub Actions run against a disposable `mariadb:10.6` service container -
synthetic `vtu_money_tests` data, no production contact - and run
[38999509204](https://github.com/vickoboy104-hub/belovedSubP-/actions/runs/38999509204)
passed on commit `f2cfaa5f` with `MariaDB version: 10.6.28-MariaDB-ubu2204` (the same 10.6.28
this box reports), driver `mysql`, **12 tests / 110 assertions / zero skips**. The MySQL 8.4.3
pass no longer needs to stand in for anything: the proofs have now run on the live engine's
own version. Tracked as checklist §9 item 4 and approval row C5.

---

## 1. Which code is actually live

```sh
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git status --porcelain | head -20
git log -1 --format='%h %cd %s'
```

**What the answer means.** The commit sha is what the site runs. Compare it with the remote
branch head (`4d3507fae0fef951c4b2ab4a87ccafbce9307328` as of 2026-10-17) - the live box is
expected to be **behind** it, and by how much decides whether a deploy is a fast-forward or a
conversation. `git status --porcelain` should be empty; anything listed is a hand-edit on the
server that a checkout will overwrite, and it must be read before it is lost - with the one
standing exception already read: `server.js` and `tmp/` are known, preserved untracked paths,
and no checkout in this range collides with either (preflight section 6).

## 2. The three saved settings that decide what the site can sell

```sh
php artisan tinker --execute="echo setting('provider'); echo PHP_EOL;"
php artisan tinker --execute="echo setting('nin_verify_mode'); echo PHP_EOL;"
php artisan tinker --execute="echo setting('bvn_verify_mode'); echo PHP_EOL;"
```

| Value | Meaning |
|---|---|
| `provider` = `gsubz` | VTU purchases can be sent. |
| `provider` = `mock`, blank or anything else | **Every VTU sale is refused** with "customers cannot buy right now", before any wallet is touched. That is the 2026-10-16 guard working, and it means live sales are stopped until the box is set. |
| `nin_verify_mode` / `bvn_verify_mode` = `manual` | An admin reads the provider result; the customer waits on a person. |
| either = `automatic` | A provider call runs on submit. Each answered check costs the owner ₦160 (NIN) or ₦80 (BVN), so this is a money setting, not a convenience one. |

An empty answer for the modes is not "off" - it means the default in
`app/Helpers/settings.php` applies, so report the blank and it will be read from the code.

One known, harmless side effect: the first `setting()` read in a fresh `tinker` process warms
the `settings.all` cache file under `storage/framework/cache`. That is a read-through of the
settings table, not a write to it - no setting changes, and nothing is printed beyond the one
value asked for.

**Answered (2026-10-16): `provider = gsubz`, `nin_verify_mode = automatic`,
`bvn_verify_mode = automatic`** - recorded in §0. The live box is automatic on both identity
paths, so every answered check is live money: ₦160 per NIN, ₦80 per BVN.

## 3. Are the provider and gateway keys configured (presence only)

```sh
php artisan tinker --execute='foreach (["provider_gsubz_api_key","nin_api_key","bvn_api_key","flutterwave_public_key","flutterwave_secret_key"] as $k) { echo str_pad($k, 26).(setting($k) ? "present" : "missing").PHP_EOL; }'
php artisan tinker --execute='echo config("services.flutterwave.secret_key") ? "secret_key present" : "secret_key MISSING"; echo PHP_EOL;'
php artisan tinker --execute='echo config("services.flutterwave.secret_hash") ? "secret_hash present" : "secret_hash MISSING"; echo PHP_EOL;'
php artisan tinker --execute='echo config("services.flutterwave.fixed_account_bvn") ? "fixed_account_bvn present" : "fixed_account_bvn MISSING"; echo PHP_EOL;'
grep -c '^FLUTTERWAVE_SECRET_HASH=' .env
```

**Read this pair together.** `config(...)` is what the running application uses; the
`grep -c` is what `.env` contains. `.env` having the hash while `config()` says `missing` means
the config is **cached** (`bootstrap/cache/config.php`) and the file is being ignored - which is
exactly how a webhook can look configured and still answer 500 to every event.

`secret_hash` missing means **every** Flutterwave webhook is refused, so deposits land only if
the five-minute sweep finds them. That single line is the most likely explanation for any
"I sent money and it did not reflect".

**Answered (2026-10-16): `flutterwave_secret_key` present, and the secret hash present in
Laravel `config()`** - the side that decides whether the webhook actually works, so the
cached-config failure mode above does not apply on this box.
**Answered (2026-10-17): `provider_gsubz_api_key`, `nin_api_key`, `bvn_api_key`,
`flutterwave_public_key`, and `fixed_account_bvn` are all present.**

## 4. Is the scheduler running at all

```sh
crontab -l | grep -v '^#'
php artisan schedule:list
ls -l schedule.log 2>/dev/null; tail -5 schedule.log 2>/dev/null
ls -l bootstrap/cache/
```

`schedule:list` is the code's promise and must show all three:

```
prices:sync  hourly
wallet:sync-deposits  every 5 minutes
manual:recover-documents  every 5 minutes
```

`crontab -l` is the reality. **No `schedule:run` line means no sweep** - a bank transfer whose
webhook was missed is never credited, and a paid order whose document failed to file is never
repaired. Both then look like the site took money and did nothing.

If `crontab -l` prints nothing, also check whether cPanel's own cron jobs list a
`schedule:run` entry (the page under *Advanced → Cron Jobs*), since hosting accounts sometimes
carry them outside the user crontab. Report which one it is; do not add either one from this
document.

**Answered (2026-10-16): the cron exists and fires every minute; `schedule:list` shows the
two jobs the old code knows.** The third row (`manual:recover-documents`) is the post-deploy
proof (checklist §7.8), and the log/heartbeat checks in the checklist's section 5 remain the
after-deploy confirmation.

## 5. Database: version, engines and migration state

```sh
php artisan tinker --execute='echo json_encode((array) DB::selectOne("select @@version as version, @@transaction_isolation as isolation, @@innodb_lock_wait_timeout as lock_wait_seconds, @@autocommit as autocommit")); echo PHP_EOL;'
php artisan tinker --execute='foreach (DB::select("select table_name as name, engine as eng from information_schema.tables where table_schema = database()") as $t) { echo $t->name." ".$t->eng.PHP_EOL; }' | grep -Ei 'wallets|wallet_transactions|users|purchase_intents|document_filings|flutterwave|referral'
php artisan tinker --execute='echo json_encode((array) DB::selectOne("select count(*) as wallets, sum(case when balance < 0 then 1 else 0 end) as negative from wallets")); echo PHP_EOL;'
php artisan migrate:status | tail -25
```

**Answered in full (2026-10-16): MariaDB 10.6.28, autocommit 1, isolation
REPEATABLE-READ, lock timeout 50 s; the four money tables InnoDB; 201 wallets, zero
negative balances.** Nothing from this section is still open. The MariaDB-not-MySQL point
is §0's plan-changer, and the isolation/timeout pair matches the conditions the
concurrency proofs ran under.

**Why each one matters.**

- The concurrency proofs were run on MySQL 8.4.3 on a development machine. Hosting's engine
  version, its default isolation level and `innodb_lock_wait_timeout` are the conditions those
  proofs actually assumed; a `READ-COMMITTED` or a 10-second lock timeout changes what a losing
  race looks like.
- Any table listed above as anything other than `InnoDB` has **no row locks**, which means every
  atomic debit, every claim and every admin claim race silently does nothing on that table.
- `negative` must be 0. A negative balance is a symptom of a debit that did not go through the
  guarded path.
- `migrate:status` is read-only. Before a deploy the two money tables
  (`flutterwave_charge_claims`, `flutterwave_held_deposits`) are expected to be **absent**, not
  pending - they are added by the checkout, and their migrations have never run on this host.
  Report it as "not present yet", never run `migrate` to make the message nicer.

## 6. What the deposit ledger looks like right now

```sh
php artisan deposits:audit --days=30 --references=0
```

`deposits:audit` changes nothing and `--references=0` keeps it off the network entirely: no
Flutterwave calls, no cost. That promise is forced by a regression test
(`test_the_audit_with_zero_references_makes_no_http_call_at_all` in
`tests/Feature/WalletSupersededDepositRecoveryTest.php`), which runs the command with
Flutterwave fully configured and a pending deposit sitting on the ledger, and asserts that not
one HTTP request leaves the machine - a zero can never again be quietly clamped up to one live
call, which is what `->limit(max(1, $limit))` used to do. It prints credits by status,
everything still advertised as waiting, transfers written off as never arriving, and any
pending request whose account number belongs to no customer on the site.

**On code older than `cd19810c` this command is post-deploy only.** A box still running the
old audit clamps `--references=0` up to one real Flutterwave call, so until the new code is
live, take the pre-deploy ledger baseline with a count instead:

```sh
php artisan tinker --execute='echo json_encode((array) DB::selectOne("select count(*) as pending_flutterwave_credits from wallet_transactions where type = \"credit\" and channel like \"flutterwave%\" and status = \"pending\" and created_at >= now() - interval 30 day")); echo PHP_EOL;'
```

Record the numbers as they stand **before** a deploy. After a deploy, the same command is the
check that the new guards did not orphan somebody's money - and a row that was already wrong
last month is not something the new code can silently repair.

**The baseline is in (2026-10-16): 36 successful, 1 pending, 3 failed in the last 30
days.** The post-deploy audit compares against exactly these three numbers, and §11
characterizes the pending and failed rows now, so their future is a decision
rather than a surprise.

**Re-read by the owner on 2026-10-17: 1 pending and 4 failed.** One more failed row is in the
open set than on 2026-10-16. Why is not established from these two reads alone - the 30-day
window slides, so a new request may have failed, or an older failed row may have aged out while
another aged in; nothing here claims a cause. The successful count for the current window was
not re-read, so the "36 successful" half of the baseline is still the 2026-10-16 figure. The
five open rows are recorded per row in §11 and in `deploy-approval-checklist.md`'s C4 summary.

Two follow-up counts, both read-only, and both expected to fail before the deploy:

```sh
php artisan tinker --execute='echo Schema::hasTable("flutterwave_charge_claims") ? "claims table present" : "claims table not there yet"; echo PHP_EOL;'
php artisan tinker --execute='echo Schema::hasTable("flutterwave_held_deposits") ? "held table present" : "held table not there yet"; echo PHP_EOL;'
```

## 7. Duplicate account numbers - the attribution problem, measured

The wrong-wallet guard goes live with the next deploy; this measures how much ambiguity is
already sitting in the data, which decides whether the first day after the deploy holds a
queue of transfers or nothing at all.

```sh
php artisan tinker --execute='echo json_encode((array) DB::selectOne("select count(*) as shared_account_numbers, coalesce(sum(holders), 0) as customer_rows from (select count(*) as holders from users where virtual_account_number is not null and virtual_account_number <> \"\" group by virtual_account_number having count(*) > 1) as shared")); echo PHP_EOL;'
php artisan tinker --execute='foreach (DB::select("select holders, count(*) as account_numbers from (select virtual_account_number, count(*) as holders from users where virtual_account_number is not null and virtual_account_number <> \"\" group by virtual_account_number having count(*) > 1) as shared group by holders") as $r) { echo $r->holders." holder(s) on ".$r->account_numbers." account number(s)".PHP_EOL; }'
php artisan tinker --execute='echo json_encode((array) DB::selectOne("select count(*) as customers_with_an_account from users where virtual_account_number is not null and virtual_account_number <> \"\"")); echo PHP_EOL;'
```

**Nothing is changed here, and no account number is printed.** The aggregate counts are all a
routine check needs: whether the ambiguity exists at all, and how big it is. A duplicate number
is not automatically wrong - it is often the same customer's replaced one-time account recorded
twice, or a number an admin typed onto a support account. Each number that is genuinely shared
still needs a decision about who owns it, and that decision is the owner's, not a script's. If
the aggregate is not zero, the enumeration of the actual numbers is a separate deliberate step
to ask for - that list is live bank details, and it belongs in an ownership decision, not in a
routine check.

**Answered (2026-10-16): zero duplicate virtual account numbers** - `shared_account_numbers`
= 0, so there is no ambiguity queue waiting for the held-deposits path on day one. The
commands above stay for future runs, because the ambiguity can be created after the deploy
(an admin typing a number onto a second account, or Flutterwave reissuing one).

## 8. Are payments reaching the site at all

```sh
grep -c 'Flutterwave webhook' storage/logs/laravel.log
grep -c 'user not found' storage/logs/laravel.log
grep -c 'held for reconciliation' storage/logs/laravel.log
tail -c 200000 storage/logs/laravel.log | grep -o 'Flutterwave [a-zA-Z ]*' | sort | uniq -c | sort -rn | head
```

Read-only counts, no secrets in log lines. The first is how often a hook arrived; the second is
transfers that reached a number the site could not attribute - which today means money landed
and nobody was paid, and after the deploy means a held deposit. The third only exists after the
attribution guard is live.

**Partly answered (2026-10-16): the strongest scheduler evidence arrived outside the greps -
`wallet:sync-deposits` was observed executing and completing.**
**Answered (2026-10-17): `grep -c` counts are Flutterwave webhook 28, `user not found` 7,
held for reconciliation 0.** These are log-message counts, not necessarily unique payment
counts.

## 9. What NOT to run while doing these checks

- `php artisan migrate`, `migrate --force`, `migrate:rollback` - a deploy decision.
- `config:cache`, `optimize`, `optimize:clear`, `view:clear` - all of them change what the live
  site serves. §3 reads the config instead of rebuilding it.
- `composer install`, `npm run build`, `rsync --delete`, any `chmod -R`.
- `deposits:audit` **without** `--references=0` (it calls Flutterwave), and `wallet:sync-deposits`
  outright - that command credits wallets.
- Any provider check: no NIN/BVN test call, no Flutterwave test payment, no airtime probe. Each
  one costs real money or moves real money.
- `git checkout`, `git pull`, `git reset` on the server until §1's `git status` output has been
  read and any hand-edit accounted for.

## 10. Report back in this shape

Thirteen rows. As of 2026-10-17 every one of them has an owner-supplied answer, recorded in §0
and in `deploy-approval-checklist.md`'s sections B and C: the key presences (§3) are all present,
§8's log counts are in (webhook 28, `user not found` 7, `held for reconciliation` 0), and §6's
status counts were read 2026-10-16 as 36 / 1 / 3 and re-read 2026-10-17 as **1 pending + 4
failed** (the successful count was not re-read). What is left is not a hosting question: it is
§11's characterization of those five open rows, and for any row §11.6 still calls `not
determinable`, the provider dashboard - which is the owner's action, not the site's.

| Check | Answer |
|---|---|
| Live commit sha | |
| `git status --porcelain` | |
| `provider` | |
| `nin_verify_mode` / `bvn_verify_mode` | |
| Keys present/missing, each of the five | |
| `FLUTTERWAVE_SECRET_HASH` in `.env` vs in `config()` | |
| `crontab -l` line, or "none" | |
| `schedule:list` output | |
| MySQL version, isolation, lock timeout, autocommit | |
| Engines of the money tables | |
| Wallets with a negative balance | |
| `deposits:audit` totals (pending / written off / unattributed) | |
| Duplicate account numbers found (aggregate counts only) | |

Those thirteen answers are what turns "the code is ready" into "the deployment is a decision".

## 11. The pending and failed Flutterwave credits - a read-only investigation

The 30-day baseline (§6, read 2026-10-16) was 36 successful, 1 pending, 3 failed. The owner's
re-read on **2026-10-17** gives **1 pending and 4 failed**: the open set is now **five** rows -
ledger ids #4801, #4846, #5013, #5055, #5064 - and their per-row record is in
`docs/deploy-approval-checklist.md`, section "C4 summary". The successful-row count for the
current window was **not** re-read, so nothing below claims a number for it; the 36 is history
either way. Because the open set changed, the two commands that classify it were widened and
re-proven the same day (§11.2 and §11.6) - the first live run exposed that its identity-token
list was narrower than what the funding code writes. This plan characterizes the rows
**without asking Flutterwave anything, and without refunding, crediting or editing a single
row** - local database reads and log reads only. Run it before the
deploy; nothing in it changes anything, and its output is the pre-deploy picture the
post-deploy audit gets compared against. This is approval row C4, and it answers three
questions for each open row: is the reference a duplicate, is there a previous
successful payment that already settled the same money, and does the row need manual
reconciliation once the new code is live.

Nothing here prints an account number or a secret: rows are identified by ledger id, and
attribution is reported as user-id counts.

### 11.1 List the open rows with their attribution facts

```sh
php artisan tinker --execute='foreach (\App\Models\WalletTransaction::query()->where("type","credit")->where("channel","like","flutterwave%")->whereIn("status",["pending","failed"])->where("created_at",">=",now()->subDays(30))->orderBy("id")->get() as $t) { $meta = (array) ($t->meta ?? []); $acct = trim((string) ($meta["virtual_account_number"] ?? "")); $holders = $acct !== "" ? \App\Models\User::where("virtual_account_number",$acct)->pluck("id")->all() : []; $owner = DB::table("wallets")->where("id",$t->wallet_id)->value("user_id"); echo "#".$t->id." | ".$t->status." | ".($t->channel ?: "-")." | kobo ".$t->amount." = ".number_format(((int) $t->amount) / 100, 2, ".", ",")." N | ".$t->created_at->format("Y-m-d H:i")." | ref ".($t->reference ?: "-")." | wallet user ".($owner ?? "?")." | meta keys: ".implode(",", array_keys($meta))." | account-number holders: ".($acct === "" ? "not stored" : ($holders === [] ? "none" : implode(",", $holders))).PHP_EOL; }'
```

The channel is printed because two very different failures share the word `failed`: a card
checkout that the customer abandoned or that the gateway rejected
(`flutterwave`, `app/Http/Controllers/FlutterwaveController.php:162-191`), and a virtual-account
request that the site itself closes out in bulk - every pending transfer row on a wallet is
marked failed the moment the customer asks for a new account number
(`flutterwave_virtual_account`, `app/Http/Controllers/VirtualAccountController.php:311-318`), and
again 60 minutes after Flutterwave answered "no transfer"
(`app/Services/WalletDepositSync.php:270-280`). The amount is printed in kobo **with** the naira
figure, for the same reason as §11.6. `meta keys` names the metadata fields without printing any
value, which is how you can see at a glance whether the row ever had a gateway identity to match.

Read each line against three cases:

- **0 holders** - the account number on the row matches no customer. Post-deploy, an
  arriving transfer on that number becomes a held deposit, not a credit.
- **1 holder, and it is the wallet's own user** - ordinary: the row is simply unresolved,
  and post-deploy the sweep or the webhook settles it the normal way.
- **2+ holders, or 1 holder that is a different user** - ambiguous or wrong-wallet:
  post-deploy, exactly the case the held-deposits screen exists for.

### 11.2 Could the same money have been credited a second time?

`wallet_transactions.reference` carries a UNIQUE index
(`database/migrations/2026_01_14_190722_create_wallet_transactions_table.php:20`), so two rows
cannot share one at all. That makes this first query a **schema-integrity probe** rather than a
payment clue: an empty answer is what the index guarantees, and a non-empty answer would mean
the live table is missing the index - which would widen the double-payment question far beyond
what this document assumes, and would be reported to the owner before anything else.

```sh
php artisan tinker --execute='echo json_encode(DB::table("wallet_transactions")->selectRaw("reference, group_concat(distinct status) as statuses, count(*) as rows_total")->where("channel","like","flutterwave%")->where("type","credit")->whereNotNull("reference")->where("reference","<>","")->groupBy("reference")->havingRaw("count(*) > 1")->get(), JSON_PRETTY_PRINT); echo PHP_EOL;'
```

An empty answer says the index is doing its job. It says nothing about whether a customer was
paid for twice, because a successful retry is written under a **new** reference - which is what
the two checks below exist for.

A retry can also succeed under a **new** reference, which no reference-grouped query can see.
For each open row, count same-wallet, same-amount **successful** credits within a day
either side - a previous-successful-payment **clue**:

```sh
php artisan tinker --execute='foreach (\App\Models\WalletTransaction::query()->where("type","credit")->where("channel","like","flutterwave%")->whereIn("status",["pending","failed"])->where("created_at",">=",now()->subDays(30))->orderBy("id")->get() as $t) { $twins = \App\Models\WalletTransaction::query()->where("wallet_id",$t->wallet_id)->where("type","credit")->where("status","success")->where("amount",$t->amount)->whereBetween("created_at",[$t->created_at->copy()->subDay(),$t->created_at->copy()->addDay()])->count(); echo "#".$t->id." (".$t->status.", amount ".$t->amount."): same-wallet same-amount successes within a day: ".$twins.PHP_EOL; }'
```

A nonzero answer is a clue that a retry may have paid the same customer, but it is **not**
proof - two unrelated transfers can share a wallet and amount. Verify charge identity before
classifying the row as settled.

The **definitive** previous-payment check compares the **charge-scoped** gateway identity
tokens stored in `meta` against every successful credit in the same window -
`flutterwave_charge_id`, `flutterwave_id`, `flw_ref`, legacy `id`, and the same fields inside the
stored `flutterwave_payload`. The original three-key version could not see a success row written
under `flutterwave_id` or under the payload, which is most of what the arrival code stores
(`app/Services/WalletFundingService.php:151-170`). `tx_ref` and `flutterwave_reference` are
deliberately excluded: a permanent virtual account reuses them for every transfer into it, so
matching them would call two different charges the same money (§11.6 explains, with the code's
own comment at `app/Services/WalletFundingService.php:624-629`). This command prints only counts
and verdicts, never the tokens. A match exits only the two inner loops (`break 2`), never the
loop over the target rows, so each row always gets its own line:

```sh
php artisan tinker --execute='
$keys = ["flutterwave_charge_id","flutterwave_id","flw_ref","id"];
$payloadKeys = ["id","flw_ref"];
$tokens = static function (mixed $meta) use ($keys, $payloadKeys): array {
  $meta = (array) $meta;
  $payload = (array) ($meta["flutterwave_payload"] ?? []);
  $out = [];
  foreach ($keys as $key) {
    $value = $meta[$key] ?? null;
    if (is_scalar($value) && trim((string) $value) !== "") { $out[] = trim((string) $value); }
  }
  foreach ($payloadKeys as $key) {
    $value = $payload[$key] ?? null;
    if (is_scalar($value) && trim((string) $value) !== "") { $out[] = trim((string) $value); }
  }
  return array_values(array_unique($out));
};
$successes = \App\Models\WalletTransaction::query()
  ->where("type","credit")
  ->where("status","success")
  ->where("channel","like","flutterwave%")
  ->where("created_at",">=",now()->subDays(30))
  ->get();
foreach (\App\Models\WalletTransaction::query()->where("type","credit")->where("channel","like","flutterwave%")->whereIn("status",["pending","failed"])->where("created_at",">=",now()->subDays(30))->orderBy("id")->get() as $t) {
  $ids = $tokens($t->meta ?? []);
  $verdict = $ids === [] ? "not determinable (row stores no charge identity)" : "no (".count($ids)." checked, none credited)";
  foreach ($successes as $s) {
    $sids = $tokens($s->meta ?? []);
    foreach ($ids as $id) {
      if (in_array($id,$sids,true)) { $verdict = "yes"; break 2; }
    }
  }
  echo "#".$t->id." (".$t->status.", ".($t->channel ?: "-").", kobo ".$t->amount."): same-charge-identity success exists: ".$verdict.PHP_EOL;
}'
```

`yes` here is the only result that proves the same charge was already credited. `no (N checked)`
is a real negative for that exact charge within the window. `not determinable` says nothing
about the money at all - it says the row was written before any charge identity existed to
store, which is the normal shape of every card-callback failure and every virtual-account
funding request (§11.6 explains why, with the line references).

### 11.3 What the site itself said at the time - logs, not the provider

For each reference from 11.1, read the site's own record of the arrival: the webhook, the
sweep's verdict, any "user not found":

```sh
grep -c '<reference-from-11.1>' storage/logs/laravel.log
grep '<reference-from-11.1>' storage/logs/laravel.log | tail -5
```

How to read it: a `failed` credit row is the site's own record that **this request** did not
complete. It is not a record about the money. A failed row does **not** prove money was
received (the branch that writes it can be driven by the customer's browser, or by a gateway
lookup that never returned a verdict - `app/Http/Controllers/FlutterwaveController.php:162-181`),
and it equally does **not** prove money was not received: when a charge arrives for a request
row that is already `failed`, the funding code adopts only `pending` or `success` rows
(`app/Services/WalletFundingService.php:643-647`), so the money is banked under a **brand-new
success row with a different reference** (`:180-189`) and the failed request stays failed
forever. A still-`pending` row has either not been swept, or been swept and stayed
unattributable - a transfer that has not arrived, or one that arrived and could not be
attached to an owner. **Do not read `pending` as "the sweep asked and nothing was there."** The
sweep is deliberately small: up to 10 customers per run (`sweep(int $limit = 10)`), up to 8
references and 12 charges per customer, and a per-customer throttle on top
(`app/Services/WalletDepositSync.php:30-33`, `:69`, `:322`, `:385`, `:190-195`). A pending row
turns to `failed` only when the gateway actually answered "nothing on this reference" **and** the
row is older than 60 minutes (`:264-280`), so a pending row can simply be starved by those caps
rather than resolved. Whether a given pending reference was ever answered is therefore not
knowable from the ledger - and the sweep now runs from a cron every minute (verified 2026-10-16,
§4), so an old pending row has had many chances, not none.

### 11.4 Which rows become manual reconciliation candidates

Read 11.1's attribution case, 11.2's three checks and 11.3's log lines together. The rule is:
only a **verified charge identity** is proof that the same Flutterwave charge was paid - the
charge-scoped gateway tokens (`flutterwave_charge_id`, `flutterwave_id`, `flw_ref`, legacy `id`,
and the same fields inside the stored `flutterwave_payload`; see
`app/Services/WalletFundingService.php:654-689`). `tx_ref` and `flutterwave_reference` are
**account**-scoped: a permanent virtual account reuses them for every transfer, so they are
clues, never proof. Same reference, same wallet+amount or same account+amount is a clue too -
two different charges can share a reference typo, the same amount, or the same ever-present
virtual account number.

Also: migration `2026_10_16_000002_create_flutterwave_held_deposits_table` is forward-only and
does **not** backfill old `pending` or `failed` rows. So an old failed deposit will **not**
automatically appear in Admin → Held Deposits after the deploy. The held-deposits screen is
for **new** ambiguous arrivals; old rows are resolved through ordinary admin credit with an
audit note, not through that screen.

With that, each row lands in one bucket:

- **Verified charge identity match to a successful row** - the same Flutterwave charge was
  already credited. If that credit sits on the **same wallet**, nothing is owed; annotate
  only. If it sits on a **different wallet**, the money was banked but who owns it is an
  ownership decision - flag for review, do not label the row settled.
- **No verified identity match, but a clue** (same reference, same wallet+amount within a day,
  a same-account+amount success, or a success sharing the row's account-scoped gateway
  reference) - `dashboard-check (clue)`. Flag for the owner to check the Flutterwave dashboard.
  It is **not** closed.
- **Failed, carrying its own charge-scoped identity, and no success row has it** -
  `manual-reconcile candidate`. This is the strongest negative the local records can give: the
  charge was looked for by name and is not on record as credited. Whether Flutterwave is holding
  the money is
  still a dashboard question, not a site API question. If the dashboard shows the charge
  completed, resolve after the deploy through an ordinary manual credit with an audit note -
  never a hand-written SQL fix, never during the deploy itself, and not through the
  held-deposits screen (old rows are not backfilled there).
- **Failed, and the row stores no charge-scoped identity at all** - `not determinable locally`.
  The old code never persisted a verdict for this request, so the site's records can neither
  prove nor disprove an arrival, and a later transfer for the same money may have been banked
  under a different reference (§11.3). Only the provider's own dashboard can settle it. Do **not**
  read this bucket as "nothing was owed".
- **Pending, no verified match** - swept (or not yet swept) and still unresolved: either the
  transfer never completed (nothing owed) or it arrived unattributed, which the old code drops
  quietly. Post-deploy, §6's audit re-run answers it: the new code can only credit a provable
  owner or hold a **new** arrival for an admin. An old pending row is not backfilled into held
  deposits.

The one hard rule stands regardless of bucket: nothing in this investigation refunds, credits,
edits or deletes anything. It only sorts the open rows into the buckets above so the
post-deploy work has a list instead of a surprise.

### 11.6 One-shot privacy-safe C4 summary (widened 2026-10-17 after the first live run)

Run this single command on the live box. It prints the pending and failed rows in one line
each, never exposing the full reference, account number, customer name, email or phone. It
reports the channel, the amount in kobo **and** naira, how long ago the row was created and
last touched, whether the deposit sweep is still asking about it, attribution as a
holder-count case plus a coarse count of the customers whose own account metadata mentions
that account number, what the ledger said when it closed the row out, duplicate-reference
counts, three clues (same wallet + amount, same account number + amount, same account-scoped
gateway reference), the definitive charge-identity check, and log-line mentions. A verified
match whose credit sits on a
**different wallet** is bucketed `ownership-review`, never `settled`. On a match the two inner
loops exit via `break 2` - deliberately one level short of the targets loop, so every target
row always gets its line.

`wallet_transactions.amount` is **kobo**
(`database/migrations/2026_01_14_190722_create_wallet_transactions_table.php:19`), and the site
minimum is ₦100 (`app/Http/Controllers/FlutterwaveController.php:26`), so a raw column read is
100× away from the naira figure. Every line states both, because no row in this report should
be unit-ambiguous when it is quoted into a release document.

**What the definitive column can and cannot say - and why the first live run could not say it.**
The first version of this command compared only `flutterwave_charge_id`, `id` and `flw_ref`. The
arrival code writes more names than that (`app/Services/WalletFundingService.php:151-170`), so a
success row stored under a key the command never looked at could not be matched, and the
command's `no` was partly an artifact of its own narrow list rather than a finding about the
money. The widening also had to separate the two **kinds** of token Flutterwave issues, because
they do not mean the same thing:

- **Charge-scoped - proof.** `flutterwave_charge_id` (the charge's own `id`), `flutterwave_id`
  and `flw_ref` (the charge's `flw_ref`), the legacy top-level `id`, and the same two fields
  inside the stored `flutterwave_payload`. Flutterwave issues one of these to one charge, so two
  rows sharing one are the same money. These are the names `isSameCharge()` compares
  (`app/Services/WalletFundingService.php:654-689`).
- **Account-scoped - clue only.** `tx_ref`, `flutterwave_reference` and the payload's
  `reference`. A permanent virtual account is issued once and paid into as many times as its
  owner likes, and the code's own comment records that every one of those transfers carries the
  single reference the account was created under (`app/Services/WalletFundingService.php:624-629`).
  Matching on it would call a customer's second payment a replay of their first and could mark
  an unpaid row `settled`, so it is reported as `same-session(success) N [account-scoped, clue
  only]` and never as `verified`. Same for a same-account-number + same-amount success
  (`same account+amount success N`).

With the list corrected, the command also stops pretending one bare `no` is a single answer. It
now reports three states, and the distinction is the point:

- `yes (credit on wallet user N)` - the same charge **is** on record as credited.
- `no (N checked, none credited)` - this exact charge was looked for by its own charge-scoped
  identity and no success row in the window carries it. A real negative, for that window.
- `not determinable (no charge identity stored)` - the row holds no charge-scoped identity at
  all, so local records cannot say either way. **This is not evidence that no money arrived.**
  It is a fact about what the old code persisted: a card funding request stores only amount, fee,
  email and consent before the gateway is even called
  (`app/Http/Controllers/FlutterwaveController.php:53-69`), and none of the paths that mark such
  a row failed ever obtains or stores a charge identity - `:162-165` and `:171-181` do not touch
  `meta` at all, and `:185-191` adds only a `gateway_response` string, no id
  (`:162-165` additionally trusts the customer's browser for `status`/`transaction_id`;
  `:171-181` is the case where the gateway lookup itself failed, so no verdict was ever
  obtained). A virtual-account funding request stores its `tx_ref` and account number
  and nothing gateway-issued (`app/Http/Controllers/VirtualAccountController.php:323-341`),
  and the two bulk close-outs (`:311-318` when a customer asks for a new account number,
  `app/Services/WalletDepositSync.php:270-280` after 60 unanswered minutes) change only
  `status` and `description`, never `meta`.

```sh
php artisan tinker --execute='
use Illuminate\Support\Facades\File;
// Charge-scoped tokens: Flutterwave issues these once, per charge, so equality proves
// the two rows are the same money.
$proofKeys = ["flutterwave_charge_id","flutterwave_id","flw_ref","id"];
$proofPayloadKeys = ["id","flw_ref"];
// Account-scoped tokens: a permanent virtual account carries the same tx_ref / gateway
// reference for every transfer ever made into it, so these link a session, not a charge.
$sessionKeys = ["tx_ref","flutterwave_reference"];
$sessionPayloadKeys = ["reference"];
$pick = static function (mixed $meta, array $keys, array $payloadKeys): array {
  $meta = (array) $meta;
  $payload = (array) ($meta["flutterwave_payload"] ?? []);
  $out = [];
  foreach ($keys as $key) {
    $value = $meta[$key] ?? null;
    if (is_scalar($value) && trim((string) $value) !== "") { $out[] = trim((string) $value); }
  }
  foreach ($payloadKeys as $key) {
    $value = $payload[$key] ?? null;
    if (is_scalar($value) && trim((string) $value) !== "") { $out[] = trim((string) $value); }
  }
  return array_values(array_unique($out));
};
$proof = static fn (mixed $meta): array => $pick($meta, $proofKeys, $proofPayloadKeys);
$session = static fn (mixed $meta): array => $pick($meta, $sessionKeys, $sessionPayloadKeys);
$story = static function (?string $text): string {
  $text = (string) $text;
  if (str_contains($text,"a new one-time account was generated")) return "closed: a new account number replaced it";
  if (str_contains($text,"No transfer arrived for this funding request")) return "closed: the sweep asked, no transfer found";
  if (str_contains($text,"Waiting for a bank transfer")) return "waiting on the account it names";
  if (str_contains($text,"Wallet funding via")) return "ordinary funding request";
  return $text === "" ? "no description" : "other wording";
};
$targets = \App\Models\WalletTransaction::query()
  ->where("type","credit")
  ->where("channel","like","flutterwave%")
  ->whereIn("status",["pending","failed"])
  ->where("created_at",">=",now()->subDays(30))
  ->orderBy("id")
  ->get();
$successes = \App\Models\WalletTransaction::query()
  ->where("type","credit")
  ->where("status","success")
  ->where("channel","like","flutterwave%")
  ->where("created_at",">=",now()->subDays(30))
  ->get();
foreach ($targets as $t) {
  $meta = (array) ($t->meta ?? []);
  $acct = trim((string) ($meta["virtual_account_number"] ?? ""));
  $holders = $acct !== "" ? \App\Models\User::where("virtual_account_number",$acct)->pluck("id")->all() : [];
  $owner = DB::table("wallets")->where("id",$t->wallet_id)->value("user_id");
  $holderCase = $acct === ""
    ? "no account stored"
    : (count($holders) === 0
      ? "0 holders"
      : (count($holders) > 1
        ? ">1 holders"
        : ($holders[0] == $owner ? "1 holder = wallet user" : "1 holder != wallet user")));
  $accountMentions = $acct !== ""
    ? DB::table("users")->whereNotNull("virtual_account_metadata")->where("virtual_account_metadata","like","%".$acct."%")->count()
    : 0;
  $ref = $t->reference ?: null;
  $dup = 0; $dupStatuses = [];
  if ($ref) {
    $rows = DB::table("wallet_transactions")
      ->where("type","credit")
      ->where("channel","like","flutterwave%")
      ->where("reference",$ref)
      ->select("status")
      ->get();
    $dup = $rows->count();
    $dupStatuses = $rows->pluck("status")->unique()->sort()->values()->all();
  }
  $twin = \App\Models\WalletTransaction::query()
    ->where("wallet_id",$t->wallet_id)
    ->where("type","credit")
    ->where("status","success")
    ->where("amount",$t->amount)
    ->whereBetween("created_at",[$t->created_at->copy()->subDay(), $t->created_at->copy()->addDay()])
    ->count();
  $acctTwin = 0;
  if ($acct !== "") {
    foreach ($successes as $s) {
      $sm = (array) ($s->meta ?? []);
      if ((int) $s->amount === (int) $t->amount && trim((string) ($sm["virtual_account_number"] ?? "")) === $acct) { $acctTwin++; }
    }
  }
  $ids = $proof($meta);
  $verified = false; $matchUserId = null;
  foreach ($successes as $s) {
    $sids = $proof($s->meta ?? []);
    foreach ($ids as $id) {
      if (in_array($id,$sids,true)) {
        $verified = true;
        $matchUserId = DB::table("wallets")->where("id",$s->wallet_id)->value("user_id");
        break 2;
      }
    }
  }
  $linked = $session($meta);
  $sessionTwin = 0;
  if ($linked !== []) {
    foreach ($successes as $s) {
      if (array_intersect($linked, $session($s->meta ?? [])) !== []) { $sessionTwin++; }
    }
  }
  $logCount = 0;
  if ($ref && File::exists(storage_path("logs/laravel.log"))) {
    foreach (File::lines(storage_path("logs/laravel.log")) as $line) { if (str_contains($line,$ref)) $logCount++; }
  }
  $naira = number_format(((int) $t->amount) / 100, 2, ".", ",");
  $age = round($t->created_at->diffInDays(now(),true),1);
  $touch = round($t->updated_at->diffInDays(now(),true),1);
  $sweepAsks = ($t->status === "pending" || $touch <= 14) ? "yes" : "no";
  if ($verified) {
    $bucket = ($matchUserId !== null && $matchUserId == $owner)
      ? "settled (verified identity, same wallet)"
      : "ownership-review (verified identity, other wallet)";
  } elseif ($t->status === "pending") {
    $bucket = "pending-watch".($ids === [] ? " (charge identity not on record)" : " (charge identity checked, no match)");
  } elseif ($dup > 1 || $twin > 0 || $acctTwin > 0 || $sessionTwin > 0) {
    $bucket = "dashboard-check (clue)";
  } elseif ($ids === []) {
    $bucket = "not determinable locally (no charge identity stored, no clue)";
  } else {
    $bucket = "manual-reconcile candidate (charge identity checked, no match, no clue)";
  }
  echo "#".$t->id
    ." | ".$t->status
    ." | ".($t->channel ?: "-")
    ." | kobo ".$t->amount." = N".$naira
    ." | ".$age."d old, touched ".$touch."d ago, sweep asks ".$sweepAsks
    ." | wallet user ".($owner ?? "?")
    ." | ".$holderCase.($acct !== "" ? " (account named in ".$accountMentions." user record(s), coarse)" : "")
    ." | said: ".$story($t->description)
    ." | same-ref rows ".$dup." (".implode(",",$dupStatuses).")"
    ." | same wallet+amount success ".$twin
    ." | same account+amount success ".$acctTwin
    ." | same-session(success) ".$sessionTwin." [account-scoped, clue only]"
    ." | charge-identity tokens on row ".count($ids)
    ." | verified identity match ".($verified ? "yes (credit on wallet user ".($matchUserId ?? "?").")" : ($ids === [] ? "not determinable (no charge identity stored)" : "no (".count($ids)." checked, none credited)"))
    ." | log mentions ".$logCount
    ." | bucket: ".$bucket
    .PHP_EOL;
}
'
```

`same-ref rows` can only exceed 1 if the UNIQUE index on `reference`
(`2026_01_14_190722_create_wallet_transactions_table.php:20`) is not present on the live table;
§11.2's group-by query is the authoritative duplicate-reference check either way.

Read the output against the buckets in §11.4. `verified identity match = yes` is the only
result that proves the same Flutterwave charge was already credited - and the bucket then
says whether that credit sits on the same wallet (`settled`) or a different one
(`ownership-review`: the money was banked, but who owns it is the owner's decision, never
the command's). Everything else is a clue or a blank, and a blank (`not determinable`) is
strictly **not** an answer about whether money arrived.

### 11.5 What this plan deliberately does not do

- **No Flutterwave call of any kind** - not `deposits:audit` without `--references=0`, no
  dashboard lookup, no test payment. Whether the money actually arrived is a question the
  post-deploy sweep and webhook answer as part of normal operation, not something this
  investigation pays to ask.
- **No refunds, no credits, no row edits, no deletes.** If any open row turns out to be
  owed money, the resolution path after the deploy is the ordinary sweep finding a later
  arrival, or an admin credit with an audit note - never a hand-written SQL fix. Not the
  held-deposits screen: old rows are not backfilled into it (§11.4).
- **The pending row does not block the deploy.** The new code is strictly safer for it: it
  can only be credited to a provable owner or held for an admin, never quietly dropped -
  which is what the current code does with a transfer it cannot attribute.
