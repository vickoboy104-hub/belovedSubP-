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
    <x-global-loader />

    <header class="fixed inset-x-0 top-0 z-40 bg-[#17233d] text-white shadow-[0_10px_30px_rgba(16,26,49,0.18)]">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-6">
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
        </div>
    </header>

    <main class="{{ $isAuthPage ? 'pt-[104px] pb-8 sm:pt-[112px] sm:pb-14' : 'pt-[106px] pb-10 sm:pt-[114px] sm:pb-14' }}">
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

</body>
</html>
