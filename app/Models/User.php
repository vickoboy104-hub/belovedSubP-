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
     * This account's permission slugs, read from the database at most once per
     * request. Not an attribute: a column with this name would be a permission an
     * attacker could write.
     */
    private ?array $adminPermissionCache = null;

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

    public function permissionGrants(): HasMany
    {
        return $this->hasMany(\App\Models\AdminPermissionGrant::class);
    }

    /**
     * Whether this account is one of the Master Admins the owner named in
     * configuration. The answer comes from the server's environment, never from a
     * column, because a promotable account is an account anybody with the right
     * screen can promote.
     */
    public function isMasterAdmin(): bool
    {
        return $this->is_admin
            && in_array((int) $this->id, \App\Support\AdminPermissions::masterUserIds(), true);
    }

    /**
     * What this account is, in the words the owner asked for on the profile.
     */
    public function accountRoleLabel(): string
    {
        if (!$this->is_admin) {
            return 'USER';
        }

        return $this->isMasterAdmin() ? 'MASTER ADMIN' : 'ADMIN';
    }

    /**
     * The permissions this account holds, read once per request.
     *
     * A Master Admin holds every permission without a row behind it. An ordinary
     * administrator is limited to what was signed for them, and an account that is
     * no longer an administrator holds nothing at all, so deactivation cannot be
     * worked around by leftover grant rows.
     *
     * @return list<string>
     */
    public function adminPermissionSlugs(): array
    {
        if (!$this->is_admin) {
            return [];
        }

        if ($this->isMasterAdmin()) {
            return \App\Support\AdminPermissions::slugs();
        }

        if ($this->adminPermissionCache === null) {
            $this->adminPermissionCache = $this->permissionGrants()
                ->pluck('permission')
                ->filter(static fn ($slug): bool => \App\Support\AdminPermissions::exists((string) $slug))
                ->values()
                ->all();
        }

        return $this->adminPermissionCache;
    }

    public function hasAdminPermission(string $slug): bool
    {
        if ($slug === \App\Support\AdminPermissions::MASTER_PERMISSION) {
            // Only configuration makes a Master, and a Master is not a permission
            // anybody can ask for.
            return $this->isMasterAdmin();
        }

        return in_array($slug, $this->adminPermissionSlugs(), true);
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
