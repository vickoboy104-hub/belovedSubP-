# Deploy and go-live verification checklist

For `belovedsubp.com` on ListedHosting shared cPanel (host `s3470`, user `beloveds`,
LiteSpeed, PHP 8.3.35 CLI (owner-read 2026-10-16, superseding an earlier 8.3.33 read),
app repo `/home/beloveds/vtu`).

Read this whole file before touching the live box. Two rules override everything in it:

1. **Never edit a proven command block on the spot.** The one-line "improvement" I made to
   the docroot copy loop on 2026-10-11 took the whole site to HTTP 500. Copy these blocks
   exactly, or not at all.
2. **Nothing here authorises a deploy.** A deploy needs the owner's explicit go-ahead, and
   so does every step that spends money or touches a live row.

---

## 0. Before anything else — is this build even allowed to go out?

| # | Check | How to check | Pass looks like |
|---|---|---|---|
| 0.1 | The branch you are deploying is the reviewed one | `git -C /home/beloveds/vtu rev-parse origin/codex/identity-first-jhtech-experience` | `8992cbaa0f089e2db7b85ff0bbed01b146709b75` or a descendant the owner has read - currently `6db7a49b46c2c399439efdafee992f7dbb800c51`, reviewed and push-approved by name 2026-10-17 |
| 0.2 | It is a fast-forward from what is live | `git merge-base --is-ancestor <live-HEAD> <new-HEAD>; echo $?` | `0`. If it is not `0`, **stop** and work out why before pulling |
| 0.3 | The full suite is green on the machine that built it | `php artisan test` | no failures, and the twelve MySQL concurrency tests run somewhere before a money-path change ships (section 9, item 4) |
| 0.4 | Every credential has been rotated **after** the machine cleaning | owner's list: GitHub, cPanel, FTP, DB, GSUBZ, ConfirmIdent, Flutterwave, SMTP | done. Until it is done this deploy is **not** cleared for production - see section 6 |
| 0.5 | A database backup exists, verified, outside the docroot | section 1.0's blocks | a gzipped dump under `/home/beloveds/backups/` whose **dump command exited zero** (1.0's guard deletes the file when it did not - a gzip filename alone never passes), read back with table counts that equal the live schema plus a completion line, **and restored into a scratch MariaDB database with matching money-table row counts**, its filename recorded beside `last-good-sha.txt` (section 10.0) |

---

## 1. Pull the code (order matters, and it has been got wrong before)

### 1.0 Back the database up first - made, verified, and kept private

This range is the first whose migrations **write data** (two backfills - see
[deploy-preflight.md](deploy-preflight.md) section 3), and section 10.3 deliberately
forbids rolling the database back, so a copy of the database is the only recovery for data
the range touches. Gate 0.5 checks it happened; this is where it happens.

Written 2026-10-17, not yet exercised on this host (its failure detection was hardened the
same day after review) - run the availability check first, and fall back to cPanel's
phpMyAdmin Export (gzip) if the shell refuses:

```bash
cd /home/beloveds/vtu
command -v mysqldump mysql || echo "mysqldump or mysql missing in this shell - use cPanel phpMyAdmin Export"
mysqldump --version
```

**A failed dump must fail loudly.** `mysqldump ... | gzip > file` is not an acceptable command
here: the pipeline's exit status belongs to gzip, so a mysqldump that dies mid-dump still ends
the line with exit zero and leaves a small `.sql.gz` file wearing a backup's name - exactly
the false backup this gate exists to refuse. `set -o pipefail` is not the fix: this shell
family already rejects `<( ... )`, so it cannot be relied on to support pipefail either. The
block below therefore never pipes. `mysqldump` writes the plain `.sql` file itself, **its own
exit status is tested directly**, the partial file is deleted when that status is nonzero,
and only a dump that exited zero is compressed afterwards.

`mysqldump --version` should answer **MariaDB Dump 10.6.x**: the live engine is MariaDB
10.6.28 (read 2026-10-16), and the host's own client is the version-matched pairing the
backup wants. `--single-transaction`, `--routines` and gzip are long-standing
MariaDB 10.6 behaviour, and the JSON columns - `LONGTEXT` with `json_valid` checks under
MariaDB - dump and restore natively between identical engines. The phpMyAdmin fallback is
cPanel's own MariaDB tool and needs no compatibility argument at all.

With `mysqldump`, the credentials come from `.env` at runtime - nothing is typed into a
prompt, and no value lands in shell history or `ps` output:

```bash
cd /home/beloveds/vtu
DB_HOST=$(sed -n 's/^DB_HOST=//p' .env | sed 's/^"//;s/"$//')
DB_NAME=$(sed -n 's/^DB_DATABASE=//p' .env | sed 's/^"//;s/"$//')
DB_USER=$(sed -n 's/^DB_USERNAME=//p' .env | sed 's/^"//;s/"$//')
DB_PASS=$(sed -n 's/^DB_PASSWORD=//p' .env | sed 's/^"//;s/"$//')
mkdir -p /home/beloveds/backups && chmod 700 /home/beloveds/backups
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASS" > /home/beloveds/.my.dump.cnf
chmod 600 /home/beloveds/.my.dump.cnf
BASE="/home/beloveds/backups/predeploy-$(date +%Y%m%d-%H%M)"
if mysqldump --defaults-extra-file=/home/beloveds/.my.dump.cnf --single-transaction --routines "$DB_NAME" > "$BASE.sql"; then
  gzip "$BASE.sql" && echo "dump exited zero and is compressed: $BASE.sql.gz"
else
  echo "DUMP FAILED - $BASE.sql was partial and has been removed; it is not a backup. Read the error above before doing anything else."
  rm -f "$BASE.sql"
fi
rm -f /home/beloveds/.my.dump.cnf
[ -e /home/beloveds/.my.dump.cnf ] && echo "credential file still present - remove it now"
chmod 600 /home/beloveds/backups/predeploy-*.sql.gz
ls -l /home/beloveds/backups/
```

The `rm -f` of the credential file sits **outside** the `if`, so the database password leaves
the disk whether the dump succeeded or failed; the `[ -e ]` line that follows is its
verification and prints nothing when the removal worked.

`--single-transaction` takes a consistent snapshot of the InnoDB tables without locking
them (the four money tables are InnoDB, read on the box 2026-10-16), so the site stays up
while the dump runs.

**Read the copy back - a backup that has never been read is a hope, not a backup.** Two table
counts, one from the live schema and one from the dump, and the two numbers must be **equal** -
a dump truncated mid-way loses its tail tables and fails this pair:

```bash
cd /home/beloveds/vtu
BASE="/home/beloveds/backups/predeploy-YYYYMMDD-HHMM"   # the filename shown by ls -l above, minus .sql.gz
php artisan tinker --execute="echo 'tables in the live schema: '.DB::select('select count(*) c from information_schema.tables where table_schema = database()')[0]->c.PHP_EOL;"
zcat "$BASE.sql.gz" | grep -c '^CREATE TABLE'
zcat "$BASE.sql.gz" | tail -n 3
```

The tail must end with the dump's completion line (`-- Dump completed`), not stop mid-table. A
few-kilobyte file is a failed dump wearing a filename - though after the guard above it should
no longer exist long enough to be mistaken for one.

**The restore is required for this range, not optional, and it is what turns "a dump exists"
into "a backup is verified".** Create the scratch database first: cPanel → MySQL Databases,
name it `restore` (cPanel prefixes it to `beloveds_restore`), and attach the live database's
own user with All Privileges. Then bring the dump back up in it, compare the four money
tables' row counts against live, and drop the scratch database once the two count lines have
been read - it holds a second copy of customer identities:

```bash
cd /home/beloveds/vtu
BASE="/home/beloveds/backups/predeploy-YYYYMMDD-HHMM"
DB_HOST=$(sed -n 's/^DB_HOST=//p' .env | sed 's/^"//;s/"$//')
DB_USER=$(sed -n 's/^DB_USERNAME=//p' .env | sed 's/^"//;s/"$//')
DB_PASS=$(sed -n 's/^DB_PASSWORD=//p' .env | sed 's/^"//;s/"$//')
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASS" > /home/beloveds/.my.dump.cnf
chmod 600 /home/beloveds/.my.dump.cnf
gunzip -c "$BASE.sql.gz" > /home/beloveds/backups/restore-check.sql
if mysql --defaults-extra-file=/home/beloveds/.my.dump.cnf beloveds_restore < /home/beloveds/backups/restore-check.sql; then
  echo "restore completed into beloveds_restore"
else
  echo "RESTORE FAILED - this backup is not proven. Do not continue with the deploy."
fi
php artisan tinker --execute="echo 'live: users '.DB::table('users')->count().' | orders '.DB::table('orders')->count().' | wallets '.DB::table('wallets')->count().' | wallet_transactions '.DB::table('wallet_transactions')->count().PHP_EOL;"
mysql --defaults-extra-file=/home/beloveds/.my.dump.cnf -N -e "SELECT CONCAT('restored: users ', COUNT(*)) FROM beloveds_restore.users UNION ALL SELECT CONCAT('orders ', COUNT(*)) FROM beloveds_restore.orders UNION ALL SELECT CONCAT('wallets ', COUNT(*)) FROM beloveds_restore.wallets UNION ALL SELECT CONCAT('wallet_transactions ', COUNT(*)) FROM beloveds_restore.wallet_transactions;"
mysql --defaults-extra-file=/home/beloveds/.my.dump.cnf -e "DROP DATABASE beloveds_restore"
rm -f /home/beloveds/.my.dump.cnf
[ -e /home/beloveds/.my.dump.cnf ] && echo "credential file still present - remove it now"
rm -f /home/beloveds/backups/restore-check.sql
```

The restore reads the file through a redirect rather than a pipe, for the same reason the dump
does: `mysql`'s own exit status is what the `if` tests. That restore is also the MariaDB 10.6
compatibility proof - a dump that comes back up on the same engine with matching counts is a
verified backup, not a compatible-looking one - and it happens **before any live migration
runs**: 1.0 sits before 1.1's window, and gate 0.5 fails the deploy if it did not happen.

When the two count lines agree, record the filename beside the sha - together they are the
rollback record (section 10.0):

```bash
echo "verified backup: $BASE.sql.gz" >> /home/beloveds/last-good-sha.txt
```

- **Private means outside `public_html`** - `/home/beloveds/backups/` with the permissions
  above; anything under the docroot is web-served.
- Download a copy off the server before the deploy proceeds.
- Keep it until the deploy has been stable for a week, then delete it deliberately.
- The plain-text `restore-check.sql` and the scratch database are deleted inside their own
  block - neither outlives section 1.0.
- Record the filename beside `last-good-sha.txt` with the final block above, only once the
  counts agreed - together they are the rollback record.

### 1.1 Take the site down - the maintenance window opens

Whether this deploy runs inside a closed window is the owner's call (preflight section 7
names the seconds it removes: a cron tick between checkout and migrate would otherwise run
new code against the old schema - and, for this range only, the straggler window in which
a browser holding stale HTML can ask for one of the two old hashed assets the checkout
removes, preflight section 5). Taken, the command is the one section 10 already uses,
self-refreshing so a customer mid-purchase comes back on their own:

```bash
cd /home/beloveds/vtu
php artisan down --retry=15
```

Record the opening time in the deploy notes; the window closes at the end of section 4,
and section 7's smoke test is the proof it closed cleanly. Skipped, the deploy still works
- it is just not a controlled window.

### 1.2 Pull the code

```bash
cd /home/beloveds/vtu
git fetch origin
git checkout codex/identity-first-jhtech-experience
git merge --ff-only origin/codex/identity-first-jhtech-experience
```

### 1.3 Review the migrations, then run them

The checkout above put the seven new migration files on disk; review what they will do
before running anything. Both commands are read-only:

```bash
cd /home/beloveds/vtu
php artisan migrate:status | tail -12
php artisan migrate --pretend
```

- `migrate:status` should list exactly the seven migrations from the preflight's section 2
  table as `Pending` - no more, no fewer.
- `migrate --pretend` prints the SQL without executing it: five `create table`, and the
  two `alter table orders` runs with their indexes. The two backfills are PHP logic, so
  their reads and writes do **not** appear in the pretend output - their behaviour is
  described in preflight section 3 and proved after the fact by 7.4 and 8.3. Any `drop`,
  any `update` without a `where`, or any table outside the preflight's list is a stop, not
  a deploy.

Then run them:

```bash
cd /home/beloveds/vtu
php artisan migrate --force
```

- **`migrate` comes after the checkout, never before.** A migration added in the commits
  being deployed does not exist on disk until the checkout finishes; run early and it
  prints a reassuring "Nothing to migrate" while the schema stays behind the code, which
  then 500s on the first write.
- The pull and the migrate have been separate blocks since 2026-10-17 so the review steps
  fit between them; the order - checkout before migrate - is the part that was got wrong
  before, and it is unchanged.
- **No `composer install`** unless `composer.json` / `composer.lock` actually changed:
  `git log -1 --name-only -- composer.json composer.lock`.
- **No `npm run build` on the server.** Compiled assets are force-committed; the server
  has no business rebuilding them.

## 2. Rebuild the front end — only if you changed the front end

```bash
php artisan view:cache
npm run build
```

Then `git add -f public/build` **on the development machine**, commit, and deploy that
commit. `public/build` is gitignored but shipped, and the same sources build either 170 kB
or 185 kB of CSS depending on whether the view cache was warm when Vite ran. If the CSS
size moved without you changing any style, the view cache is the reason.

## 3. Copy the public files into the docroot — the step that bites every deploy

`~/public_html` is the docroot and is a **hand-maintained copy** of `vtu/public`, not a
symlink. Only `build` and `storage` are linked. Any file added under `public/` 404s after
a perfect `git pull` until it is copied by hand.

```bash
cd /home/beloveds/vtu
git ls-tree -r --name-only HEAD -- public | sed 's|^public/||' | grep -vE '^(build|storage)/' > /tmp/want.txt
while read -r f; do
  [ -e "$HOME/public_html/$f" ] && continue
  mkdir -p "$HOME/public_html/$(dirname "$f")"
  cp -a "public/$f" "$HOME/public_html/$f" && echo "copied: $f"
done < /tmp/want.txt
```

- **Additive only. Never `rsync --delete`, never add a `cmp`-based overwrite.**
  `public_html/index.php`, `.htaccess` and `robots.txt` are **hand-edited on the server**
  and are not the git versions. `index.php` in the docroot is an absolute-path shim
  pointing at `/home/beloveds/vtu/vendor/autoload.php`; overwriting it with git's
  relative-path version breaks the whole site.
- `<( ... )` process substitution **fails** in this shell. Use the real temp file above.
- Never `rm -rf ~/public_html/build/` — the trailing slash follows the symlink and deletes
  the real `vtu/public/build`.

## 4. Finish the deploy

```bash
cd /home/beloveds/vtu
php artisan optimize
```

`config` and `routes` are cached on this host, so the deploy must **end** with `optimize`,
not `optimize:clear`, or the site silently loses its cache. Proof the route cache took the
new routes: `/settings` answers `302` rather than `404`.

If 1.1 took the site down, this is where the maintenance window closes - the same pairing
section 10.5 uses:

```bash
cd /home/beloveds/vtu
php artisan up
```

`up` on a site that is already live prints "Application is already live." and changes
nothing, so the command is safe whether or not the window was closed. Record the closing
time beside the opening time from 1.1: the pair is what makes this a controlled maintenance
window rather than an outage that happened to be short.

## 5. The cron — the one the money depends on

Three commands are scheduled (`bootstrap/app.php`):

| Command | How often | What breaks if it never runs |
|---|---|---|
| `prices:sync` | hourly | Provider prices drift; the site sells at yesterday's cost |
| `wallet:sync-deposits` | every 5 min | **A bank transfer that arrived while the webhook was down is never credited.** This sweep is the only thing that closes that gap |
| `manual:recover-documents` | every 5 min | A paid order whose document upload died stays "lost" until an admin notices |

Nothing on shared cPanel fires a Laravel schedule without a user cron entry. To verify one
exists, and to make it prove itself:

```bash
crontab -l | grep -v '^#'
```

If there is no `schedule:run` line, add one that **writes to a log instead of /dev/null**,
so its firing is observable rather than assumed:

```
* * * * * cd /home/beloveds/vtu && /usr/local/bin/php artisan schedule:run >> /home/beloveds/schedule.log 2>&1
```

- Confirm the PHP binary the CLI wants with `which php` on the box first; `/usr/local/bin/php`
  is the usual ListedHosting path but must be checked, not assumed.
- Then wait five minutes and check the file has grown: `ls -l /home/beloveds/schedule.log`.
  An empty file that is being touched proves the cron fires; a file full of errors proves it
  fires and fails, which is the more useful of the two outcomes.
- Cross-check the schedule the code expects: `php artisan schedule:list`. Run against this
  branch on 2026-10-16 it printed exactly the three rows above and nothing else -
  `0 * * * *` for `prices:sync`, `*/5 * * * *` for the other two - so the table is the code's
  own statement, not a summary of it.
- Second heartbeat, no code needed: `provider_plan_prices.last_synced_at` should move within
  an hour of the cron going in - `ProviderPlanPriceService` stamps that column on every plan
  it refreshes. If it does not move, the cron is not running as the user who owns the app.

## 6. Service worker and cached pages

`public/sw.js` (`CACHE_VERSION = 'v2'`) does **not** serve cached HTML: the fetch handler
returns early for `request.mode === 'navigate'`, so every page comes from the network. A
customer cannot be left staring at a stale purchase form by this file, and the version
number does not need bumping when only Blade changes.

What it *does* cache is static assets, and it serves them **cache-first**: a returning
visitor's first hit on a file at an unchanged URL gets the old copy while the new one is
fetched in the background.

- Vite's bundle files are content-hashed, so a rebuild changes their URLs and this never
  bites.
- Un-hashed files *can* bite: `manifest.webmanifest`, `icons/pwa-192x192.png`,
  `icons/pwa-512x512.png`, `favicon.ico`, and anything under `images/`. **If a deploy
  changes one of those, bump `CACHE_VERSION` in the same commit** so `activate` deletes the
  old `static-*` cache.
- After any deploy that changes `sw.js` itself, confirm the docroot copy matches git rather
  than trusting the copy loop:
  `cmp /home/beloveds/vtu/public/sw.js /home/beloveds/public_html/sw.js && echo same`

## 7. Post-deploy smoke test — read-only first

| # | Check | How | Pass |
|---|---|---|---|
| 7.1 | App is up | `curl -s -o /dev/null -w '%{http_code}' https://www.belovedsubp.com/up` | `200` |
| 7.2 | New assets are not 404 | fetch the newest `public/build/assets/*.css` name from both the site and `git ls-tree` | same bytes, HTTP 200 |
| 7.3 | Login page renders styled | load `/login` in a browser, view source, confirm the `build/assets` names match the deployed manifest | yes |
| 7.4 | All seven migrations ran | `php artisan migrate:status \| grep -E 'service_forms\|add_assignment\|retire_saved\|purchase_intents\|document_filings\|flutterwave'` | all seven `Ran` - [deploy-preflight.md](deploy-preflight.md) section 2 is the list; the two `flutterwave` tables are what stop a single bank transfer funding two wallets |
| 7.5 | Held-purchase discovery still works | run section 3 of [held-purchase-runbook.md](held-purchase-runbook.md) | returns without error (zero holds is a pass) |
| 7.6 | Prices unchanged from the approved list | admin settings page, and the ten NIN Modification tiers still ₦6,500 / ₦7,500 / ₦15,000 / ₦40,000 | no silent movement |
| 7.7 | Log for new fatals | `tail -100 storage/logs/laravel.log` | nothing since the deploy timestamp |
| 7.8 | The scheduler gained its third job | `php artisan schedule:list` | exactly three rows - `prices:sync` hourly, `wallet:sync-deposits` and `manual:recover-documents` every 5 minutes (section 5's table); two rows means the checkout did not land |
| 7.9 | The offline deposit audit, now that its fix is live | `php artisan deposits:audit --days=30 --references=0` | zero HTTP calls, per the regression test's promise; the pending-credit total matches the pre-deploy baseline count (production-checks §6); any new unattributed row is a held deposit for an admin to resolve - never a deleted row |

A live purchase of a real product (airtime to the owner's own number, one NIN lookup) is
**not** in this checklist by default: each one spends the owner's money. Ask first.

## 8. Integration requirements - what has to be configured before a customer can be served

Two different places hold the credentials, and mixing them up is how a deploy ends up with
a service that 500s or silently refuses. **Admin settings beat `.env`** wherever both exist
(`GsubzApi::__construct()` and `BvnApi` read `setting(..., config(...))`).

| Service family | Provider | Where its credential lives | Required |
|---|---|---|---|
| Airtime, data, recharge card, cable TV, electricity, exam pins, premium apps | GSUBZ (`POST /api/pay/`, `GET /api/verify/`, `/api/plans`, `/api/socialplans`, `/api/balance`) | admin **API Provider** card (`provider_gsubz_api_key`, `provider_gsubz_base_url`), falling back to `GSUBZ_API_KEY` / `GSUBZ_BASE_URL` in `.env` | yes |
| Which of those actually go out at all | - | `settings.provider` must be `gsubz` (or `alt`) | **see the warning below** |
| Alternative VTU API (only if it is ever switched on) | `provider_alt_api_key` / `provider_alt_base_url`, or `ALT_PROVIDER_*` | only if `provider = alt` |
| NIN search / phone / demographic, slip print, slip reports, validation | ConfirmIdent (`NinApi`) | `nin_api_key` in settings, `NIN_API_KEY` + `NIN_PRINT_ENDPOINT` / `NIN_REPORTS_ENDPOINT` / `NIN_VALIDATION_ENDPOINT` in `.env` | yes |
| BVN verify, details, slip | ConfirmIdent (`BvnApi`) | `bvn_api_key`, **falls back to the NIN key when blank**, plus `BVN_PRINT_ENDPOINT` | yes |
| BVN phone / BMS retrieval | ConfirmIdent | `bvn_api_key` **and** `BVN_RETRIEVE_AGENT_CODE` (our agent code, not the customer's) | only if those two are offered |
| Wallet funding - card, bank transfer, USSD | Flutterwave (`/v3/payments`, `/v3/transactions/{id}/verify`, `/v3/virtual-account-numbers`) | **`.env` only**: `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_PUBLIC_KEY`, `FLUTTERWAVE_SECRET_HASH`, `FLUTTERWAVE_ENCRYPTION_KEY` | yes |
| Deposit webhook | Flutterwave → `POST /wallet/flutterwave/webhook-v2` | the URL must be typed into the Flutterwave dashboard **with the same secret hash** as `.env`; the route is CSRF-exempt and answers `500` when the hash is unconfigured and `401` when it does not match | yes |
| SMS (alerts, bulk message) | `SMS_API_KEY` / `SMS_ENDPOINT` / `SMS_SENDER_ID` | `.env`; **absent from this repository's `.env` entirely**, so it is either set on the live box or the feature is dark | confirm on the box |
| Transactional mail | the `MAIL_*` block + `MAIL_FROM_ADDRESS` | `.env` | yes - password resets and receipts are email |
| JH Tech | `JHTECH_ENABLED` / `JHTECH_API_KEY` | off, by decision: the identity services run independently of them | - |

`php artisan optimize` caches `.env` into `bootstrap/cache/config.php`. **A `.env` edit does
nothing until `optimize` runs again.** Settings saved through the admin page take effect
immediately (`AdminController` flushes the settings cache on save).

### 8.1 Which identity routes are live versus staffed

`identity_verify_mode()` reads `nin_verify_mode` / `bvn_verify_mode`, and anything that is
not exactly `manual` is treated as `automatic`. The development database on 2026-10-16 held:

| Setting | Value there | What it means |
|---|---|---|
| `nin_verify_mode` | `automatic` | NIN search, print, reports and validation go to ConfirmIdent by themselves |
| `bvn_verify_mode` | `manual` | BVN work is staffed: the request becomes an admin job and no provider call is made |
| `provider` | `gsubz` | VTU really calls GSUBZ (see 8.2) |

**Confirm these three on the live box before you report the site as complete.** They decide
whether a customer is waiting on a machine or on an admin, and they change what the
ConfirmIdent billing question in
[confirmident-billing-policy.md](confirmident-billing-policy.md) is actually worth.

**Confirmed by the owner's live reads, 2026-10-16: `provider = gsubz`,
`nin_verify_mode = automatic`, `bvn_verify_mode = automatic`.** The live box is automatic
on both identity paths - every answered check is ₦160 (NIN) or ₦80 (BVN), which makes the
billing question in item 3 of section 9 live money rather than hypothetical. The table
above keeps the development values for contrast; the dev box held BVN at `manual`.

### 8.2 Mock (Test Mode) cannot take a customer's money any more

`settings.provider` has a third value, **Mock (Test Mode)**, and it is still selectable on
the admin page. Until 2026-10-16 it was the most expensive switch on the site: with `mock`
selected the wallet was debited first (`debitWallet()` sat above the branch that chose the
provider), the order was written `success`, referral commission was paid, nobody was called,
and the customer was told "Airtime purchase successful (Mock Mode)!". Any provider value this
site did not recognise - a typo, a retired name, an empty box - fell into the same fake
success. Seven routes were like that: airtime, data, recharge card, cable, electricity, exam
pin, premium app.

That is now closed in code, in `VtuController::refuseUnchargeableProvider()`, which every one
of the seven routes reaches **before** the wallet, before the order, and before the purchase
identity is spent:

- `gsubz` and `alt` are the only names a purchase may be charged through;
- `mock` completes a purchase only on a machine whose `APP_ENV` is `local` or `testing`, and
  even there it pays **no commission** and stamps `meta.simulated = true` on the order;
- anything else is refused with a 400, the balance untouched, no order row, and an admin
  alert that repeats at most once every twenty minutes;
- because the refusal happens before the identity is claimed, the same open form buys
  normally the moment an admin fixes the box - no reload, no second charge.

Proved by `tests/Feature/MockProviderChargeTest.php` (7 tests, 99 assertions: every route
refused on a live machine, unknown and empty names refused too, the identity reusable, mock
still rehearsing on `local`, no commission on a simulated sale, a configured provider still
selling, and the settings page saying so).

**What is still worth checking on the live box**, because the fix only protects what is
deployed:

```bash
cd /home/beloveds/vtu
php artisan tinker --execute="echo setting('provider');"
```

It must say `gsubz`. If it says `mock`, purchases are now refused rather than faked - the
customer keeps their money, and the site sells nothing until the box is changed. The admin
API Provider card now carries a banner saying exactly that, so the state cannot be read as
healthy by accident.

### 8.3 Wallet funding - the two things a missed deposit depends on

Reviewed line by line in [wallet-funding-review.md](wallet-funding-review.md); the short
version for this checklist is that a bank transfer becomes balance by the webhook or by
`wallet:sync-deposits`, and **both have a live-box precondition**:

- `FLUTTERWAVE_SECRET_HASH` unset means the webhook answers `500` to every event and credits
  nothing (`FlutterwaveController.php:234-237`);
- no `schedule:run` cron means the sweep never runs (section 5), and the sweep is the only
  thing that recovers a deposit whose webhook was lost during a 500 window;
- a transfer this site cannot attribute is logged and dropped, with no admin screen - the
  read-only `php artisan deposits:audit --days=30 --references=0` is what surfaces it. Keep
  the `--references=0`: without it the command asks Flutterwave about up to ten outstanding
  references, one live call each, which a read-only check must not do.

**One verified transfer can now only be paid out once, site-wide.** The three guards above
all keyed on *which wallet* was being credited, so the same charge could be credited again
through a second customer's Check deposit button; measured on 2026-10-16, one ₦3,000 transfer
put ₦5,900 into two wallets. The fix is `flutterwave_charge_claims` - one row per charge
identity with a unique index, taken inside the credit transaction before the balance moves.
Section 7.4 proves the table exists; after a deploy prove it is actually being written:

```bash
cd /home/beloveds/vtu
php artisan tinker --execute="echo DB::table('flutterwave_charge_claims')->count();"
```

The number is the count of deposits the site has already paid out. The migration backfills it
as it runs, so on a box with funding history it should be greater than zero the moment
`migrate` finishes. Zero on a box that has funded wallets means the backfill found nothing to
read and the guard protects only new deposits - worth knowing before a duplicate is reported
as impossible. The full reasoning, the tests and what the guard deliberately does **not**
claim are in section 3 of the review.

Run those four reads from section 8 of the review before telling anyone a deposit will
self-heal.

## 9. What still blocks a clean production claim

Recorded honestly, in the order they should be closed:

1. **Credential rotation** (section 0.4). The development machine was infected with a
   file-infector plus a credential stealer; anything typed on it since about June 2026 is
   to be treated as exposed. Until GitHub, cPanel, DB, GSUBZ, ConfirmIdent, Flutterwave and
   SMTP secrets have all been changed, "the site is secure" is not a claim I can make,
   whatever the tests say.
2. **The cron** (section 5) — **verified 2026-10-16**: the `schedule:run` entry exists and
   fires every minute. What remains is the post-deploy proof: `schedule:list` showing all
   three rows (7.8) and the log/heartbeat checks in section 5 reading clean once the new
   code is live.
3. **ConfirmIdent's billing policy for an answered "no"**
   ([confirmident-billing-policy.md](confirmident-billing-policy.md)) — free to ask about,
   and until it is answered the code holds identities, which costs the business ₦160 per
   ambiguous NIN lookup.
4. **The concurrency proofs versus the live engine** - `tests/Feature/MysqlMoneyPathConcurrencyTest.php`,
   twelve tests, pass against a real MySQL 8.4.3 in a database created for them (2026-10-16).
   The live engine is **MariaDB 10.6.28** with isolation **REPEATABLE-READ**, lock timeout
   **50 s**, and the four money tables on InnoDB (owner-read 2026-10-16) - the isolation and
   timeout match the pair the proofs ran under exactly (MySQL 8.4's defaults are the same two
   values), so the remaining delta is the engine implementation itself. **Recommendation
   (2026-10-17): no separate full test environment.** The minimal sufficient step is a
   MariaDB 10.6 instance (container or install) on the **trusted second machine**, running
   the twelve tests against an empty database there; the host-based alternative is a scratch
   database from `~/vtu` after the checkout, **only if** the host's vendor carries the test
   stack - the suite's own guards (driver, the `MONEY_TESTS_DATABASE` name, empty money
   tables) make it refuse production data. Never run the suite against the production
   database, and never run it from the compromised development box against the host's
   database: that would put live DB credentials on an exposed machine. Documented
   acceptance of the residual risk is the third option, and it is the owner's call -
   tracked as row C5 of the approval checklist.
   **Owner decision, 2026-10-17: the run happens.** A disposable, empty MariaDB 10.6 outside
   production, synthetic data only, never the live database or customer records. The
   development box cannot provide that environment, and this is recorded rather than glossed:
   no MariaDB distribution exists on it at all (`C:\laragon\bin` ships MySQL only), every
   other binary under that tree is on the infected list, and installing fresh vendor binaries
   on an uncleaned machine is what the rule above forbids - so the MySQL 8.4.3 pass is
   **not** read as MariaDB proof. What closes C5 now: the trusted second machine (pre-deploy),
   a scratch database on the host (post-deploy only - the suite files themselves do not exist
   on the box until this range's checkout lands, and a shared-hosting two-process lock test
   wants a quiet minute; it is production infrastructure, so it counts only if the owner reads
   "outside production" as including it), or documented acceptance. Until one of those
   happens, C5 stays open.
5. **The SparkTech API key** — their `/developer` surface is gated per account and a User
   login is told to contact support. Not our code; needs the owner to ask them.
6. **None of 8.2's protection exists on the live site until this checklist is run.** The Mock
   guard, the identity rules and the atomic debits are all commits on the development branch;
   `www.belovedsubp.com` still executes whatever `~/vtu` was last checked out to. Until
   sections 1-4 have run, the live site can still fake a success it was paid for.
7. **The customer-facing pages have never been walked through in a browser on the live box**
   after any deploy - only on the development copy. Section 7 is read-only by design; it does
   not prove a purchase works, because proving that spends the owner's money.

## 10. Roll back — the way out when the smoke test fails

Recorded before it is needed, because it is the moment judgement goes away. Run it in order,
exactly as written.

**0. The only step that must happen before the deploy:** write down the sha you are leaving.
Section 0 asks for it; without it there is no "back" to go to.

```bash
cd /home/beloveds/vtu && git rev-parse HEAD > /home/beloveds/last-good-sha.txt && cat /home/beloveds/last-good-sha.txt
```

1. **Take the site down, self-refreshing**, so a customer mid-purchase comes back on their
   own. Laravel 12.47 has no admin bypass token on `artisan down` - only `--redirect`,
   `--retry` and `--status`:

   ```bash
   cd /home/beloveds/vtu
   php artisan down --retry=15
   ```

2. **Go back to the recorded commit.** A plain checkout, not a `reset --hard`, and not a
   branch name - a branch is a moving thing and this is the one moment it must not move:

   ```bash
   cd /home/beloveds/vtu
   git checkout "$(cat /home/beloveds/last-good-sha.txt)"
   ```

3. **Do not roll the database back.** The migrations on this branch only add - the new
   `purchase_intents` and `document_filings` tables and nullable columns - and the older code
   simply ignores what it does not know. `migrate:rollback` would drop a column to gain
   nothing, and dropping a column on a busy table can lock it. This is the one instruction
   here that goes against the reflex.

4. **Nothing to re-copy in the docroot.** Section 3 only ever adds files and never
   overwrites, so the old build's entries are still there - including the hand-kept
   `public_html/index.php` shim, which is why that loop must never be made smarter.

5. **Bring the cache and the site back, then prove it:**

   ```bash
   cd /home/beloveds/vtu
   php artisan optimize
   php artisan up
   curl -s -o /dev/null -w '%{http_code}\n' https://www.belovedsubp.com/up
   ```

   `200` for `/up` and a `302` for `/settings` is the same pair section 7 uses.

6. **What a rollback does not undo:** any wallet movement that happened while the new code
   was running. Those are ledger rows in the database, not files, and they stay. A purchase
   held by the new identity rules survives the rollback as one orphan `purchase_intents`
   row - resolve it with the read-only query in
   [held-purchase-runbook.md](held-purchase-runbook.md), never by deleting the row, which is
   what turns a held request into a second bill.

7. **What a code-only rollback leaves behind, table by table for this range** - written in
   [deploy-preflight.md](deploy-preflight.md) section 10: held deposits invisible to the old
   UI, recovery paused for `document_filings`, claims made while the new code ran reverting
   to unclaimed, and migration 3's edit to saved definitions being irreversible by design.
   Resolve held money **before** rolling back; read the list before, not after, the moment
   judgement goes away.
