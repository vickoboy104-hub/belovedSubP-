<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NinVerificationCache extends Model
{
    protected $fillable = [
        'lookup_nin',
        'lookup_phone',
        'lookup_demo',
        'resolved_nin',
        'resolved_phone',
        'source_lookup_type',
        'source_payload',
        'normalized_data',
        'provider_data',
        'first_verified_by_user_id',
        'last_verified_by_user_id',
        'last_order_id',
        'last_verified_at',
    ];

    protected $casts = [
        'source_payload' => 'array',
        'normalized_data' => 'array',
        'provider_data' => 'array',
        'last_verified_at' => 'datetime',
    ];
}
