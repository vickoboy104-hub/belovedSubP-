# Deploy and go-live verification checklist

For `belovedsubp.com` on ListedHosting shared cPanel (host `s3470`, user `beloveds`,
LiteSpeed, PHP 8.3.33 CLI, app repo `/home/beloveds/vtu`).

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
| 0.1 | The branch you are deploying is the reviewed one | `git -C /home/beloveds/vtu rev-parse origin/codex/identity-first-jhtech-experience` | `afb3260d54d5e6c58402ed8ba1bb75cdca3c7cbb` or a descendant the owner has read |
| 0.2 | It is a fast-forward from what is live | `git merge-base --is-ancestor <live-HEAD> <new-HEAD>; echo $?` | `0`. If it is not `0`, **stop** and work out why before pulling |
| 0.3 | The full suite is green on the machine that built it | `php artisan test` | no failures, and the 10 MySQL concurrency tests run somewhere before a money-path change ships (section 4) |
| 0.4 | Every credential has been rotated **after** the machine cleaning | owner's list: GitHub, cPanel, FTP, DB, GSUBZ, ConfirmIdent, Flutterwave, SMTP | done. Until it is done this deploy is **not** cleared for production - see section 6 |

---

## 1. Pull the code (order matters, and it has been got wrong before)

```bash
cd /home/beloveds/vtu
git fetch origin
git checkout codex/identity-first-jhtech-experience
git merge --ff-only origin/codex/identity-first-jhtech-experience
php artisan migrate --force
```

- **`migrate` comes after the checkout, never before.** A migration added in the commits
  being deployed does not exist on disk until the checkout finishes; run early and it
  prints a reassuring "Nothing to migrate" while the schema stays behind the code, which
  then 500s on the first write.
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
| 7.4 | Money-safety migrations ran | `php artisan migrate:status \| grep -E 'record_purchase_intents\|record_document_filings\|flutterwave_charge_claims'` | all three `Ran` - the last one is the table that stops a single bank transfer funding two wallets |
| 7.5 | Held-purchase discovery still works | run section 3 of [held-purchase-runbook.md](held-purchase-runbook.md) | returns without error (zero holds is a pass) |
| 7.6 | Prices unchanged from the approved list | admin settings page, and the ten NIN Modification tiers still ₦6,500 / ₦7,500 / ₦15,000 / ₦40,000 | no silent movement |
| 7.7 | Log for new fatals | `tail -100 storage/logs/laravel.log` | nothing since the deploy timestamp |

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
2. **The cron** (section 5) — unverified as of this writing.
3. **ConfirmIdent's billing policy for an answered "no"**
   ([confirmident-billing-policy.md](confirmident-billing-policy.md)) — free to ask about,
   and until it is answered the code holds identities, which costs the business ₦160 per
   ambiguous NIN lookup.
4. **The MySQL concurrency proofs** - `tests/Feature/MysqlMoneyPathConcurrencyTest.php`, ten
   tests - now pass against a real MySQL 8.4.3 in a database created for them (2026-10-16),
   but they have **never been run against the live engine's own configuration** (its version,
   its isolation level, its `innodb_lock_wait_timeout`). Repointing one run at a scratch
   database *on ListedHosting* is the remaining piece; the commands are printed inside
   `howToRun()` in that file.
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
