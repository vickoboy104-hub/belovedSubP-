<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'service_id',
        'customer_ref',
        'amount',
        'profit',
        'provider',
        'provider_reference',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'assigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The agent who took the request in hand, for showing their name in the queue.
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isManualQueue(): bool
    {
        return ($this->meta['manual_queue'] ?? false) === true;
    }

    /**
     * What the customer is told this request is, in words only.
     *
     * The stored status is the money's truth and stays pending until a person or a
     * document answers the request, so an agent taking it in hand must not be
     * written into it: "In Progress" comes from who holds the order, and the request
     * stays refundable exactly as it was.
     *
     * A request that is not ours to work by hand - an API purchase, a top-up - keeps
     * the wording it has always had, and each history table may still say "Completed"
     * in its own voice through the key.
     *
     * @return array{key: string, label: string, tone: string}
     */
    public function customerStatus(): array
    {
        $manual = $this->isManualQueue();

        if ((string) ($this->status ?: 'pending') === 'pending') {
            if ($manual && $this->assigned_to !== null) {
                return ['key' => 'in_progress', 'label' => 'In Progress', 'tone' => 'warning'];
            }

            return [
                'key' => 'pending',
                'label' => $manual ? 'Waiting' : 'Pending',
                'tone' => 'warning',
            ];
        }

        return match ((string) $this->status) {
            'success' => ['key' => 'success', 'label' => 'Success', 'tone' => 'success'],
            'failed' => ['key' => 'failed', 'label' => 'Failed', 'tone' => 'danger'],
            default => ['key' => 'pending', 'label' => 'Pending', 'tone' => 'warning'],
        };
    }
}
