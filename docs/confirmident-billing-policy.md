# Does ConfirmIdent charge us for a verification that came back "no"?

**Status: unresolved as of 2026-10-16.** Nobody has answered it, and the code is written
so that an unanswered question costs the business money rather than a customer.

This file records the question precisely, what we can and cannot see from our side, the
rule we run under until it is answered, and the exact one-line change each possible
answer unlocks. It is a companion to [held-purchase-runbook.md](held-purchase-runbook.md),
which tells an administrator what to do about a hold; this file explains why the hold
exists in the first place.

## 1. The question, in one sentence

When our site sends ConfirmIdent a NIN or BVN lookup and the provider **answers** with a
negative - "record not found", "name does not match", "this NIN has no phone linked" -
**does our ConfirmIdent wallet get debited for that answered request?**

There are actually three separate questions inside that one, and they have separate
answers:

| Ask | What we need to know | What we are paying per the price table |
| --- | --- | --- |
| A lookup that **answered "no"** | Is a negative answer billed like a positive one? | ₦160 NIN, ₦80 BVN |
| A lookup that **answered "the service is down"** | Is a request the provider could not complete billed? | same |
| A **validation** (`price_nin_validation_*`) that was refused | Is a refused modification form billed, or only a submitted one? | ₦700 / ₦1,000 |

The first is the one that matters most: it is by far the most common.

## 2. Why it matters, in money

The prices below are from `jhtech_price_reference()` in `app/Helpers/settings.php`, and
they are what ConfirmIdent takes from **our** wallet, not what the customer pays us.

- A NIN lookup costs us **₦160** whichever of its three routes (by NIN, by phone, by
  demographic) is used.
- A BVN lookup costs us **₦80**.
- A NIN validation costs us **₦700** (no record) or **₦1,000** (update record).

If a negative answer **is** billed, then a customer who mistypes one digit and retries
costs us ₦160 twice, and the second one is entirely our loss - the customer paid us once.
That argues for keeping the identity locked so a retry has to be a fresh, deliberate
request the customer chooses.

If a negative answer is **not** billed, then locking the identity buys us nothing: we are
turning away a paying customer over a fear that costs nothing, and the correction they
wanted to make (a wrong digit) has to start a whole new purchase.

**We cannot split this difference from our side. Only the provider's answer can.**

## 3. What the provider's answer actually contains

This is everything our code looks at, and it is all there is:

- `NinApi::isSuccessful()` (`app/Services/NinApi.php:118`) reads one boolean: a `success`
  field, or a `status` of `success`/`true`.
- `NinApi::message()` (`:128`) reads a `message` string, and falls back to a fixed
  sentence when there is none.
- `NinApi::isServiceDown()` (`:144`) recognises a provider-side problem by matching six
  phrases in that message ("service not available", "network issue", "try again later",
  "temporarily unavailable", "timed out", "could not connect").
- The HTTP status is recorded, but only when the provider answered at all.

**Nothing in that tells us about money.** There is no fee field, no "amount debited"
field, no balance in the response - not because we ignored one, but because these are the
only fields the integration has ever received.

We also cannot check our own balance. The ConfirmIdent endpoints this site is configured
for are the search, print, reports and validation ones (`config/services.php:59-76`);
**there is no balance endpoint**, unlike GSUBZ, which has `/api/balance/`. So we cannot
watch our wallet dip after a "no".

The one wallet signal we do get is indirect: when a request comes back because our
provider balance is empty, admins get *"URGENT: NIN provider wallet needs funding"*
(`app/Http/Controllers/VtuController.php:4384`). That tells us the wallet is dry. It does
not tell us what a single negative answer cost.

## 4. The rule we run under until it is answered

`VtuController::intentAfterProviderFailure()` (`:4624`) decides what happens to the
customer's purchase identity when a provider ask fails:

- **The ask never left this site** → the identity is handed straight back
  (`PurchaseIntent::release()`), and the customer can resend. Nine places in the two
  provider clients mark this explicitly with `'provider_asked' => false`:
  `NinApi.php:84,97,111,175` and `BvnApi.php:57,70,92,160,168`. Those are missing keys,
  missing endpoints and locally-rejected payloads - requests we know ConfirmIdent never
  saw.
- **Anything else** → the identity is **held** (`intent_held`), the customer is **not**
  debited, no order is created, and nothing is retried automatically.

The default is deliberately one-sided. `providerNeverAsked()` (`:4654`) reads
`$response['provider_asked'] ?? true` - so if a response ever arrives without a marker,
the code **assumes the provider may have been billed** and holds. A new failure path that
forgets to mark itself therefore fails safe rather than failing expensive.

The cost of that default is ours, never the customer's: an ambiguous "no" can burn a
₦160 ask that turns out to have been free. That is the trade the business is making until
someone answers the question.

## 5. Getting the answer

**Route A - ask ConfirmIdent directly.** Nothing to spend, nothing to risk. Suggested
wording, with the endpoints named so they can answer specifically:

> We integrate `/nin_search`, `/nin_phone`, `/nin_demo`, `/bvn_search` and the validation
> endpoint. When a lookup returns successfully but with a negative result - no record
> found, or name/phone mismatch - is our wallet debited the same as for a matching
> result? And for a request you refuse because your side is unavailable, do we pay?
> Please confirm per endpoint. We also need to know whether a validation form that you
> reject is billed before or after submission.

Their terms page is not authoritative here; ask for it in writing (email or the support
channel their account uses) and keep the reply with this file.

**Route B - measure it.** Note the balance in their dashboard, send one deliberate
negative lookup, note the balance again. This **spends real money** (₦80 for a BVN probe
is the cheapest) and needs a number that belongs to no customer - the owner's own NIN or
a test record the provider supplies. Do not run it against a customer's data, and do not
run it at all without the owner saying so: see the standing rule that every live ConfirmIdent
probe needs asking for first.

Route A is free and should be tried first.

## 6. What each answer changes in the code

One method and one test, both already named:

- **"A negative answer is billed."** → Nothing changes. The hold is correct and this file
  closes with the answer recorded here. Worth adding the per-hold cost to the admin hold
  notice so the loss is visible.
- **"A negative answer is free."** → `intentAfterProviderFailure()` should hand the
  identity back when the provider *answered* (a real "no"), and keep holding only when
  the answer never came. That is a change in that one method, and the test that currently
  pins the conservative behaviour is
  `tests/Feature/PurchaseIntentSafetyTest.php:348`
  (`test_a_lookup_answered_with_a_no_holds_its_request_until_a_no_is_known_to_be_free`) -
  its name says exactly what it is waiting for.
- **"Refusals because your service is down are free, negatives are billed."** → the split
  is already in the code (`isServiceDown()`), so it becomes a matter of which branch
  releases.

Until one of those is written down by the provider, this stays as it is and item #77
stays open.

## 7. What this is *not*

- It is not a bug. No customer has been double-charged by this, and the design makes that
  impossible: a held ask creates no order and no wallet movement.
- It is not a reason to add a retry. Every retry of a possibly-billed ask risks paying
  twice for one answer.
- It is not a blocker for the rest of the project. It is a decision the owner has to make
  with one piece of information they do not yet have.
