<?php

namespace App\Services\Providers;

class ProviderFactory
{
    public static function make(?string $provider = null): ProviderInterface
    {
        $provider = $provider ?: setting('provider', 'mock');

        return match ($provider) {
            'mock' => new MockProvider(),
            default => new MockProvider(), // later: 'gsubz' => new GsubzProvider()
        };
    }
}
