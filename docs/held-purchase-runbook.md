# Held purchases: how an administrator investigates and closes one

Written for whoever runs Admin on this site. It assumes you can read the site's
database and its log file, and it assumes you will **not** send a paid provider
request twice in order to find out what happened.

Related code: `app/Services/PurchaseIntent.php`, `intentAfterProviderFailure()` and
`providerNeverAsked()` in `app/Http/Controllers/VtuController.php`,
`billableClient()` in `app/Services/GsubzApi.php`.

---

## 1. Why some unclear answers are held and others are not

Asking a provider for something costs this site money before it costs a customer
anything, and the three kinds of ask differ in what you can find out afterwards.

| What is asked | Who answers | What it costs us | Is there a reference to look up later? | What an unclear answer does |
| --- | --- | --- | --- | --- |
| NIN lookup, NIN slip, BVN check, BVN slip | ConfirmIdent | ₦160 a lookup, ₦80 a BVN check, per call | **No.** A failed lookup returns nothing we can query later. | **HELD.** No order, no debit, the request identity stays spent. |
| NIN validation | ConfirmIdent | ₦700-₦1,000 | Yes, our own `requestID` on the order | Booked as a **pending** order, then either kept pending (their wording says it is queued) or refunded. |
| Airtime, data, TV, electricity, exam PIN | GSUBZ / alt | the value supplied | Yes, the `requestID` we sent, and `/api/verify/` answers for it | Booked as a **pending** order, reconciled by that reference, then completed or refunded. |

ConfirmIdent gives us no handle on a failed lookup, so the site refuses to guess:
the request is treated as one they may have billed, and it is never repeated. GSUBZ
and validation give us a reference, so those are reconciled rather than held.

Either way the customer is never charged for a provider answer that brought them
nothing. The rule that stands behind this is the owner's: **a failed verification
must not debit the customer.** If that leaves a ₦160 bill with us, the business eats
it rather than risk billing a person twice.

## 2. What a held lookup leaves behind — all of it

A held NIN or BVN ask leaves **three** traces and nothing else:

1. One row in `purchase_intents` (`user_id`, `token`, `created_at`) with **no** row in
   `orders` carrying that `intent_token`.
2. A warning in `storage/logs/laravel.log` (or `laravel-YYYY-MM-DD.log`) with one of
   these exact phrases: `NIN provider request failed`, `NIN provider connection
   failed`, `BVN provider request failed`, `BVN provider connection failed`. The entry
   carries the endpoint, the HTTP status, the provider's own response body and the
   payload *field names* - never the customer's NIN or BVN.
3. Nothing else. **No order, no wallet movement, no ledger row.** The customer's
   balance is exactly what it was, so there is nothing to refund and nothing to
   adjust. Please do not "correct" a wallet in this situation; you would be creating a
   payment that never happened.

If the provider reported its *own* trouble (a down service or an empty wallet) there is
also an admin notification, titled *"NIN provider is refusing requests"* or *"URGENT:
NIN provider wallet needs funding"*. Those are throttled to one per kind per 20 minutes,
so they are a signal, not a complete record.

What the customer saw was one of these two sentences, and it is worth quoting them
back to a customer who asks:

> *We could not get through to the verification service and do not know whether it took
> the request. Nothing has been charged to your wallet. Do not send this again - reload
> the page to start a fresh request, and if you are charged for it, tell us and it will
> be put right.*

> *\<the provider's own reason\> The verification service answered this request, and we
> cannot tell from an answer whether it charged us for the attempt. Nothing has been
> charged to your wallet. Reload the page to start a fresh request instead of sending
> this one again, so a second attempt is one you chose.*

There is no retry button, no queue worker and no scheduled command that repeats a held
ask. That is deliberate; please do not add one.

## 3. Finding what is held right now

Read-only queries. Both are safe to run whenever you like.

The holds themselves - a claim with no order behind it, older than 15 minutes:

```sql
SELECT pi.user_id, pi.token, pi.created_at
FROM purchase_intents pi
LEFT JOIN orders o
       ON o.user_id = pi.user_id AND o.intent_token = pi.token
WHERE o.id IS NULL
  AND pi.created_at < NOW() - INTERVAL 15 MINUTE
ORDER BY pi.created_at DESC;
```

Fifteen minutes, because a lookup that is still standing at the provider has its claim
written and its answer not yet written. A row younger than that is a request in flight,
not a hold.

Then match each one to its log entry to learn *which* job and *why*:

```
grep -nE "NIN provider (request|connection) failed|BVN provider (request|connection) failed" \
  storage/logs/laravel.log | tail -40
```

The timestamp on the log line is the moment we asked; that is the timestamp to look for
in the provider's own report.

About the card and airtime routes: their claim is taken *inside* the same database
transaction as the debit, so an attempt that rolled back (too thin a wallet, a refusal)
leaves no row behind. A leftover row in `purchase_intents` is therefore either a held
identity ask or a request still in flight - it is never a cancelled purchase.

## 4. Closing one, in order

1. **Start from the customer being right.** They were not charged. Confirm it in the
   ledger rather than reassuring them from memory:
   `SELECT * FROM wallet_transactions WHERE wallet_id = (SELECT id FROM wallets WHERE user_id = ?) ORDER BY id DESC LIMIT 10;`
2. **Ask ConfirmIdent, without asking them again.** Sign in to their dashboard with the
   account the `nin_api_key` in Admin → Settings belongs to, and open its transaction /
   usage report. Look for a debit at the timestamp from step 3. This is free and
   read-only; re-running the request to "see if it works now" is not - that is the
   second bill this whole design exists to prevent.
3. **Act on what their report says.**
   - *Nothing was billed.* Nothing happened to anybody. Tell the customer to reload and
     submit again; the reloaded page carries a fresh request identity, which is what a
     deliberate second attempt should look like.
   - *We were billed, and their record shows the answer.* The customer still has their
     money and no result. If they want the result, they reload and buy it again; or you
     run this one by hand from **Admin → Manual requests**, which books its own order,
     its own debit, its own document filing and its own audit trail. Never reuse the
     held token - it is the record that the first ask went out.
   - *We were billed and nothing was delivered.* Same as above, plus raise it with the
     provider. The loss is ours by design, not the customer's.
4. **Do not delete the `purchase_intents` row** to make the customer's existing page
   work again. That row is the only thing that tells this site the intention has
   already been sent to a service that bills per call. Removing it converts a held
   request into an open second bill, and it destroys the evidence you used in step 2.

## 5. The unclear answers that are *not* held

- **A GSUBZ purchase with an unclear answer** already left a pending order carrying our
  own `requestID`, and the site reconciles it through `/api/verify/` before deciding:
  verified → completed, otherwise → refunded, both in the same transaction as the
  ledger row. Since commit `11ca711d`-and-later the purchase itself is sent **exactly
  once** by our client (`billableClient()` in `app/Services/GsubzApi.php`); the retries
  that survive are only on the free read-only calls, so asking "did that go through?"
  twice cannot bill us twice. If you find a pending card or airtime order, use the
  provider's verify page with its `requestID` - do **not** re-run the purchase from the
  provider's own console with the same reference, and do not fund a second supply by
  hand while the order is still pending.
- **A NIN validation that came back unclear** stays as a pending order the customer has
  paid for, because validation is a job ConfirmIdent processes after accepting it. It
  is visible in Admin and is settled by an answer, a refund, or an admin's manual
  fulfilment - never by re-submitting the form.

## 6. What is still open, and how it would close

- **Unverified fact.** Whether ConfirmIdent bills for an *unsuccessful* answer cannot be
  read out of their response body - a refusal and a billed record look identical from
  here. The site therefore assumes the worst. The free check is their own account
  report (Admin, not support tickets: the transaction list already shows a line per
  call). If it shows that an answer of "no" is not billed, the release rule can be
  relaxed in one place: `providerNeverAsked()` in `VtuController`, together with
  `test_a_lookup_answered_with_a_no_holds_its_request_until_a_no_is_known_to_be_free`
  in `tests/Feature/PurchaseIntentSafetyTest.php`, which exists to hold the line until
  that answer is known. This is an owner decision, not a cleanup.
- **No sweep, no expiry.** `purchase_intents` rows are never pruned. Held claims stay
  until a person reads them; that is what makes them evidence. One row per purchase
  form is small, but it is worth a look alongside step 3 now and then.
- **A hold is invisible in the admin UI**, because no order was ever written. Section 3
  is the only way to see one.
- Hosting cron (`schedule:run`, which sweeps deposits and staged documents) and the
  service-worker cache (`sw.js`) are still on the deployment checklist. Neither is
  verified as running on the hosting server yet.
