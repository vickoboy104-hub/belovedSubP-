<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'subject',
        'message',
        'attachment_path',
        'status',
        'user_last_read_at',
        'admin_last_read_at',
        'last_message_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'user_last_read_at' => 'datetime',
        'admin_last_read_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class)->latestOfMany();
    }
}
