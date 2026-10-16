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
| New migrations | not installed yet - expected before the deploy (§5) |
| PHP (CLI) | 8.3.35 |

**One of these answers changes a plan.** The live engine is **MariaDB, not MySQL**: the
twelve money-path concurrency proofs passed against MySQL 8.4.3 on the development
machine, and "same InnoDB locking model" is an expectation, not a proof. One suite run
against a scratch database on the host is the remaining piece - it creates a database, so
it is not part of the read-only batch; it is an owner decision, tracked as checklist §9
item 4. Still open from §5: the isolation level and `innodb_lock_wait_timeout`.

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

**Partly answered (2026-10-16): MariaDB 10.6.28, autocommit 1; the four money tables
InnoDB.** The MariaDB-not-MySQL point is §0's plan-changer. Still open from this command:
the isolation level, the lock timeout, and the negative-balance count.

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

Thirteen rows, and §0 (2026-10-16) already answers eight of them - live sha, porcelain
status, the three saved modes, the cron line, `schedule:list`, the money-table engines, the
duplicate aggregate - plus the engine row's version and autocommit. The report back is now
the open set: the five key presences (§3), the `.env`-vs-`config()` hash pair (§3), the
isolation level and lock timeout (§5), negative wallets (§5), the §6 baseline count, and
§8's log counts.

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
