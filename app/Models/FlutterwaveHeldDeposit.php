<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verified bank transfer that this site can prove arrived but cannot prove who
 * it belongs to. Holding it here is the alternative to guessing: no balance moves,
 * the money is not lost, and an admin can see the transfer and the customers it
 * could belong to.
 */
class FlutterwaveHeldDeposit extends Model
{
    protected $fillable = [
        'charge_token',
        'flutterwave_charge_id',
        'flutterwave_id',
        'tx_ref',
        'virtual_account_number',
        'amount_kobo',
        'currency',
        'channel',
        'arrived_via',
        'reason',
        'holder_user_ids',
        'payload',
        'status',
        'resolution_note',
        'resolved_by_user_id',
        'resolved_wallet_id',
        'resolved_at',
    ];

    protected $casts = [
        'holder_user_ids' => 'array',
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function resolvedWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'resolved_wallet_id');
    }

    public function isHeld(): bool
    {
        return $this->status === 'held';
    }
}
