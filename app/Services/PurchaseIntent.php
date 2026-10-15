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
 * The claim is a row the database will only accept once, taken inside the same
 * transaction as the debit. Two requests racing with the same identity therefore
 * cannot both spend it: the loser's claim is refused before a naira moves, and a
 * purchase that never completed (insufficient balance, a rollback) gives its identity
 * back with the rest of it, so a genuine second attempt is still possible.
 */
class PurchaseIntent
{
    public const FIELD = 'intent_token';

    /**
     * A fresh identity, minted for a form about to be shown to somebody.
     */
    public static function mint(): string
    {
        return (string) Str::uuid();
    }

    /**
     * The identity this submission carries, or null when it carries none.
     *
     * A form that dropped its identity is not a reason to refuse a customer their
     * purchase - it only means that particular request cannot be recognised as a
     * repeat, so the gap is logged and can be found and closed.
     */
    public static function fromRequest(Request $request): ?string
    {
        $token = trim((string) $request->input(self::FIELD));

        if ($token === '' || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) !== 1) {
            Log::info('Purchase submitted without an intent identity.', [
                'route' => optional($request->route())->getName(),
                'shape' => $token === '' ? 'absent' : 'malformed',
            ]);

            return null;
        }

        return $token;
    }

    /**
     * Whether somebody has already made this exact intention.
     *
     * This is only a look, never a claim: an identity is spent for good when the
     * purchase that took it was actually booked, and a request that has not reached
     * the provider yet must still be free to fail and be tried again.
     */
    public static function isSpent(int $userId, ?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        return DB::table('purchase_intents')
            ->where('user_id', $userId)
            ->where('token', $token)
            ->exists();
    }

    /**
     * Take this identity for ourselves. False means somebody else already did.
     */
    public static function claim(int $userId, ?string $token): bool
    {
        if ($token === null) {
            return true;
        }

        return DB::table('purchase_intents')->insertOrIgnore([
            'user_id' => $userId,
            'token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * The order the first request with this identity bought, if it is findable.
     */
    public static function firstOrder(int $userId, ?string $token): ?Order
    {
        if ($token === null) {
            return null;
        }

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
