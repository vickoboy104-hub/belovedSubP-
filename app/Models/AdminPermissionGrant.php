<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One permission, given to one administrator, by one person, on one date.
 *
 * There is no update path and no delete path through a model event: a grant is
 * written or removed by the permissions screen, which signs the change into
 * admin_activity_logs in the same transaction. The row itself carries who signed it
 * so a grant can be traced even if the audit history is later archived.
 */
class AdminPermissionGrant extends Model
{
    protected $fillable = [
        'user_id',
        'permission',
        'granted_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'granted_by_user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
