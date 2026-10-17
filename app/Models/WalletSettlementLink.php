<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An administrator's signed statement that one specific gateway charge was already
 * paid to a customer by hand, and which ledger row did it.
 *
 * It moves no money. Its only effect is on the funding path: a later report of the
 * charge named here is held for a human instead of credited, because the site has an
 * accountable record that the money went out already. A relationship between a
 * charge and a manual credit that nobody declared does not exist - an amount, a
 * customer or an account number that happens to match is not evidence that two
 * records are the same money, and inferring one is how a genuine new deposit gets
 * held behind an unrelated piece of old help.
 */
class WalletSettlementLink extends Model
{
    protected $fillable = [
        'charge_token',
        'charge_token_kind',
        'tx_ref',
        'wallet_id',
        'manual_transaction_id',
        'amount_kobo',
        'status',
        'reason',
        'declared_by_user_id',
        'declared_at',
        'retracted_by_user_id',
        'retracted_at',
        'retraction_note',
    ];

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'manual_transaction_id' => 'integer',
            'amount_kobo' => 'integer',
            'declared_by_user_id' => 'integer',
            'declared_at' => 'datetime',
            'retracted_by_user_id' => 'integer',
            'retracted_at' => 'datetime',
        ];
    }

    public function manualTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'manual_transaction_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by_user_id');
    }

    public function retractedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retracted_by_user_id');
    }

    public function isRetracted(): bool
    {
        return $this->status !== 'active';
    }
}
