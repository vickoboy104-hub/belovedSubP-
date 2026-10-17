<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One thing an admin did, or tried to do.
 *
 * This record is evidence, so the model refuses to let it be rewritten. Updates
 * and deletes throw rather than silently succeeding: an admin who can edit their
 * own history has no history. Writes that bypass Eloquent (raw SQL, a migration,
 * a direct query-builder update on this table) are outside what a model can
 * enforce, which is why the access rules in docs/admin-activity-audit.md treat
 * application-level and database-level protection as two separate layers.
 */
class AdminActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const CATEGORIES = [
        'wallet',
        'customer',
        'order',
        'platform',
        'auth',
        'support',
        'broadcast',
        'transaction_review',
    ];

    public const RESULTS = ['success', 'failed', 'denied'];

    protected $fillable = [
        'correlation_id',
        'admin_user_id',
        'admin_name',
        'category',
        'action',
        'description',
        'target_type',
        'target_id',
        'reference',
        'result',
        'reason',
        'changes',
        'ip_address',
        'session_hash',
        'user_agent',
        'dedupe_key',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $log): void {
            throw new RuntimeException('Admin activity records cannot be modified.');
        });

        static::deleting(function (self $log): void {
            throw new RuntimeException('Admin activity records cannot be deleted.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /** True for the three ways an action can end, so callers cannot invent one. */
    public function isSuccess(): bool
    {
        return $this->result === 'success';
    }
}
