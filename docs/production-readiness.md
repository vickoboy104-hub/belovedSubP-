# Production readiness - the short list

One page. Each line is a thing to **check on the live box**, with the answer that counts as a
pass. Nothing here is a deploy: [deploy-checklist.md](deploy-checklist.md) holds the exact
command blocks, and this file only points at them. Never retype or "improve" a block from
that file while standing on the server - that is how the site went to HTTP 500 on 2026-10-11.

State as of 2026-10-16: everything in the left column is proven **locally**; the right column
is not proven **anywhere** until somebody runs it on `s3470`.

---

## 1. Provider configuration

| Check | Pass looks like | Detail |
|---|---|---|
| `setting('provider')` on the live box | `gsubz` | deploy-checklist §8.2. `mock` or anything unrecognised now **refuses every VTU sale before the wallet is touched** - safer, but the site sells nothing until the box is right. |
| GSUBZ key present | admin **API Provider** card filled (`provider_gsubz_api_key`), or `GSUBZ_API_KEY` in `.env` | Admin settings beat `.env` wherever both exist. |
| ConfirmIdent keys | `nin_api_key`; `bvn_api_key` or the NIN key as its fallback | Every check costs the owner ₦160 (NIN) or ₦80 (BVN). |
| `nin_verify_mode` / `bvn_verify_mode` | known and written down, not guessed | They decide whether a customer waits on a machine or on an admin. Development held `automatic` / `manual` on 2026-10-16; the live box is unmeasured. deploy-checklist §8.1. |
| Prices | the four NIN Modification tiers read ₦6,500 / ₦7,500 / ₦15,000 / ₦40,000 and no selling price moved | No owner approval has ever been given for a price change. |

## 2. Flutterwave secret hash

| Check | Pass | Detail |
|---|---|---|
| `FLUTTERWAVE_SECRET_HASH` in `.env` on the live box | present, and the **same string** as the "Webhook verification hash" in the Flutterwave dashboard | wallet-funding-review §2. Empty means the webhook answers `500` to every event and **credits nothing** - the single most likely cause of a deposit sitting pending. |
| `FLUTTERWAVE_SECRET_KEY` / `PUBLIC_KEY` | present (`.env` only - the admin pages do not hold them) | deploy-checklist §8. |
| Webhook URL registered at Flutterwave | `https://www.belovedsubp.com/wallet/flutterwave/webhook-v2` | CSRF-exempt, `401` on a hash mismatch so Flutterwave retries. |
| A `500` from the hook is not treated as loss | it is a retry | Do not "fix" the 500-by-design behaviour in a passing deploy (wallet-funding-review §7). |

## 3. Laravel scheduler and cron

| Check | Pass | Detail |
|---|---|---|
| `crontab -l \| grep -v '^#'` | contains a `schedule:run` line | **Unverified on the live box as of today.** Without it, `wallet:sync-deposits` never runs and the webhook is the only route a deposit can take. |
| `php artisan schedule:list` | exactly three: `prices:sync` hourly, `wallet:sync-deposits` */5, `manual:recover-documents` */5 | Verified against this branch on the development copy 2026-10-16. |
| The cron writes somewhere observable | `~/schedule.log` grows | deploy-checklist §5 explains the log-instead-of-`/dev/null` line and why. |
| Second heartbeat | `provider_plan_prices.last_synced_at` moves within an hour of the cron going in | Proves it runs as the user who owns the app. |

## 4. Service worker and cached pages

| Check | Pass | Detail |
|---|---|---|
| `public/sw.js` `CACHE_VERSION` | bump it **in the same commit** as any change to `manifest.webmanifest`, `icons/*`, `favicon.ico` or `images/*` | Those URLs are unhashed and served cache-first, so a returning visitor gets the old file. Vite's bundle is content-hashed and never needs this. |
| Navigate requests | come from the network | `sw.js` returns early for `request.mode === 'navigate'`, so no customer can be stuck on a stale purchase form. |
| Docroot copy after any `sw.js` change | `cmp vtu/public/sw.js public_html/sw.js` says same | `~/public_html` is a hand-kept copy, not a symlink (deploy-checklist §3). |

## 5. Database migrations

| Check | Pass | Detail |
|---|---|---|
| Order | `git checkout` **then** `php artisan migrate --force` | A migration added by the commits does not exist on disk before the checkout; running `migrate` early prints a false "Nothing to migrate". |
| `php artisan migrate:status` | `record_purchase_intents`, `record_document_filings` and `flutterwave_charge_claims` all `Ran` | The last one is the table that stops one bank transfer funding two wallets. |
| `flutterwave_charge_claims` is being written | its row count is greater than zero on a box with funding history | The migration backfills one claim per charge identity from the existing ledger, so a deposit banked before the deploy cannot be banked again. deploy-checklist §8.3. |
| No data was rewritten | every pre-existing `wallet_transactions` row is untouched, balances unchanged | The guard only adds rows. Proven by test, not by argument. |
| `migrate:rollback` | **not run** | These migrations only add; rolling back drops a column to gain nothing and can lock a busy table. deploy-checklist §10.3. |

## 6. Hosting file permissions

| Check | Pass | Detail |
|---|---|---|
| Ownership | everything the app writes is owned by `beloveds`, no world-writable file | Shared cPanel: LiteSpeed runs PHP as the account user, so `755` directories and `644` files are enough. Do not `chmod -R 777` anything to "fix" a write error - find the failing path instead. |
| Writable by the app | `storage/` and `bootstrap/cache/` accept writes | A failure here shows as a 500 on the first log line or a stale config cache. |
| Identity documents | live under `storage/app/private` and are served only through `VtuController`'s authorised route | `config/filesystems.php:35` roots the `local` disk there; `public/storage` symlinks to `storage/app/public`, so private uploads are not reachable by URL. Tests prove a guest and a non-admin member are refused; on the box, confirm `readlink ~/public_html/storage` does **not** point at `storage/app`. |
| Docroot shims | `public_html/index.php`, `.htaccess`, `robots.txt` are never overwritten | They are hand-edited absolute-path files; the copy loop is additive only. |
| `.env` | outside the docroot, never committed, and unreadable to the world | It holds every live secret. |

## 7. Safe deployment and rollback

| Check | Pass | Detail |
|---|---|---|
| Write down the commit you are leaving, **before** deploying | `~/last-good-sha.txt` exists and holds a sha | deploy-checklist §10.0. Without it there is no "back". |
| The branch is fast-forward from live | `git merge-base --is-ancestor <live> <new>` prints `0` | Anything else means stop and find out why. |
| No `composer install` | unless `composer.json`/`composer.lock` actually changed | |
| No `npm run build` on the server | assets are force-committed | `public/build` is gitignored but shipped; rebuild on the development machine after `php artisan view:cache`, and compare byte sizes. |
| The deploy **ends** with `php artisan optimize` | not `optimize:clear` | Config and route cache are live on this host; `/settings` answering `302` rather than `404` is the proof the route cache took. |
| Rollback | `artisan down --retry=15` → checkout the recorded sha → `optimize` → `up` | Never `reset --hard`, never a branch name, never `migrate:rollback`, never `rsync --delete`. |
| What a rollback does not undo | wallet movements stay | They are ledger rows, not files. Resolve a held purchase with the read-only query in [held-purchase-runbook.md](held-purchase-runbook.md), never by deleting the row. |

## 8. Live smoke tests, in order

Read-only until the owner says otherwise. Each one spends real money or touches a live row if
it is run casually.

1. `curl -s -o /dev/null -w '%{http_code}' https://www.belovedsubp.com/up` → `200`.
2. Newest `public/build/assets/*.css` name from `git ls-tree` and from the live site → same
   bytes, HTTP 200 (this is the check that catches the docroot copy problem).
3. `/login` in a browser, view source → the `build/assets` names match the deployed manifest.
4. `php artisan migrate:status | grep -E 'purchase_intents|document_filings|flutterwave_charge_claims'` → all `Ran`.
5. `php artisan deposits:audit --days=30` → read "Written off as never arriving"; a row whose
   account number nobody holds is money that will not self-heal.
6. `tail -100 storage/logs/laravel.log` → nothing new since the deploy timestamp.
7. `php artisan tinker --execute="echo setting('provider');"` → `gsubz`.
8. Admin queue: one manual request visible with all its fields and its document link; the
   customer's own receipt shows WAITING, not success.

**Not in this list on purpose:** a real airtime purchase, a real NIN check, a real deposit.
Each costs the owner money (or moves it), so they need an explicit yes first
(deploy-checklist §7).

---

## What still blocks the words "ready for production"

1. **Credential rotation** - GitHub, cPanel, FTP, DB, GSUBZ, ConfirmIdent, Flutterwave, SMTP.
   This development machine carried a file infector with a credential stealer; anything typed
   on it since about June 2026 is exposed. Tests cannot clear this.
2. **Nothing on this branch is deployed.** The mock guard, the charge guard, the atomic
   debits and the identity rules are commits; `www.belovedsubp.com` runs whatever `~/vtu` was
   last checked out to.
3. **The cron and the three settings in §1/§2/§3 are live-box facts** nobody has read yet.
4. **The MySQL concurrency proofs ran on a scratch 8.4.3**, not on ListedHosting's engine with
   its own version, isolation level and `innodb_lock_wait_timeout`.
5. **ConfirmIdent's billing policy for an answered "no" is still an open question to the
   provider** - [confirmident-billing-policy.md](confirmident-billing-policy.md). Until they
   answer, the code holds identities, which costs ₦160 per ambiguous NIN lookup.
6. **No customer-facing page has been walked through on the live site after a deploy.**
7. Two features are documented and deliberately **not** built: an admin "stuck deposits"
   screen, and a rule for which customer a *shared* virtual account number belongs to. The
   charge guard now stops the money being paid twice, but the wrong customer can still be the
   one paid once.
