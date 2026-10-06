<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\AdminSupportComplaintNotification;
use App\Notifications\AdminSystemAlertNotification;
use App\Notifications\AdminUserActivityNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

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
        'avatar',
        'flutterwave_bvn',
        'flutterwave_nin',
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
        'flutterwave_bvn',
        'flutterwave_nin',
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
            'flutterwave_bvn' => 'encrypted',
            'flutterwave_nin' => 'encrypted',
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

    /**
     * The notices a person wants when they open their alerts: their own money,
     * their own orders, a reply from support, an announcement. An administrator
     * receives a running commentary about everybody else's logins and failing
     * jobs, and none of it is about the reader - so it stays out of the tray
     * rather than burying the one alert that mattered.
     */
    public function visibleNotifications(): MorphMany
    {
        return $this->notifications()->whereNotIn('type', [
            AdminUserActivityNotification::class,
            AdminSupportComplaintNotification::class,
            AdminSystemAlertNotification::class,
        ]);
    }

    public function ensureReferralCode(): string
    {
        if (trim((string) $this->referral_code) === '') {
            do {
                $candidate = Str::upper(Str::random(8));
            } while (static::query()->where('referral_code', $candidate)->exists());

            $this->forceFill(['referral_code' => $candidate])->save();
        }

        return (string) $this->referral_code;
    }

    public function referralLink(): string
    {
        return route('referral.visit', ['code' => $this->ensureReferralCode()]);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $path = trim((string) $this->avatar);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/'.$path);
    }

    public function getInitialsAttribute(): string
    {
        $first = trim((string) $this->first_name);
        $last = trim((string) $this->last_name);

        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $this->name), 2) ?: [];
            $first = $parts[0] ?? '';
            $last = $parts[1] ?? '';
        }

        $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr(trim((string) $this->email), 0, 1));
    }

}
