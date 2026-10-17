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
| 30-day Flutterwave credits | 36 successful, 1 pending, 3 failed (§6's baseline) |
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
characterizes the one pending and three failed rows now, so their future is a decision
rather than a surprise.

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

Thirteen rows, and §0's recorded answers now cover all but two of them in full - the key
presences (§3, answered for `flutterwave_secret_key` and the hash pair, open for the other
four plus `fixed_account_bvn`) and the audit totals (§6's status counts are in: 36 / 1 / 3;
the pending and failed rows' characterization is §11). What is still worth reporting is the
small open set and nothing else: §3's remaining key presences, §8's four log counts, and
§11's results.

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

## 11. The one pending and three failed Flutterwave credits - a read-only investigation

The 30-day baseline (§6) is 36 successful, 1 pending, 3 failed. The successful 36 are
history; the other four are the rows an owner eventually has to think about. This plan
characterizes them **without asking Flutterwave anything, and without refunding, crediting
or editing a single row** - local database reads and log reads only. Run it before the
deploy; nothing in it changes anything, and its output is the pre-deploy picture the
post-deploy audit gets compared against. This is approval row C4, and it answers three
questions for each of the four rows: is the reference a duplicate, is there a previous
successful payment that already settled the same money, and does the row need manual
reconciliation once the new code is live.

Nothing here prints an account number or a secret: rows are identified by ledger id, and
attribution is reported as user-id counts.

### 11.1 List the four rows with their attribution facts

```sh
php artisan tinker --execute='foreach (\App\Models\WalletTransaction::query()->where("type","credit")->where("channel","like","flutterwave%")->whereIn("status",["pending","failed"])->where("created_at",">=",now()->subDays(30))->orderBy("id")->get() as $t) { $meta = $t->meta ?? []; $acct = $meta["virtual_account_number"] ?? null; $holders = $acct ? \App\Models\User::where("virtual_account_number",$acct)->pluck("id")->all() : []; $owner = DB::table("wallets")->where("id",$t->wallet_id)->value("user_id"); echo "#".$t->id." | ".$t->status." | amount ".$t->amount." | ".$t->created_at->format("Y-m-d H:i")." | ref ".($t->reference ?: "-")." | wallet user ".($owner ?? "?")." | meta keys: ".implode(",", array_keys($meta))." | account-number holders: ".($acct ? (count($holders) === 0 ? "none" : implode(",", $holders)) : "not stored").PHP_EOL; }'
```

Read each line against three cases:

- **0 holders** - the account number on the row matches no customer. Post-deploy, an
  arriving transfer on that number becomes a held deposit, not a credit.
- **1 holder, and it is the wallet's own user** - ordinary: the row is simply unresolved,
  and post-deploy the sweep or the webhook settles it the normal way.
- **2+ holders, or 1 holder that is a different user** - ambiguous or wrong-wallet:
  post-deploy, exactly the case the held-deposits screen exists for.

### 11.2 Has any of the four references been seen again?

A failed row followed by a successful row on the same reference is a **strong clue** that a
retry worked, but it is **not proof** the same Flutterwave charge was paid - two different
transfers can share a reference. Only a matching charge identity (see below) is proof. Two
successful rows on one reference would be a pre-guard double payment, which the 2026-10-16
measurement says the old code could produce:

```sh
php artisan tinker --execute='echo json_encode(DB::table("wallet_transactions")->selectRaw("reference, group_concat(distinct status) as statuses, count(*) as rows_total")->where("channel","like","flutterwave%")->where("type","credit")->whereNotNull("reference")->where("reference","<>","")->groupBy("reference")->havingRaw("count(*) > 1")->get(), JSON_PRETTY_PRINT); echo PHP_EOL;'
```

An empty answer is the quiet one: every reference appears exactly once.

A retry can also succeed under a **new** reference, which no reference-grouped query can see.
For each of the four rows, count same-wallet, same-amount **successful** credits within a day
either side - a previous-successful-payment **clue**:

```sh
php artisan tinker --execute='foreach (\App\Models\WalletTransaction::query()->where("type","credit")->where("channel","like","flutterwave%")->whereIn("status",["pending","failed"])->where("created_at",">=",now()->subDays(30))->orderBy("id")->get() as $t) { $twins = \App\Models\WalletTransaction::query()->where("wallet_id",$t->wallet_id)->where("type","credit")->where("status","success")->where("amount",$t->amount)->whereBetween("created_at",[$t->created_at->copy()->subDay(),$t->created_at->copy()->addDay()])->count(); echo "#".$t->id." (".$t->status.", amount ".$t->amount."): same-wallet same-amount successes within a day: ".$twins.PHP_EOL; }'
```

A nonzero answer is a clue that a retry may have paid the same customer, but it is **not**
proof - two unrelated transfers can share a wallet and amount. Verify charge identity before
classifying the row as settled.

The **definitive** previous-payment check compares the Flutterwave charge identity tokens
stored in `meta` (`flutterwave_charge_id`, `id`, `flw_ref`) against every successful credit
in the same window. This command prints only `yes` or `no` and never exposes the tokens.
A match exits only the two inner loops (`break 2`), never the loop over the target rows, so
each of the four rows always gets its own line:

```sh
php artisan tinker --execute='foreach (\App\Models\WalletTransaction::query()->where("type","credit")->where("channel","like","flutterwave%")->whereIn("status",["pending","failed"])->where("created_at",">=",now()->subDays(30))->orderBy("id")->get() as $t) { $m = $t->meta ?? []; $ids = array_values(array_filter([$m["flutterwave_charge_id"] ?? null, $m["id"] ?? null, $m["flw_ref"] ?? null])); $match = false; if ($ids !== []) { $successes = \App\Models\WalletTransaction::query()->where("type","credit")->where("status","success")->where("channel","like","flutterwave%")->where("created_at",">=",now()->subDays(30))->get(); foreach ($successes as $s) { $sm = $s->meta ?? []; $sids = array_values(array_filter([$sm["flutterwave_charge_id"] ?? null, $sm["id"] ?? null, $sm["flw_ref"] ?? null])); foreach ($ids as $id) { if (in_array($id, $sids, true)) { $match = true; break 2; } } } } echo "#".$t->id." (".$t->status."): same-charge-identity success exists: ".($match ? "yes" : "no").PHP_EOL; }'
```

`yes` here is the only result that proves the same charge was already credited; everything
else is a clue that needs independent evidence.

### 11.3 What the site itself said at the time - logs, not the provider

For each reference from 11.1, read the site's own record of the arrival: the webhook, the
sweep's verdict, any "user not found":

```sh
grep -c '<reference-from-11.1>' storage/logs/laravel.log
grep '<reference-from-11.1>' storage/logs/laravel.log | tail -5
```

How to read it: a `failed` credit row is the ledger's record that the deposit did not
complete - no balance ever moved. A still-`pending` row has been swept and stayed
unresolved, which is either a transfer that has not arrived or one that arrived and could
not be attributed.

### 11.4 Which rows become manual reconciliation candidates

Read 11.1's attribution case, 11.2's three checks and 11.3's log lines together. The rule is:
only a **verified charge identity** (matching `flutterwave_charge_id`, `id` or `flw_ref`
between two rows) is proof that the same Flutterwave charge was paid. Same reference or same
wallet+amount is a clue, not proof - two different charges can share a reference typo or the
same amount.

Also: migration `2026_10_16_000002_create_flutterwave_held_deposits_table` is forward-only and
does **not** backfill old `pending` or `failed` rows. So an old failed deposit will **not**
automatically appear in Admin → Held Deposits after the deploy. The held-deposits screen is
for **new** ambiguous arrivals; old rows are resolved through ordinary admin credit with an
audit note, not through that screen.

With that, each of the four rows lands in one bucket:

- **Verified charge identity match to a successful row** - the same Flutterwave charge was
  already credited. If that credit sits on the **same wallet**, nothing is owed; annotate
  only. If it sits on a **different wallet**, the money was banked but who owns it is an
  ownership decision - flag for review, do not label the row settled.
- **No verified identity match, but a strong clue** (same reference, or same wallet+amount
  within a day) - flag for the owner to check the Flutterwave dashboard. It is **not** closed.
- **Failed, no verified match and no strong clue** - no balance ever moved. Whether
  Flutterwave is holding the money is a dashboard question, not a site API question. If the
  dashboard shows the charge completed, the row is a **manual reconciliation candidate**:
  resolve after the deploy through an ordinary manual credit with an audit note - never a
  hand-written SQL fix, never during the deploy itself, and not through the held-deposits
  screen (old rows are not backfilled there).
- **Pending, no verified match** - swept repeatedly and still unresolved: either the transfer
  never completed (nothing owed) or it arrived unattributed, which the old code drops quietly.
  Post-deploy, §6's audit re-run answers it: the new code can only credit a provable owner or
  hold a **new** arrival for an admin. An old pending row is not backfilled into held deposits.

The one hard rule stands regardless of bucket: nothing in this investigation refunds, credits,
edits or deletes anything. It only sorts the four rows into the buckets above so the
post-deploy work has a list instead of a surprise.

### 11.6 One-shot privacy-safe C4 summary

Run this single command on the live box. It prints the same four rows in one line each,
never exposing the full reference, account number, customer name, email or phone. It reports
attribution as a holder-count case, duplicate-reference counts, a same-wallet/same-amount
clue, the only definitive check (matching charge identity), and log-line mentions. A
verified match whose credit sits on a **different wallet** is bucketed
`ownership-review`, never `settled`. On a match the two inner loops exit via `break 2` -
deliberately one level short of the targets loop, so every target row always gets its line.

```sh
php artisan tinker --execute='
use Illuminate\Support\Facades\File;
$targets = \App\Models\WalletTransaction::query()
  ->where("type","credit")
  ->where("channel","like","flutterwave%")
  ->whereIn("status",["pending","failed"])
  ->where("created_at",">=",now()->subDays(30))
  ->orderBy("id")
  ->get();
foreach ($targets as $t) {
  $meta = $t->meta ?? [];
  $acct = $meta["virtual_account_number"] ?? null;
  $holders = $acct ? \App\Models\User::where("virtual_account_number",$acct)->pluck("id")->all() : [];
  $owner = DB::table("wallets")->where("id",$t->wallet_id)->value("user_id");
  $holderCase = $acct
    ? (count($holders) === 0
      ? "0 holders"
      : (count($holders) > 1
        ? ">1 holders"
        : ($holders[0] == $owner ? "1 holder = wallet user" : "1 holder != wallet user")))
    : "no account stored";
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
  $ids = array_values(array_filter([$meta["flutterwave_charge_id"]??null, $meta["id"]??null, $meta["flw_ref"]??null]));
  $verified = false; $matchUserId = null;
  if ($ids !== []) {
    $successes = \App\Models\WalletTransaction::query()
      ->where("type","credit")
      ->where("status","success")
      ->where("channel","like","flutterwave%")
      ->where("created_at",">=",now()->subDays(30))
      ->get();
    foreach ($successes as $s) {
      $sm = $s->meta ?? [];
      $sids = array_values(array_filter([$sm["flutterwave_charge_id"]??null, $sm["id"]??null, $sm["flw_ref"]??null]));
      foreach ($ids as $id) {
        if (in_array($id,$sids,true)) {
          $verified = true;
          $matchUserId = DB::table("wallets")->where("id",$s->wallet_id)->value("user_id");
          break 2;
        }
      }
    }
  }
  $logCount = 0;
  if ($ref && File::exists(storage_path("logs/laravel.log"))) {
    foreach (File::lines(storage_path("logs/laravel.log")) as $line) { if (str_contains($line,$ref)) $logCount++; }
  }
  $bucket = $verified
    ? ((isset($matchUserId) && $matchUserId == $owner)
      ? "settled (verified identity, same wallet)"
      : "ownership-review (verified identity, other wallet)")
    : ($t->status === "failed"
      ? (($dup>1 || $twin>0) ? "dashboard-check (clue)" : "manual-reconcile candidate")
      : "pending-watch");
  echo "#".$t->id." | ".$t->status." | amount ".$t->amount." | ".round($t->created_at->diffInDays(now(),true),1)."d old | wallet user ".($owner??"?")." | ".$holderCase." | same-ref rows ".$dup." (".implode(",",$dupStatuses).") | same-wallet+amount twin ".$twin." | verified identity match ".($verified?"yes (credit on wallet user ".($matchUserId??"?").")":"no")." | log mentions ".$logCount." | bucket: ".$bucket.PHP_EOL;
}
'
```

Read the output against the buckets in §11.4. `verified identity match = yes` is the only
result that proves the same Flutterwave charge was already credited - and the bucket then
says whether that credit sits on the same wallet (`settled`) or a different one
(`ownership-review`: the money was banked, but who owns it is the owner's decision, never
the command's). Everything else is a clue that needs independent evidence.

### 11.5 What this plan deliberately does not do

- **No Flutterwave call of any kind** - not `deposits:audit` without `--references=0`, no
  dashboard lookup, no test payment. Whether the money actually arrived is a question the
  post-deploy sweep and webhook answer as part of normal operation, not something this
  investigation pays to ask.
- **No refunds, no credits, no row edits, no deletes.** If any of the four turns out to be
  owed money, the resolution path after the deploy is the held-deposits screen or the
  ordinary sweep - never a hand-written SQL fix.
- **The pending row does not block the deploy.** The new code is strictly safer for it: it
  can only be credited to a provable owner or held for an admin, never quietly dropped -
  which is what the current code does with a transfer it cannot attribute.
