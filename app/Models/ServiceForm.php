<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceForm extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'group',
        'summary',
        'icon',
        'cta',
        'definition',
        'visibility',
        'sort_order',
    ];

    protected $casts = [
        'definition' => 'array',
        'sort_order' => 'integer',
    ];
}
