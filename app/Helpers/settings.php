<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

if (!function_exists('settings_flush_cache')) {
    function settings_flush_cache(): void
    {
        try {
            Cache::forget('settings.all');
        } catch (Throwable $e) {
            // Ignore cache flush issues to avoid breaking settings updates.
        }
    }
}

if (!function_exists('setting')) {
    function setting(string $key, $default = null)
    {
        static $settingsTableExists = null;

        if ($settingsTableExists === null) {
            try {
                $settingsTableExists = Schema::hasTable('settings');
            } catch (Throwable $e) {
                $settingsTableExists = false;
            }
        }

        if (!$settingsTableExists) {
            return $default;
        }

        try {
            $settings = Cache::rememberForever('settings.all', function () {
                return Setting::query()
                    ->pluck('value', 'key')
                    ->toArray();
            });
        } catch (Throwable $e) {
            try {
                $row = Setting::where('key', $key)->first();
                return $row ? $row->value : $default;
            } catch (Throwable $inner) {
                return $default;
            }
        }

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }
}
