<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $siteName = setting('site_name', config('app.name', 'VTU Platform'));
        $siteLogo = setting('logo_url', setting('site_logo', ''));
        $siteFavicon = setting('favicon_url', setting('site_favicon', ''));
        $whatsApp = setting('whatsapp_link', 'https://wa.me/2348165587119');
        $isAuthPage = request()->routeIs('login')
            || request()->routeIs('register')
            || request()->routeIs('password.*')
            || request()->routeIs('verification.notice');

        $logoUrl = trim((string) $siteLogo);
        if ($logoUrl === '') {
            $logoUrl = asset('images/logo.png');
        } elseif (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, '/')) {
            $logoUrl = asset($logoUrl);
        }
    @endphp

    <title>{{ $siteName }}</title>

    @if(!empty($siteFavicon))
        <link rel="icon" href="{{ $siteFavicon }}">
        <link rel="shortcut icon" href="{{ $siteFavicon }}">
    @else
        <link rel="icon" href="/favicon.ico">
        <link rel="shortcut icon" href="/favicon.ico">
    @endif

    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#17233d">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $siteName }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="/icons/pwa-192x192.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[linear-gradient(180deg,#f8fbff_0%,#eef4fb_100%)] text-slate-900">
    <x-maintenance-overlay />

    <header class="sticky top-0 z-40 bg-[#17233d] text-white shadow-[0_10px_30px_rgba(16,26,49,0.18)]">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-10 w-auto max-w-[180px] object-contain brightness-0 invert">
            </a>

            <nav class="hidden items-center gap-6 md:flex">
                <a href="{{ route('home') }}" class="app-topbar-link">Home</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="app-topbar-link">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="app-topbar-link">Login</a>
                    @if(Route::has('register'))
                        <a href="{{ route('register') }}" class="app-topbar-link">Register</a>
                    @endif
                @endauth
            </nav>
        </div>
    </header>

    <main class="{{ $isAuthPage ? 'py-8 sm:py-14' : 'py-10 sm:py-14' }}">
        @if($isAuthPage)
            <div class="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[420px_minmax(0,1fr)] lg:items-stretch">
                <section class="app-form-shell self-start">
                    <div class="mb-8 flex justify-center lg:justify-start">
                        <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-14 w-auto object-contain">
                    </div>
                    {{ $slot }}
                </section>

                <section class="hidden rounded-[30px] border border-[#233455] bg-[linear-gradient(180deg,#b6b6b6_0%,#949494_100%)] p-10 text-white shadow-[0_22px_55px_rgba(20,31,53,0.18)] lg:flex lg:flex-col lg:justify-center">
                    <div class="mx-auto max-w-xl text-center">
                        <div class="text-lg font-bold">Welcome to {{ $siteName }}</div>
                        <h2 class="mt-4 text-4xl font-extrabold leading-tight">Your one-stop digital marketplace for data, airtime, bills payment and more</h2>
                        <p class="mt-5 text-base leading-8 text-white/90">
                            A clean, mobile-first experience for wallet funding, top-up services, utilities, education and account management.
                        </p>
                    </div>
                </section>
            </div>
        @else
            {{ $slot }}
        @endif
    </main>

    @if(!$isAuthPage)
        <a href="{{ $whatsApp }}" target="_blank" rel="noopener"
           class="fixed bottom-5 right-5 z-50 flex h-16 w-16 items-center justify-center rounded-full bg-[#17233d] text-white shadow-[0_18px_34px_rgba(23,35,61,0.28)] hover:bg-[#101a31]">
            <svg viewBox="0 0 24 24" class="h-7 w-7" fill="currentColor" aria-hidden="true">
                <path d="M12 2a10 10 0 0 0-8.7 14.9L2 22l5.3-1.4A10 10 0 1 0 12 2zm.1 18.2a8.1 8.1 0 0 1-4.1-1.1l-.3-.2-3.2.8.9-3.1-.2-.3a8.2 8.2 0 1 1 6.9 3.9zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.5.7c-.1.2-.3.2-.5.1a6.8 6.8 0 0 1-2-1.3 7.6 7.6 0 0 1-1.4-1.8c-.1-.2 0-.4.1-.5l.3-.4.2-.4c.1-.1 0-.3 0-.4l-.8-1.9c-.1-.3-.3-.3-.5-.3h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1.9 2.3c.1.1 1.6 2.5 4 3.5.6.3 1 .4 1.4.5.6.1 1.1.1 1.6-.1.5-.1 1.4-.6 1.5-1.2.2-.5.2-1 .1-1.1-.1-.1-.2-.1-.5-.3z"/>
            </svg>
        </a>
    @endif
</body>
</html>
