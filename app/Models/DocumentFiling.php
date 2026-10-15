<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The memory a paid request keeps about paper that is still in the holding area.
 *
 * A customer's documents are written to disk before the wallet is touched and
 * moved against the order afterwards. This row is created with the order, in the
 * same transaction as the debit, so the list of what still has to be moved outlives
 * the request that wrote it. A process that dies between the payment and the move
 * leaves a row here saying exactly which folder holds which order's paper.
 *
 * It never holds the document itself, and it never decides money: it is a to-do
 * list for the filing, and the record an agent reads when the paper is short.
 */
class DocumentFiling extends Model
{
    /** Paid, filed, and the folder is gone. */
    public const FILED = 'filed';

    /** Paid, and the move has still to happen or has still to be confirmed. */
    public const AWAITING = 'awaiting';

    /** The paper cannot be filed, so a person has to act on it. */
    public const LOST = 'lost';

    /** The request was reversed and paid back, so nothing may be filed onto it. */
    public const ABANDONED = 'abandoned';

    protected $fillable = [
        'order_id',
        'token',
        'manifest',
        'state',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'manifest' => 'array',
        'attempts' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The holding folders a paid order still needs. The sweep that clears stale
     * paper asks this before it deletes anything, because a folder that has been
     * sitting for an hour is not the same thing as a folder nobody paid for.
     *
     * @return list<string>
     */
    public static function heldTokens(): array
    {
        return self::query()
            ->where('state', self::AWAITING)
            ->pluck('token')
            ->all();
    }

    public static function forOrder(Order $order): ?self
    {
        return self::query()->where('order_id', $order->id)->first();
    }

    public function isAwaiting(): bool
    {
        return $this->state === self::AWAITING;
    }
}
