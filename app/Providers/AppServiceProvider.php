<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\SmsGateway;
use App\Listeners\RecordAuthenticationActivity;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SettingHttpSmsGateway;
use App\Support\AdminPermissions;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The broadcast panel only ever talks to the contract, so swapping the
        // transport is a settings change rather than a code change. Resolved per
        // use so a key saved in Admin Settings takes effect immediately.
        $this->app->bind(SmsGateway::class, function () {
            $gateway = new SettingHttpSmsGateway();

            return $gateway->isConfigured()
                ? $gateway
                : new NullSmsGateway();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);

        /* One gate per permission, so a Blade template can ask the same question the
           middleware asks. The gate is a convenience for hiding a control; the
           middleware is what actually refuses. Both read the same answer from the
           account, so a screen can never promise something the server will not do. */
        foreach (AdminPermissions::slugs() as $slug) {
            Gate::define($slug, static fn (User $user): bool => $user->hasAdminPermission($slug));
        }

        Gate::define(AdminPermissions::MASTER_PERMISSION, static fn (User $user): bool => $user->isMasterAdmin());

        // Every list in the site shares one pager. The framework's own view builds a
        // single unwrapping line of small buttons that runs off a phone screen whose
        // layout hides sideways overflow, so the later pages cannot be reached at all.
        Paginator::defaultView('components.pagination');
        Paginator::defaultSimpleView('components.pagination');

        // Sign-in and sign-out are audited from the framework's own events rather
        // than from the login controller, so a refusal the controller never learns
        // about - a guard attempt that failed, a throttled address - still lands
        // in the trail.
        Event::listen(Login::class, [RecordAuthenticationActivity::class, 'onLogin']);
        Event::listen(Logout::class, [RecordAuthenticationActivity::class, 'onLogout']);
        Event::listen(Failed::class, [RecordAuthenticationActivity::class, 'onFailed']);
        Event::listen(Lockout::class, [RecordAuthenticationActivity::class, 'onLockout']);
    }
}
