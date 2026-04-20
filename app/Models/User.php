<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'referral_code',
        'referred_by_user_id',
        'referral_qualified_at',
        'referral_earnings_balance',
        'referral_earnings_total',
        'referral_earnings_withdrawn',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'discount_percent' => 'float',
            'is_admin' => 'boolean',
            'virtual_account_assigned_at' => 'datetime',
            'virtual_account_metadata' => 'array',
            'last_login_at' => 'datetime',
            'referred_by_user_id' => 'integer',
            'referral_qualified_at' => 'datetime',
            'referral_earnings_balance' => 'integer',
            'referral_earnings_total' => 'integer',
            'referral_earnings_withdrawn' => 'integer',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(\App\Models\Wallet::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

}
