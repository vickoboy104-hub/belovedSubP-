<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\SmsGateway;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SettingHttpSmsGateway;

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
    }
}
