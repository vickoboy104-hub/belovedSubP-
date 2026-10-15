<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The identity of an intention.
 *
 * A double-click, a browser retrying a request it believes timed out, and a customer
 * pressing Back and Submit again are one intention seen twice. Buying the same N500
 * for the same number an hour later is a new intention. Nothing in the submitted
 * figures tells those apart - only the identity the form was given when it was shown
 * does - so every purchase form carries one and the database holds each identity
 * against one customer once.
 *
 * The claim is a row the database will only accept once. Where a purchase asks the
 * provider for nothing it is taken inside the transaction of the debit, so a request
 * that falls over gives it back with everything else; where a purchase is about to
 * ask the provider for something it is taken before the asking, because a call that
 * was answered is money gone whatever this site writes down afterwards.
 *
 * A submission that carries no usable identity is not a purchase this site can
 * protect from itself, so it is refused before a wallet is touched or a provider is
 * asked, in the shape the page that sent it understands.
 */
class PurchaseIntent
{
    public const FIELD = 'intent_token';

    /**
     * What a customer is told when the form they sent has no identity to spend.
     */
    public const MISSING_WORDS = 'This form lost its request identity before it reached us, so nothing has been charged. Reload the page and submit again.';

    /**
     * What a customer is told when the provider could not be reached at all.
     */
    public const UNCERTAIN_WORDS = 'We could not get through to the verification service and do not know whether it took the request. '
        .'Nothing has been charged to your wallet. Do not send this again - reload the page to start a fresh request, '
        .'and if you are charged for it, tell us and it will be put right.';

    /**
     * A fresh identity, minted for a form about to be shown to somebody.
     */
    public static function mint(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Whether a string could be an identity this site handed out.
     */
    public static function usable(?string $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) === 1;
    }

    /**
     * The identity this submission carries, or null when it carries none.
     *
     * Null is a refusal, not a fallback: every paid route has to turn it away before
     * it spends anything, because a purchase with no identity cannot be recognised as
     * the copy of a purchase it may already have made.
     */
    public static function fromRequest(Request $request): ?string
    {
        $token = trim((string) $request->input(self::FIELD));

        if (!self::usable($token)) {
            Log::info('Purchase refused for want of an intent identity.', [
                'route' => optional($request->route())->getName(),
                'shape' => $token === '' ? 'absent' : 'malformed',
            ]);

            return null;
        }

        return $token;
    }

    /**
     * Whether this identity has already been taken.
     *
     * A look is not a claim: it turns the copy that arrived a second too late into an
     * answer, and only {@see claim()} settles the matter with the database.
     */
    public static function isSpent(int $userId, string $token): bool
    {
        return DB::table('purchase_intents')
            ->where('user_id', $userId)
            ->where('token', $token)
            ->exists();
    }

    /**
     * Take this identity for ourselves. False means somebody else already did.
     */
    public static function claim(int $userId, string $token): bool
    {
        return DB::table('purchase_intents')->insertOrIgnore([
            'user_id' => $userId,
            'token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * Hand an identity back so the same request can be made again.
     *
     * Only for the callers that know nothing was spent on it: a request the provider
     * answered, and a request that never got as far as the provider, both left this
     * site owing nobody anything.
     */
    public static function release(int $userId, string $token): void
    {
        DB::table('purchase_intents')
            ->where('user_id', $userId)
            ->where('token', $token)
            ->delete();
    }

    /**
     * The order the first request with this identity bought, if it is findable.
     */
    public static function firstOrder(int $userId, string $token): ?Order
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('intent_token', $token)
            ->orderBy('id')
            ->first();
    }

    /**
     * What the customer is told when their intention has already been bought.
     */
    public static function message(?Order $already): string
    {
        if ($already === null) {
            return 'That request has already been sent once, so it has not been bought again. Start a fresh request if you still need it.';
        }

        if ($already->status === 'pending') {
            return 'That request is already with us and still being worked on. Please check your history before sending it again.';
        }

        if ($already->status === 'success') {
            return 'That request was already completed on '.$already->created_at->format('j M Y, g:i a').
                ' and charged once. Start a fresh request if you need it again.';
        }

        return 'That request was already sent and answered as failed, and the money went back to your wallet. Start a fresh request to try again.';
    }
}
