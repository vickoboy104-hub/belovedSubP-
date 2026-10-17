# Final functional audit - what each claim rests on

Audited 2026-10-16 on the development branch `codex/identity-first-jhtech-experience`, and every
line re-run on 2026-10-17 against head `69418773` (the full sqlite suite plus the twelve InnoDB
proofs on real MySQL).
Every line below names the test that proves it, in the form
`tests/Feature/File::method`. Nothing in this file is an opinion about behaviour I did not
run; where a claim is *not* proven by a test it says so.

Whole suite as it stands: **561 passed, 12 skipped, 4,144 assertions** (573 tests in total; sqlite,
in-memory, head `6f14c4e8`, re-run 2026-10-17, zero failures).

The 12 skips are all in `MysqlMoneyPathConcurrencyTest` when it runs on sqlite: those proofs
need two operating-system processes and row locks, which sqlite does not have. They are not
skips of convenience - the suite was re-run on **MySQL 8.4.3 on 2026-10-17, on this head
(`6f14c4e8`), and passed: 12 passed, 110 assertions, 18.29 s**, in an isolated second instance
on port 3307 against a scratch database that held nothing else, and an `information_schema` read
afterwards showed **27 tables, all of them InnoDB**, rows only in `migrations` (**35**, one per
migration file in the tree), with exact `COUNT(*)` zero in `users`, `orders`, `wallet_transactions`,
`wallet_settlement_links`, `admin_permission_grants` and `admin_activity_logs`. That run covers
`flutterwave_held_deposits` in both the engine check and
`::test_two_processes_hold_one_ambiguous_transfer_once`, where a second operating-system process
meets the first one's uncommitted hold and the outcome is one held record, no charge claim and
nobody paid, and because the scratch schema is built by running every migration in the tree, the two
tables `6f14c4e8` adds (`wallet_settlement_links`, `admin_permission_grants`) are created and proved
on real InnoDB rather than only on sqlite.

**What that run is not.** The live box reports **MariaDB 10.6.28**, not MySQL 8.4.3, and the only
run on that version was GitHub Actions run 38999509204 on `f2cfaa5f`, which does not contain this
batch. Approval row A6 carries that gap; no MariaDB number is quoted here for this head, because
MariaDB never produced one for it.

---

## 1. All seven VTU routes refuse an unavailable or mock provider without touching the wallet

`tests/Feature/MockProviderChargeTest.php`

| Method | What it forces |
|---|---|
| `::test_mock_never_charges_a_wallet_on_a_live_site` | Loops all seven `POST` routes (airtime, data, recharge card, cable, electricity, exam pin, premium apps) on a machine whose `APP_ENV` is `production`, with a real provider key present so the only fault is the provider box reading `mock`. Each must answer `400`, the balance must be exactly where it started, **zero** ledger rows, **zero** order rows, and `Http::assertNothingSent()`. |
| `::test_a_provider_name_this_site_cannot_call_is_refused_even_offline` | The same seven routes with the box reading `gsubz2` (a retired name), then empty. `400`, balance intact, no ledger row, no order. This is the case the old code read as permission to invent a success. |
| `::test_a_refused_purchase_leaves_the_form_free_to_buy_once_the_box_is_fixed` | The refusal happens **before** the purchase identity is spent, so the same open form, never reloaded, buys normally the moment an admin puts `gsubz` back. |
| `::test_mock_still_rehearses_purchases_on_a_development_machine` | Mock is not deleted - it is confined to `local`, and the order says so in its own `meta.simulated`. |
| `::test_a_simulated_purchase_pays_no_referral_commission` | A rehearsal that never delivered cannot pay a real commission. |
| `::test_the_settings_page_says_why_mock_blocks_every_sale` | The admin page states the outage instead of letting it be read as healthy. |

Guard in code: `VtuController::refuseUnchargeableProvider()`, reached by all seven routes
before `debitWallet()` and before the order insert.

## 2. Genuine provider purchases still work

| Proof | What it forces |
|---|---|
| `MockProviderChargeTest::test_a_configured_provider_still_sells_after_the_guard` | With `provider = gsubz` and a key, the five routes that price themselves from settings sell: order `success`, wallet decreased. |
| `MockProviderChargeTest::test_a_plan_that_comes_from_the_provider_list_still_sells` | **New in this pass.** Data and cable were the two routes no test had ever taken through a successful purchase, because their price comes from `GET /api/plans` rather than a settings box. This fakes that list in the shape GSUBZ really answers (`value`/`price`/`display_name`) and shows a ₦500 MTN bundle and a ₦3,400 DStv Padi both sell: one order, **one** wallet movement, and charged at the price the provider publishes - not the number the browser posted. It then asks for a plan the list does not carry and gets `422`, balance untouched, no order. |
| `ProviderConfigurationTest::test_purchases_are_sent_to_the_provider_once_a_key_is_configured` | `POST /vtu/airtime/buy` reaches the provider with the configured API key and leaves the wallet at the exact figure that one debit implies (51,000 kobo), order `success`. |
| `PurchaseIntentSafetyTest::test_one_intention_books_the_purchase_once_however_many_times_it_arrives` | A paid purchase identity replayed: one order, one movement, exact balance. |
| `PurchaseIntentSafetyTest::test_a_repeat_is_refused_before_the_provider_is_asked_again` | `Http::assertSentCount(1)` - the provider is never asked twice for one intention. |
| `ChargeableProviderSendTest::test_a_purchase_the_provider_refused_with_a_server_error_is_sent_once_only` / `::test_a_purchase_that_never_reached_the_provider_is_sent_once_only` | A timeout or a 500 from the provider does not turn into a second chargeable send. |

## 3. Wallet funding and deposit reconciliation

Arrival paths: card **checkout callback**, the **webhook**, the customer's **Check deposit**
button, and the five-minute **sweep**. Each now has a proof that it credits the amount that
actually arrived, less the ₦50 funding fee, and never credits the same money twice.

| Proof | What it forces |
|---|---|
| `WalletFundingAgreementTest::test_coming_back_from_the_checkout_credits_the_deposit_once_at_the_agreed_price` | **New.** The checkout return leg - previously untested end to end. A ₦2,000 card payment verified by `/v3/transactions/{id}/verify` credits **195,000 kobo**, writes one `success` row, takes two charge claims (`id` and `flw_ref`), and the customer arriving again from their own browser history is told "Wallet already funded" with the balance unchanged. |
| `WalletFundingAgreementTest::test_a_checkout_return_that_flutterwave_does_not_confirm_credits_nothing` | A cancelled/identifier-less return fails the pending row, banks nothing, and claims nothing. |
| `WalletDepositNotificationTest::test_a_short_transfer_is_not_credited_as_the_amount_requested` | ₦2,500 against a ₦3,000 request is **held**, not credited, and stays `pending` for the sweep. |
| `::test_a_larger_transfer_is_credited_on_what_actually_landed` | ₦3,500 arriving credits ₦3,450 - the fee is taken on what landed, not on what was promised. |
| `::test_a_transfer_belonging_to_somebody_else_is_not_credited` | Ownership is proved before money moves. |
| `::test_the_same_transfer_reported_twice_is_still_credited_once` | Reconciliation replay. |
| `FlutterwaveChargeIdempotencyTest` | Global charge uniqueness: see [wallet-funding-review.md](wallet-funding-review.md) section 3. The headline is `::test_a_transfer_that_could_belong_to_either_customer_credits_neither` - the bug that let one ₦3,000 transfer put ₦5,900 into two wallets, now proven to pay nobody automatically - and `::test_two_real_transfers_into_one_virtual_account_are_both_credited`, which is the test that stops the fix from throwing away a genuine second deposit. |
| `FlutterwaveDepositAttributionTest` (10 methods) | **New.** Ownership before money moves, end to end through the webhook, the sweep and the customer's Check deposit button: a shared account number credits neither candidate and records one held deposit (reason, gross amount, holders, full payload) with **zero** charge claims consumed; a transfer that belongs to a different customer is held as `wrong_wallet`; a deposit the customer asked for by their own `tx_ref` still pays them even when the number is shared; two genuine transfers into a single-owner account still both credit (295,000 + 145,000 = 440,000); webhook plus sweep competing over one transfer hold it **once**; admin resolution credits exactly once (replay refused, resolver and note recorded), a missing note and a non-candidate both fail validation, and dismissing keeps the record while blocking any later credit; Admin sees the queue, a member and a guest are refused. On the real engine, `MysqlMoneyPathConcurrencyTest::test_two_processes_hold_one_ambiguous_transfer_once` |
| `WalletSupersededDepositRecoveryTest::test_the_scheduled_check_credits_a_customer_who_never_opens_their_page` | `artisan wallet:sync-deposits` really banks a deposit the webhook lost. |
| `::test_the_audit_asks_about_missing_money_without_moving_any` | `deposits:audit` is read-only. |
| `::test_the_audit_with_zero_references_makes_no_http_call_at_all` | **New.** `deposits:audit --references=0` makes no HTTP request at all - run with Flutterwave fully configured and a pending deposit on the ledger, `Http::assertNothingSent()` holds, because a zero no longer clamps up to one live call. |
| `WalletLedgerSafetyTest::test_a_rolled_back_purchase_keeps_neither_money_nor_row` | A balance never moves without its ledger row, or the other way round. |

## 4. All ten NIN Modification options are live and priced

`NinModificationWorkflowTest`

- `::test_all_ten_corrections_are_on_offer_at_the_approved_retail_prices` - **new.** Names
  the ten (`name`, `phone`, `address`, `email`, `dob`, `name_phone`, `name_dob`,
  `name_email`, `name_address`, `dob_phone`), in order, each with a label, each priced at
  the approved tier: one detail ₦6,500, name with phone/email/address ₦7,500, date of birth
  with name or phone ₦15,000, date of birth alone ₦40,000. It also proves the page and the
  server agree: every option the dropdown offers is in the `in:` rule the controller
  validates against, and the rule accepts nothing the dropdown does not show. An option the
  server would refuse after charging is exactly the kind of bug a count test catches and a
  price test does not.
- `::test_each_correction_is_charged_at_the_rate_the_provider_prices_it` - the four tiers,
  from the customer's own answer.
- `::test_each_correction_is_asked_for_its_own_answers_and_no_others` - a name correction is
  never handed the attestation box, and a date-of-birth correction cannot be submitted
  without the uploaded attestation.

## 5. The customer path: submit, pay, watch it being worked, get the result

- `ManualIdentityQueueTest::test_customer_is_charged_and_the_request_lands_in_the_admin_queue`
  - `POST /vtu/manual/ipe_clearance` debits the wallet and lands on
    `GET /vtu/receipt/{order}`, and the request is in the admin queue.
- `ManualUploadPaymentSafetyTest::test_a_healthy_submission_still_charges_once_and_files_the_document`
  - a correct submission is charged exactly once and the document is stored against the order.
- `ManualOrderWorkStateTest::test_a_request_is_waiting_until_somebody_takes_it` and
  `::test_an_agent_takes_a_waiting_request_and_the_customer_reads_it_as_in_progress` - the
  customer's own pages (`/vtu/receipt/{id}`, `/vtu/orders`) say WAITING, then IN PROGRESS,
  because an agent took it - not because money arrived. Nothing is ever shown as successful
  because it was paid for.
- `ManualIdentityQueueTest::test_admin_types_a_result_and_the_customer_reads_it_on_the_receipt`
  - the admin's typed result appears on the customer's receipt, and
  `::test_admin_upload_is_only_downloadable_by_its_owner` covers `GET /vtu/receipt/{id}/result-file`.
- `ManualConsentGateTest::test_agreeing_to_the_terms_is_recorded_on_the_request_itself` - the
  consent the customer ticked is stored on the job, not just implied.

**Deliberate gap, not a bug:** a customer cannot re-open the document *they* uploaded - the
only document route is `GET /admin/manual-orders/{order}/document/{field}`
(`routes/web.php:146`), admin-only. The provider keeps the paper; the customer keeps their
own copy. If the owner wants a customer-side download, it needs its own signed route and a
decision, and I have not built one.

## 6. Admin: claim, process, complete, reject, refund

- Claim race: `ManualOrderWorkStateTest::test_two_agents_cannot_both_end_up_holding_the_same_request`
  (`POST /admin/manual-orders/{id}/claim`), `::test_the_second_conditional_write_of_a_race_changes_nothing_at_the_database`,
  `::test_only_an_admin_can_hold_a_request`.
- Complete: `::test_a_claimed_request_is_still_completed_by_the_agent_holding_it`,
  `AdminManualQueueNavigationTest::test_publishing_a_result_opens_the_next_waiting_request`,
  `::test_a_completed_request_only_appears_in_its_own_tab`,
  `::test_a_request_that_is_not_in_the_queue_is_not_editable`.
- Reject and refund exactly once: `ManualOrderAnswerSafetyTest::test_a_request_rejected_once_is_refunded_once_and_mourned_once`,
  `::test_a_refunded_request_cannot_also_be_given_a_result`,
  `::test_a_completed_request_cannot_be_rejected_into_a_second_refund`,
  `::test_a_refund_the_database_refuses_leaves_the_request_waiting`,
  `ManualIdentityQueueTest::test_rejection_refunds_the_customer_in_full`, and on the real
  engine `MysqlMoneyPathConcurrencyTest::test_one_refund_reference_cannot_pay_out_twice`.
- Holding a job must not move money: `::test_holding_a_request_moves_no_money_and_leaves_it_refundable`.
- One admin correcting another admin's published result still needs an audited reason
  (owner decision 2026-10-15; the publisher may self-correct without one).

## 7. Every field and every document reaches the admin, and nothing is public

`ManualIdentityQueueTest::test_the_admin_reads_every_answer_the_document_and_the_money` opens
`GET /admin/manual-orders/{id}` and asserts the service name, the chosen correction label,
**every** answer the customer typed, the uploaded filename, the link to the stored document,
the provider reference, the charged amount, the status, the submission timestamp, and the
customer's name and email.

Private by default:
- `NinModificationWorkflowTest::test_the_uploaded_attestation_reaches_the_agent_working_the_job_and_nobody_else`
  - another member asking for that document URL gets `403`, and a field the order never
  collected gets `404` rather than a peek into a different order.
- `NinModificationWorkflowTest::test_a_visitor_without_an_admin_seat_cannot_read_a_customers_document` -
  **new.** A guest with no session at all is not handed the file either, and the member who
  uploaded it cannot read it back through the admin route. Both the direct URL and the
  route-generated URL are refused; documents live outside `public/`.

## 8. No selling price moved; the layout moved on shared classes, and that is recorded here

- Prices are defined in `app/Helpers/settings.php` (`jhtech_price_reference()` and the
  `price_manual_*` table) and read through `ManualFulfilmentService::priceNaira()`; VTU plan
  selling prices come from `provider_plan_prices`. `IdentityPriceReferenceTest` (11 methods)
  pins retail ≥ provider cost for every key, the ₦160 / ₦80 provider costs, and that an owner
  override still works. The four NIN Modification tiers are pinned as in section 4.
- **Prices: unchanged, and this is checkable.** `git diff --name-only 0935a7f7..HEAD --
  app/Helpers/settings.php` returns nothing across all 48 commits of the deploy range, so no
  price key moved.
- **Layout: the older "no customer-visible layout change" line in this section is superseded,
  and I am correcting it rather than leaving it standing.** It was true of the four commits that
  existed when it was written (`resources/views/admin/settings.blade.php`, +26/−2, was then the
  only view touched). It is not true of this head. Measured over the three runtime commits above
  the reviewed anchor (`git diff --numstat cd19810c..HEAD`): `resources/css/app.css` **109 added /
  7 removed**, `resources/views/components/records-table.blade.php` **44 / 9**, and the CSS
  bundle swaps from `app-D-vIop72.css` (185,588 B) to `app-k_-jzmjF.css` (187,639 B). Those are
  **shared** classes and a **shared** component, and three customer pages render that component
  (`resources/views/vtu/manual-service.blade.php`, `resources/views/vtu/orders.blade.php`,
  `resources/views/wallet/transactions.blade.php`), so a phone holding a customer session sees
  new dimensions: drawer links, modal buttons and the close control reach 44 px, and a records
  table now takes a per-board `min-width` so a wide table scrolls instead of crushing.
  **What did not move:** no customer **view file** is edited in the range (the view diff lists
  nine `admin/` files, one new shared component and two changed shared components), no price, no
  brand value, no form field and no copy. `resources/js` is not in the range at all, and the JS
  bundle `app-DXuAyQnv.js` (106,512 B) is byte-for-byte the file already accounted for at the
  anchor.
- This audit pass adds no production code at all: the files it changes are the three test
  suites named above plus the two documents it writes itself
  (`MockProviderChargeTest`, `NinModificationWorkflowTest`, `WalletFundingAgreementTest`,
  `docs/functional-audit.md`, `docs/production-readiness.md`).
- **What came after the audit pass does.** The deposit ownership gate
  (`wallet-funding-review.md` section 3b) is production code and a new table:
  `WalletFundingService`, the `flutterwave_held_deposits` migration,
  `Admin/HeldDepositController`, its routes, the admin view and quick-nav entry, plus
  `FlutterwaveDepositAttributionTest` and the InnoDB hold proof. It changes what happens to a
  deposit, so it needs its own review before any deploy - the "audit touched no code" line
  above describes the audit pass itself, not this.

---

## What this audit does not prove

All of the above is **local**. None of it says the live site behaves this way, because the
live site runs whatever `~/vtu` was last checked out to.

1. **Nothing in this batch is deployed.** Not the mock guard, not the charge guard, not the
   ownership gate, not the atomic debits. A deploy is the owner's decision and needs `migrate`
   for both new tables, `flutterwave_charge_claims` and `flutterwave_held_deposits`
   ([deploy-checklist.md](deploy-checklist.md) sections 1 and 7.4).
2. **Three live-box settings decide whether deposits and identity jobs work at all**: the
   `schedule:run` cron, `FLUTTERWAVE_SECRET_HASH`, and the saved `provider` /
   `nin_verify_mode` / `bvn_verify_mode` values. Read them on the box; they cannot be proved
   from here.
3. **The MySQL proofs ran on a scratch 8.4.3, not on ListedHosting's engine** - its version,
   isolation level and `innodb_lock_wait_timeout` are still unmeasured.
4. **No real purchase, deposit or provider call was made** anywhere in this audit. Every
   provider in these tests is a fake, because a ConfirmIdent call costs ₦160 or ₦80 and a
   Flutterwave charge moves real money. The customer path has been walked in a browser on the
   development copy, never on the live box after a deploy.
5. **The credentials are still exposed.** This machine had a file infector plus a credential
   stealer; GitHub, cPanel, DB, GSUBZ, ConfirmIdent, Flutterwave and SMTP secrets all need
   rotating before anyone calls this production-ready.
