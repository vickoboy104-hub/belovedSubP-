<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ site_theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $siteName = site_name();
        $siteFavicon = setting('favicon_url', setting('site_favicon', ''));
        $whatsApp = whatsapp_link();
        $isAuthPage = request()->routeIs('login')
            || request()->routeIs('register')
            || request()->routeIs('password.*')
            || request()->routeIs('verification.notice');

        // Public pages can wear their own logo; the splash and page loader always
        // wear the separate square mark the admin uploads for them.
        $logoUrl = site_login_logo_url();
        $brandMark = site_loader_logo_url();
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
    <meta name="theme-color" content="#123461">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $siteName }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="/icons/pwa-192x192.png">
    <link rel="preload" as="image" href="{{ $brandMark }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell-bg min-h-screen text-slate-900">
    <x-maintenance-overlay />
    <x-app-splash :logo="$brandMark" :name="$siteName" />
    <x-global-loader :logo="$brandMark" :name="$siteName" />

    <header x-data="{ menuOpen: false }" class="app-header-bar fixed inset-x-0 top-0 z-40">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <button type="button" class="reference-menu-toggle md:hidden" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen" aria-controls="guest-mobile-menu" aria-label="Toggle navigation">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-10 w-auto max-w-[180px] object-contain">
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
            <a href="{{ route('download.app') }}" class="reference-install md:hidden">Install app</a>
        </div>
        <nav x-cloak x-show="menuOpen" x-transition:enter="transition duration-500 ease-out" x-transition:enter-start="opacity-0 -translate-y-3" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition duration-500 ease-in" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-3" id="guest-mobile-menu" class="reference-guest-drawer md:hidden" aria-label="Mobile navigation">
            <a href="{{ route('home') }}">Home</a>
            @auth
                <a href="{{ route('dashboard') }}">Dashboard</a>
            @else
                <a href="{{ route('login') }}">Login</a>
                @if(Route::has('register'))<a href="{{ route('register') }}">Register</a>@endif
            @endauth
            <a href="{{ route('download.app') }}">Install app</a>
        </nav>
    </header>

    <main class="{{ $isAuthPage ? 'reference-auth-page pt-[88px] pb-8 sm:pt-[100px] sm:pb-14' : 'pt-[76px] pb-10 sm:pt-[90px] sm:pb-14' }}">
        @if($isAuthPage)
            <div class="reference-auth-layout mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-[420px_minmax(0,1fr)] lg:items-stretch">
                <section class="app-form-shell self-start">
                    <div class="mb-8 flex justify-center lg:justify-start">
                        <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-14 w-auto object-contain">
                    </div>
                    {{ $slot }}
                </section>

                <section class="app-accent-panel hidden rounded-[20px] p-10 text-white lg:flex lg:flex-col lg:justify-center">
                    <div class="mx-auto max-w-xl text-center">
                        <div class="text-lg font-bold">Welcome to {{ $siteName }}</div>
                        <h2 class="mt-4 text-4xl font-extrabold leading-tight">Identity services, payments and more in one place</h2>
                        <p class="mt-5 text-base leading-8 text-white/90">
                            Verify NIN and BVN, fund your wallet, manage your account and access everyday services from your phone.
                        </p>
                    </div>
                </section>
            </div>
        @else
            {{ $slot }}
        @endif
    </main>

    <x-whatsapp-support :href="$whatsApp" />

</body>
</html>
