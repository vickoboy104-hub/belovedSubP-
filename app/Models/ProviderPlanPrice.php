<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderPlanPrice extends Model
{
    protected $fillable = [
        'provider',
        'service_slug',
        'provider_service_id',
        'plan_id',
        'plan_name',
        'provider_price',
        'selling_price',
        'selling_price_is_custom',
        'is_active',
        'raw_plan',
        'last_synced_at',
    ];

    protected $casts = [
        'provider_price' => 'float',
        'selling_price' => 'float',
        'selling_price_is_custom' => 'boolean',
        'is_active' => 'boolean',
        'raw_plan' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
