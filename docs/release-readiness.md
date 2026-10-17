# Release readiness - BelovedSubP, 2026-10-16

One report, nine areas. It answers a single question for each: **what is proven, by what, and
what still has to happen on the live box before a customer can be trusted with it.**

Nothing in this file is a deploy instruction. The command blocks live in
[deploy-checklist.md](deploy-checklist.md) and must be run there exactly as written - that
file says why retyping a proven block on the server took the site to HTTP 500 on 2026-10-11.
The read-only checks to run before anyone decides are in
[production-checks.md](production-checks.md).

## How to read the three statuses

| Mark | Meaning |
|---|---|
| **LOCAL** | A test on this branch forces the behaviour, and I ran it. Proves the code, not the live box. |
| **LOCAL-FAKE** | The route is exercised end to end, but against a faked provider or gateway. The shape of the request is asserted; the real service's answer never was. |
| **LIVE** | Cannot be proven from this machine at all. Somebody has to read it on `s3470`, or spend real money with the owner's say-so. |

Suite as it stands on this branch: **561 passed, 12 skipped, 4,144 assertions** (573 tests in
total; sqlite, head `6f14c4e8`, re-run 2026-10-17, zero failures). The 12 skips are the two-process
InnoDB proofs, which cannot run on
sqlite by design. They were **re-run on this head on 2026-10-17 against real MySQL 8.4.3** -
isolated second instance, port 3307, scratch database, synthetic rows only - and passed:
**12 passed, 110 assertions, 18.29 s**, with the scratch database read back afterwards as **27
tables, every one of them InnoDB** (including `admin_activity_logs`, `wallet_settlement_links` and
`admin_permission_grants`), **35 rows in `migrations`** against the 35 migration files in the tree,
and exact `COUNT(*)` zero in every money table - so the whole migration set on this branch has now
been applied on InnoDB, not only the ones that were there before.

**What that does not prove.** The live box is not MySQL 8.4.3, it is **MariaDB 10.6.28**, and the
only run on that version (GitHub Actions run 38999509204, 2026-10-17) executed on `f2cfaa5f`,
which is **before** the prior-settlement hold, the audit trail and this batch. So the number that
matters for the box has not yet seen this code. The route to it is a **hand-started** Actions run:
the workflow's push trigger is filtered to its own file, so the 2026-10-17 review push started
nothing, and `workflow_dispatch` needs a signed-in GitHub session this machine does not have. I am
not going to describe a MariaDB result that MariaDB never produced. Approval rows A6 and C5 carry
that gap and both routes to closing it.

## Where the code stands

Branch `codex/identity-first-jhtech-experience`. The **runtime** head on this machine is
`6f14c4e8` and the branch tip above it is documentation (`b54fde5b` plus the commit that records
it), none of it pushed; the remote head,
read again with `git ls-remote` on 2026-10-17, is `e32744d4`, and the **runtime** head on the remote
is `69418773` - everything between it and `e32744d4` is documentation. `6f14c4e8` has not been
pushed and no push is authorized for it.

| Commit | Pushed? | What it is |
|---|---|---|
| `ab8076b5` | yes | Refuse to pay out one bank transfer twice (the global charge claim). |
| `127f45df` | yes | The functional audit and the read-only production check list. |
| `d6931635` | **yes** | The deposit ownership gate, the held-deposit table, admin screen and 10 attribution tests. |
| `882188bb` | **yes** | The InnoDB re-proof of that gate plus the corrected verification figures. |
| `cd19810c` | **yes** | Last **runtime** commit reviewed: `deposits:audit --references=0` off the network, duplicate accounts counted without printing them. |
| `3d7deacb` | **yes - 2026-10-17, review only** | Docs: the read-only gateway-reference probe (§11.7). No code. |
| `f2d40bd2` | **yes - 2026-10-17, review only** | Hold a charge the wallet already dealt with instead of crediting it twice; 10 new tests, and it changes `WalletFundingService` itself. |
| `3be00d41` | **yes - 2026-10-17, review only** | The append-only administrator activity trail, its migration, the mandatory note on manual wallet movements, and the two owner-only screens. |
| `69418773` | **yes - 2026-10-17, review only** | The phone-usable admin boards, the read-side figure mask, the shared pager and the Transaction Explorer. This is the head the numbers above were measured on, and the **runtime head on the remote**. |
| `e32744d4` | **yes - 2026-10-17, review only** | Docs: every figure the last three commits made stale, re-measured. The current remote head. |
| `98c96cb1` | **no - local only** | Docs: the measurement showing which manual-credit channels the duplicate-credit hold cannot see (approval-checklist C9), and the widened §11.7 count. No code. |
| `6f14c4e8` | **no - local only, and no push is authorized for it** | Runtime: granular administrator permissions (27 slugs, the `admin_permission_grants` table, a `can_admin:` gate on the admin routes) and the explicit manual-settlement declaration (`wallet_settlement_links`), real `users.id` display, and the corrected `config/audit.php` comment about database triggers. Two migrations. 44 files, +3,756 / −152. |
| `b54fde5b` | **no - local only, and no push is authorized for it** | Docs only: seven files, all under `docs/`, zero runtime files. It re-measures everything `6f14c4e8` made stale and corrects two false claims - "573 passed" was really 561 passed plus 12 skipped, and row A5's "no customer view is touched" is contradicted by `resources/views/profile/show.blade.php`. The commit that adds this row is a second documentation commit above the same runtime head, also local, also unpushed. |

"Pushed" here means **on `codex/identity-first-jhtech-experience` for independent review and
testing**, under the owner's 2026-10-17 development-only authorization. It does not mean merged,
deployed, migrated or reviewed-and-approved; `main` is untouched at `17cb6eec…`.

`www.belovedsubp.com` still runs whatever `~/vtu` was last checked out to (`0935a7f7`): 48
commits behind the runtime head `6f14c4e8`, 49 behind this branch tip. None of the above is live.

The four runtime commits above the reviewed anchor touch **72 files** under `app/ tests/ database/ config/ routes/
resources/ bootstrap/ public/`, and they **do** change `resources/css` and `public/build`, so
there is an asset step in this batch. The layout delta is **not** admin-only, and the honest
wording is this: `resources/css/app.css` gained **109 lines and rewrote 7** (its diffstat is
116 changed lines) of size and tap-target rules on
**shared** classes - `.reference-nav-link` (the customer drawer), `.app-modal-btn` and
`.app-modal-close`, `.mobile-topbar-icon`, and `.records-table` now carries a computed
`min-width` - and the shared component `resources/views/components/records-table.blade.php` is
rendered by three customer pages (`resources/views/vtu/manual-service.blade.php`,
`resources/views/vtu/orders.blade.php`, `resources/views/wallet/transactions.blade.php`), so
those pages render at new dimensions on a phone. Exactly **one customer view file is edited in
the range**: `resources/views/profile/show.blade.php` shows the signed-in customer their own
`users.id` (one added line, existing markup, nothing editable) - the other fourteen changed views
are eleven under `admin/` and three shared components
(`git diff --name-status cd19810c..HEAD -- resources/views`). No price, brand, form field
or copy was changed in any commit on this branch, and `git diff --name-only 0935a7f7..HEAD --
app/Helpers/settings.php` returns nothing.

---

## 1. Customer payment and wallet funding

Four arrival paths, all of which pass through `WalletFundingService::creditFlutterwaveCharge`:
the checkout return (`wallet.fund` → `FlutterwaveController::callback`), the webhook
(`POST /wallet/flutterwave/webhook-v2`, `verif-hash` checked first), the customer's own
**Check deposit** button, and `wallet:sync-deposits` running from the scheduler every five
minutes.

| Claim | Status | Rests on |
|---|---|---|
| A ₦3,000 deposit credits 295,000 kobo - the ₦50 fee the customer agreed to, nothing else | LOCAL | `WalletFundingAgreementTest` |
| Coming back from checkout twice, or pressing the button twice, credits once | LOCAL | `WalletFundingAgreementTest`, `WalletDepositNotificationTest` |
| A transfer that lands short of what was requested is **not** credited as if it had arrived; it stays pending and the sweep finishes it | LOCAL | `WalletDepositNotificationTest::test_a_short_transfer_is_not_credited_as_the_amount_requested` |
| A transfer that lands large is credited on what landed, fee included | LOCAL | `::test_a_larger_transfer_is_credited_on_what_actually_landed` |
| One verified charge can never fund two wallets | LOCAL, and on InnoDB | `FlutterwaveChargeIdempotencyTest`; `MysqlMoneyPathConcurrencyTest::test_one_verified_transfer_cannot_fund_two_wallets_at_the_same_time` |
| A transfer more than one customer could claim pays **nobody** and is written down for an admin | LOCAL, and on InnoDB | `FlutterwaveDepositAttributionTest` (10 methods); `MysqlMoneyPathConcurrencyTest::test_two_processes_hold_one_ambiguous_transfer_once` |
| An admin can release a held deposit to one candidate, once, with a mandatory note, and dismissing keeps the record | LOCAL | `FlutterwaveDepositAttributionTest` |
| Two genuine transfers into one permanent account are both credited | LOCAL | `FlutterwaveChargeIdempotencyTest::test_two_real_transfers_into_one_virtual_account_are_both_credited` |
| No balance ever moves without its `wallet_transactions` row, and a rollback leaves nothing another connection can see | LOCAL, InnoDB | `WalletLedgerSafetyTest`, `MysqlMoneyPathConcurrencyTest` |
| A real Flutterwave payment reaches a real wallet | **LIVE** | Never tested against the gateway. `FLUTTERWAVE_SECRET_HASH`, the webhook URL registered at Flutterwave, and the `.env` keys are box facts - production-checks §3, §8. |
| The sweep actually runs | **LIVE** | Needs the `schedule:run` cron; unread. §4 below. |

Open decision that is not code: `users.virtual_account_number` is indexed but not unique, and
an admin can type any number into it. The gate refuses to guess; only a data migration
(measure the duplicates first, production-checks §7) removes the guessing.

## 2. All seven VTU purchase services

Airtime, data, recharge-card PIN, cable (DStv/GOTV/Startimes), electricity, exam pins
(JAMB/WAEC/NECO/NABTEB), and premium app subscriptions.

| Claim | Status | Rests on |
|---|---|---|
| Every one of the seven refuses when the saved provider is `mock`, blank or a retired name - `400`, balance untouched, **zero** ledger rows, **zero** orders, `Http::assertNothingSent()` | LOCAL | `MockProviderChargeTest` (7 routes looped) |
| The refusal happens before the purchase identity is spent, so the same open form buys normally once the box is fixed | LOCAL | `::test_a_refused_purchase_leaves_the_form_free_to_buy_once_the_box_is_fixed` |
| With a configured provider all routes still sell, charged at the price the provider publishes rather than the number the browser posted | LOCAL-FAKE | `MockProviderChargeTest`, `ProviderConfigurationTest`, `PurchaseIntentSafetyTest` |
| Data and cable - whose price comes from `GET /api/plans` - complete a real purchase | LOCAL-FAKE | `::test_a_plan_that_comes_from_the_provider_list_still_sells`; a plan absent from the list gets `422` |
| A chargeable send goes out exactly once, including on a timeout or a provider 500 | LOCAL, InnoDB | `ChargeableProviderSendTest`, `PurchaseIntentSafetyTest` |
| A GSUBZ account with no balance, a wrong key, or a changed answer | **LIVE** | Never attempted with a real key on purpose: a purchase spends the owner's float. |

## 3. All ten NIN Modification options

`name`, `phone`, `address`, `email`, `dob`, `name_phone`, `name_dob`, `name_email`,
`name_address`, `dob_phone`.

| Claim | Status | Rests on |
|---|---|---|
| All ten are on offer, in order, each labelled, at the approved tiers: one detail ₦6,500, name with phone/email/address ₦7,500, date of birth with name or phone ₦15,000, date of birth alone ₦40,000 | LOCAL | `NinModificationWorkflowTest::test_all_ten_corrections_are_on_offer_at_the_approved_retail_prices` |
| Page and server agree: every option the dropdown offers passes the controller's `in:` rule, and the rule accepts nothing the dropdown hides | LOCAL | same test |
| Each correction asks for its own answers and no others; a date-of-birth correction cannot be submitted without its attestation | LOCAL | `::test_each_correction_is_asked_for_its_own_answers_and_no_others` |
| Each is charged at the rate the provider prices it | LOCAL | `::test_each_correction_is_charged_at_the_rate_the_provider_prices_it` |
| A correction really goes through ConfirmIdent | **LIVE** | Every call costs ₦160. Never made from this machine without the owner asking. |

## 4. Other NIN and BVN services

NIN validation, NIN search and slip print, BVN verification, BVN details with the customer's
acknowledgement, BVN slip sheet, IPE clearance and the other staffed manual services.

| Claim | Status | Rests on |
|---|---|---|
| Identity routes refuse `mock`/unconfigured/retired providers and paused services instead of inventing a success | LOCAL | `MockProviderChargeTest`, `PausedIdentityServiceTest`, `ServiceOutageTest` |
| A failed verification does not debit the customer, and an ambiguous identity lookup is held rather than re-sent | LOCAL | `PurchaseIntentSafetyTest`, `ChargeableProviderSendTest`, `IdentityProfitTest` |
| NIN slip and BVN slip render our own A4 page and print alone; the BVN details page waits for the customer's acknowledgement | LOCAL | `NinSlipPrintTest`, `BvnDetailsAndSlipTest`, `BvnServicesPageTest` |
| Saved admin service definitions reach the customer form without resetting admin work | LOCAL | `SavedServiceFormReconciliationTest`, `ServiceFormCascadeTest`, `AdminServiceFormEditorTest` |
| Prices stay ≥ provider cost, ₦160 NIN / ₦80 BVN, and an owner override still works | LOCAL | `IdentityPriceReferenceTest` (11 methods), `IdentityPriceLockTest` |
| Whether the live box is in `automatic` or `manual` mode for NIN and BVN | **LIVE** | Development held `automatic` / `manual` on 2026-10-16. The live values are unread and they decide whether a customer waits on a machine or on a person. production-checks §2. |
| ConfirmIdent's answer to "is a failed check billed?" | **LIVE** | Still open with the provider - [confirmident-billing-policy.md](confirmident-billing-policy.md). Until they reply, holding identities is the safe answer and it costs money. |

## 5. Admin processing and the result the customer reads

| Claim | Status | Rests on |
|---|---|---|
| A paid manual request lands in the admin queue and the customer's own pages read WAITING, then IN PROGRESS because an agent took it - never "success" because money arrived | LOCAL | `ManualOrderWorkStateTest`, `ManualIdentityQueueTest` |
| Two agents cannot both end up holding one request; claim, release and transfer are conditional writes; the second write of a race changes nothing at the database | LOCAL, InnoDB | `ManualOrderWorkStateTest`, `MysqlMoneyPathConcurrencyTest` |
| Completing publishes the typed result, which the customer reads on the receipt; a result file is downloadable only by its owner | LOCAL | `ManualIdentityQueueTest`, `AdminManualQueueNavigationTest` |
| Rejection refunds exactly once; a refunded request cannot also be given a result; a completed one cannot be rejected into a second refund; a refund the database refuses leaves the job waiting | LOCAL, InnoDB | `ManualOrderAnswerSafetyTest`, `MysqlMoneyPathConcurrencyTest::test_one_refund_reference_cannot_pay_out_twice` |
| One admin correcting another admin's published result needs an audited reason; the publisher may self-correct without one; the completion notice is never duplicated | LOCAL | `AdminManualQueueNavigationTest`, `ManualOrderAnswerSafetyTest` (owner decision 2026-10-15) |
| The admin sees every answer, the document, the money, the provider reference, and nobody outside can read any of it (member `403`, guest `403`, unknown field `404`) | LOCAL | `ManualIdentityQueueTest`, `NinModificationWorkflowTest` |
| Held wallet deposits have a screen, a badge, candidates, a mandatory note and a status change instead of a delete | LOCAL | `FlutterwaveDepositAttributionTest`, `resources/views/admin/held-deposits.blade.php` |
| An agent actually working the real queue on the live box after a deploy | **LIVE** | Never walked on `www.belovedsubp.com`. |

Deliberate gap, not a bug: a customer cannot re-download the document *they* uploaded. Making
that possible needs a signed route and a decision from the owner.

## 6. Document recovery

| Claim | Status | Rests on |
|---|---|---|
| A filing whose document was never attached is recovered, and a paid order is not left stranded by a failed upload | LOCAL | `InterruptedDocumentRecoveryTest`, `ManualUploadPaymentSafetyTest` |
| Two recovery runs cannot work the same paid filing at once (row lock under `withoutOverlapping`) | LOCAL, InnoDB | `MysqlMoneyPathConcurrencyTest::test_two_recovery_runs_cannot_work_the_same_paid_filing_at_once` |
| A filing recorded as LOST is protected from automatic pruning until an admin resolves it | LOCAL | `LostDocumentFilingProtectionTest` |
| Private storage really is private on the host | **LIVE** | Tests prove the route refuses; on the box, confirm `readlink ~/public_html/storage` does not point at `storage/app`. |

## 7. Scheduled jobs

Three commands are registered in `bootstrap/app.php`, verified against this branch on the
development copy: `prices:sync` hourly, `wallet:sync-deposits` every five minutes,
`manual:recover-documents` every five minutes, all `withoutOverlapping()`.

| Claim | Status | Rests on |
|---|---|---|
| The sweep banks a deposit the webhook lost, for a customer who never opens their page | LOCAL | `WalletSupersededDepositRecoveryTest` |
| The sweep stops looking: 12 candidates, 8 references per pass, abandoned after 60 minutes, 14 days back | constants read in code, **not** test-proven | `WalletDepositSync.php:30,33,40,49`; behaviour documented in `docs/wallet-funding-review.md` §4 |
| Recovery runs on paid orders owed paper | LOCAL | as §6 |
| **A cron exists on the live box at all** | **LIVE** | The `crontab -l` line filtered for comments has never been read here. Without a `schedule:run` entry, the sweep and both recovery jobs do not run and the webhook - the path that depends on `FLUTTERWAVE_SECRET_HASH` - is the only route money takes. production-checks §4. |
| Prices are being synced from the provider | **LIVE** | Needs the cron and a working key; the hourly heartbeat is `provider_plan_prices.last_synced_at`. |

## 8. Provider configuration

| Item | Status | Note |
|---|---|---|
| Which provider is saved (`gsubz` / `mock` / retired / empty) | **LIVE** | `setting('provider')`; admin settings beat `.env` wherever both exist. Any value this code cannot call now refuses every sale before the wallet is touched. |
| GSUBZ key | **LIVE** | presence only in the report-back; never printed. |
| ConfirmIdent NIN / BVN keys, and BVN falling back to the NIN key | **LIVE** | presence only. |
| `nin_verify_mode`, `bvn_verify_mode` | **LIVE** | Development held `automatic` / `manual`. |
| Flutterwave `.env` keys and `FLUTTERWAVE_SECRET_HASH` | **LIVE** | Empty hash = the webhook answers `500` to everything and credits nothing. |
| Prices | LOCAL | Every selling price in this batch is unchanged; the tiers and the ₦160/₦80 costs are pinned by `IdentityPriceReferenceTest` and the NIN Modification test. |
| The credential exposure behind all of the above | **LIVE, owed** | This machine carried a file infector with a credential stealer. The owner is handling rotation separately; it stays a release requirement, not something a green test suite can clear. |

## 9. Deployment, smoke testing and rollback

| Step | Status | Where it is written |
|---|---|---|
| The exact deploy order, including the docroot copy that has bitten every deploy (`public_html` is a hand-kept copy of `vtu/public`, so new assets 404 after a perfect `git pull`) | - | deploy-checklist §1-§3, run verbatim |
| The three tables this branch adds (`flutterwave_charge_claims`, `flutterwave_held_deposits`, `admin_activity_logs`) arrive by `git checkout` **then** `php artisan migrate --force`; `migrate:status` must show all **eight** migrations of the range as `Ran` | LOCAL migration, LIVE run | deploy-checklist §1.3 and §7.4, preflight §2 and §2b |
| The migrations only add rows; no pre-existing ledger row or balance is rewritten | LOCAL | the claim-backfill test and `held_deposits` having **no** backfill at all |
| **CSS changed in this batch, so the built bundle is what ships.** `resources/css/app.css` gained the mobile rules and `public/build/assets/app-k_-jzmjF.css` (187,639 bytes) is force-committed with it. `/public/build` is gitignored and only ever arrives by `git add -f`, so a checkout without that file renders an unstyled site - and rebuilding on the box instead would produce a different hash and a manifest that disagrees with the docroot copy | LOCAL, built and committed on the development machine | deploy-checklist §2 and §3; the byte-size comparison in §7.2 |
| `sw.js` `CACHE_VERSION` bumps in the same commit as any unhashed asset change | not needed here | production-readiness §4 |
| The deploy **ends** with `php artisan optimize` | - | deploy-checklist §4 |
| Post-deploy smoke test, read-only first (HTTP 200, matching hashed CSS bytes, migrate:status, `deposits:audit --references=0`, the Held Deposits screen, log tail, `setting('provider')`, one admin queue row) | - | production-readiness §8 |
| Rollback: `~/last-good-sha.txt` written **before** deploying, then `artisan down --retry=15` → checkout that sha → `optimize` → `up`. Never `reset --hard`, never `migrate:rollback`, never `rsync --delete` | - | deploy-checklist §10 |
| What rollback cannot undo: wallet movements. A held deposit or held purchase is resolved from its record, never by deleting the row | LOCAL | [held-purchase-runbook.md](held-purchase-runbook.md) |
| A real purchase, a real NIN check and a real deposit on the live site | **LIVE, needs an explicit yes** | Each spends the owner's money. Deliberately absent from the smoke list. |

---

## The decision this leaves on the table

**Safe to ship as a set of guards.** Every behaviour change in the runtime commits now on the review
branch makes money *harder* to move wrongly: an ambiguous deposit now pays nobody and waits for a
human; a duplicate charge is refused at a unique index; a charge the wallet already dealt with is
held instead of paid twice; a mock or retired provider refuses before the wallet is touched. What is
NOT true, and was said here before it was measured: no change to a price or a customer form is real
(prices untouched, `app/Helpers/settings.php` diff empty across the range), but **the layout did
move on shared classes**, so customer pages render differently on a phone and an asset step exists
in this batch - row A5 and §8 above carry that.

**Not yet cleared for a deploy**, and none of it is code:

1. the three runtime commits are on the review branch as of 2026-10-17 and have **not been reviewed
   as a deploy target**; the reviewed runtime anchor is still `cd19810c` (row A1);
2. answered since this list was first written - `crontab -l`, `FLUTTERWAVE_SECRET_HASH`, `provider`,
   `nin_verify_mode` and `bvn_verify_mode` are all owner-read and recorded in approval rows B2-B11;
3. hosting's engine is now read (**MariaDB 10.6.28**, isolation REPEATABLE-READ, lock timeout 50 s,
   row B3-B4), but the twelve two-process proofs have only run on scratch MySQL 8.4.3; the run on
   the live engine's own version has to be started by hand because the workflow's push trigger is
   path-filtered (rows A6 and C5);
4. how many live customers already share one virtual account number is unmeasured
   (production-checks §7) - that number decides whether the held-deposit queue opens with two
   rows or two hundred;
5. credential rotation is owed and owned by the owner, separately;
6. ConfirmIdent's billing answer for a failed check is still open;
7. the duplicate-credit hold does not see either admin credit channel, so a manual help followed by
   a gateway report of the same money is still a live double-credit path. The proposed closure needs
   an explicit admin-declared link, never amount or account matching - approval row C9, and it is a
   money-path change, so it waits on the owner.

The honest sequence is: push on approval → run the read-only checks → decide the deploy with
the five answers in hand → migrate → smoke test read-only → then, only with a yes, one real
₦100 airtime purchase and one real ₦500 deposit to prove the two paths that no test on this
machine can prove.
