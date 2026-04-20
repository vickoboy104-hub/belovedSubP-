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
        $authUser = auth()->user();
        $walletKobo = (int) ($authUser?->wallet->balance ?? 0);

        $logoUrl = trim((string) $siteLogo);
        if ($logoUrl === '') {
            $logoUrl = asset('images/logo.png');
        } elseif (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, '/')) {
            $logoUrl = asset($logoUrl);
        }

        $isRoute = fn (string $pattern) => request()->routeIs($pattern);

        $primaryNav = [
            ['label' => 'Home', 'route' => 'dashboard', 'match' => 'dashboard'],
            ['label' => 'Top Up & Bills', 'route' => 'vtu.data', 'match' => 'vtu.*'],
            ['label' => 'Wallet', 'route' => 'wallet.fund', 'match' => 'wallet.*'],
            ['label' => 'Orders', 'route' => 'vtu.orders', 'match' => 'vtu.orders'],
            ['label' => 'Profile', 'route' => 'profile.edit', 'match' => 'profile.*'],
        ];

        $drawerSections = [
            [
                'title' => 'Top Up & Bills',
                'items' => [
                    ['label' => 'Buy Data', 'route' => 'vtu.data'],
                    ['label' => 'Buy Airtime', 'route' => 'vtu.airtime'],
                    ['label' => 'Pay TV Bill', 'route' => 'vtu.cable'],
                    ['label' => 'Pay Electricity Bill', 'route' => 'vtu.electricity'],
                    ['label' => 'Education', 'route' => 'vtu.exam'],
                    ['label' => 'Recharge PIN', 'route' => 'vtu.recharge-card'],
                    ['label' => 'Premium Apps', 'route' => 'vtu.premium-apps'],
                ],
            ],
            [
                'title' => 'Finance',
                'items' => [
                    ['label' => 'Fund Wallet', 'route' => 'wallet.fund'],
                    ['label' => 'Transactions', 'route' => 'wallet.transactions'],
                    ['label' => 'Orders', 'route' => 'vtu.orders'],
                ],
            ],
        ];
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

    <div x-data="{ drawerOpen: false }" class="min-h-screen">
        <header class="sticky top-0 z-40 bg-[#17233d] text-white shadow-[0_12px_30px_rgba(16,26,49,0.18)]">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
                <div class="flex items-center gap-3">
                    <button type="button"
                            class="flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-white md:hidden"
                            @click="drawerOpen = true"
                            aria-label="Open menu">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 7h16"></path>
                            <path d="M4 12h16"></path>
                            <path d="M4 17h16"></path>
                        </svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                        <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-10 w-auto max-w-[180px] object-contain brightness-0 invert">
                    </a>
                </div>

                <nav class="hidden items-center gap-6 md:flex">
                    <a href="{{ route('home') }}" class="app-topbar-link">About Us</a>
                    <a href="{{ route('home') }}#pricing" class="app-topbar-link">Pricing</a>
                    <a href="{{ route('profile.edit') }}" class="app-topbar-link">Account</a>
                </nav>
            </div>
        </header>

        <div x-show="drawerOpen" x-transition.opacity class="fixed inset-0 z-50 bg-black/45 md:hidden" @click="drawerOpen = false"></div>

        <aside x-show="drawerOpen"
               x-transition
               class="fixed inset-y-0 left-0 z-[60] w-[86%] max-w-sm overflow-y-auto rounded-r-[30px] border-r border-slate-200 bg-[linear-gradient(180deg,#f8fbff_0%,#edf4fb_100%)] p-4 shadow-[0_22px_55px_rgba(18,31,56,0.18)] md:hidden">
            <div class="app-section-muted p-4">
                <div class="text-xs font-semibold text-slate-500">Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}</div>
                <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $authUser?->name ?? 'User' }}</div>
                <div class="mt-3 flex items-center justify-between">
                    <div class="text-sm text-slate-500">Wallet balance</div>
                    <div class="text-lg font-extrabold text-slate-900">&#8358;{{ number_format($walletKobo / 100, 2) }}</div>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <a href="{{ route('dashboard') }}" class="nav-item {{ $isRoute('dashboard') ? 'nav-item-active' : '' }}">Home</a>

                @foreach($drawerSections as $section)
                    <div class="rounded-[22px] border border-slate-200 bg-white p-2 shadow-[0_10px_28px_rgba(18,31,56,0.05)]">
                        <div class="px-3 py-3 text-sm font-extrabold text-slate-800">{{ $section['title'] }}</div>
                        <div class="space-y-1">
                            @foreach($section['items'] as $item)
                                <a href="{{ route($item['route']) }}"
                                   class="flex items-center justify-between rounded-2xl px-3 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    <span>{{ $item['label'] }}</span>
                                    <span class="text-slate-300">›</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if(($authUser?->is_admin ?? false) && Route::has('admin.dashboard'))
                    <a href="{{ route('admin.dashboard') }}" class="nav-item">Admin Panel</a>
                @endif

                <form method="POST" action="{{ route('logout', absolute: false) }}">
                    @csrf
                    <button type="submit" class="nav-item w-full text-left">Logout</button>
                </form>
            </div>
        </aside>

        <main class="app-bottom-safe py-6 sm:py-8">
            <div class="app-page">
                <div class="mb-6 grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <section class="app-balance-card p-5 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-sm text-white/70">Wallet Balance</div>
                                <div id="walletBalance"
                                     data-wallet-kobo="{{ $walletKobo }}"
                                     class="mt-2 text-3xl font-extrabold sm:text-4xl">
                                    &#8358;{{ number_format($walletKobo / 100, 2) }}
                                </div>
                            </div>
                            <a href="{{ route('wallet.transactions') }}" class="rounded-2xl border border-white/15 bg-white/8 px-4 py-3 text-sm font-bold text-white hover:bg-white/12">
                                History
                            </a>
                        </div>

                        <div class="mt-5 app-balance-subcard">
                            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                <div class="min-w-0">
                                    <div class="text-sm text-slate-500">Dedicated Wallet Funding</div>
                                    <div class="mt-2 flex items-center gap-3">
                                        <div class="text-base font-bold text-slate-900">{{ $authUser?->virtual_account_bank ?: 'Virtual account' }}</div>
                                        <div class="h-8 w-px bg-slate-300"></div>
                                        <div class="truncate text-2xl font-extrabold tracking-wide text-slate-900">{{ $authUser?->virtual_account_number ?: 'Complete profile to generate' }}</div>
                                    </div>
                                </div>
                                <a href="{{ route('wallet.fund') }}" class="btn-primary whitespace-nowrap">Fund Wallet</a>
                            </div>
                        </div>
                    </section>

                    <section class="app-section-muted p-4 sm:p-5">
                        <div class="text-sm font-semibold text-slate-500">Quick actions</div>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <a href="{{ route('vtu.data') }}" class="app-mini-tile">
                                <span class="app-mini-tile-icon">
                                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M2 18h20"></path>
                                        <path d="M5 14l3-3 2 2 4-5 5 6"></path>
                                    </svg>
                                </span>
                                <span class="app-mini-tile-label">Data</span>
                            </a>
                            <a href="{{ route('vtu.airtime') }}" class="app-mini-tile">
                                <span class="app-mini-tile-icon">
                                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M5 7h14"></path>
                                        <path d="M8 4h8"></path>
                                        <rect x="4" y="7" width="16" height="13" rx="2"></rect>
                                    </svg>
                                </span>
                                <span class="app-mini-tile-label">Airtime</span>
                            </a>
                            <a href="{{ route('vtu.cable') }}" class="app-mini-tile">
                                <span class="app-mini-tile-icon">
                                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="5" width="18" height="12" rx="2"></rect>
                                        <path d="M8 21h8"></path>
                                    </svg>
                                </span>
                                <span class="app-mini-tile-label">TV</span>
                            </a>
                            <a href="{{ route('vtu.electricity') }}" class="app-mini-tile">
                                <span class="app-mini-tile-icon">
                                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"></path>
                                    </svg>
                                </span>
                                <span class="app-mini-tile-label">Electricity</span>
                            </a>
                            <a href="{{ route('vtu.exam') }}" class="app-mini-tile">
                                <span class="app-mini-tile-icon">
                                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 19.5V4.5"></path>
                                        <path d="M20 19.5V4.5"></path>
                                        <path d="M4 8.5h16"></path>
                                        <path d="M8 4.5v15"></path>
                                    </svg>
                                </span>
                                <span class="app-mini-tile-label">Education</span>
                            </a>
                            <a href="{{ route('vtu.premium-apps') }}" class="app-mini-tile">
                                <span class="app-mini-tile-icon">
                                    <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 3l2.8 5.6L21 9.5l-4.5 4.3 1 6.2L12 17l-5.5 3 1-6.2L3 9.5l6.2-.9L12 3z"></path>
                                    </svg>
                                </span>
                                <span class="app-mini-tile-label">Premium Apps</span>
                            </a>
                        </div>
                    </section>
                </div>

                <x-global-loader />
                <x-toast />

                <div id="transactionResultOverlay" class="fixed inset-0 z-[96] hidden items-center justify-center px-4">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" aria-hidden="true"></div>
                    <div class="relative w-full max-w-md overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_22px_55px_rgba(18,31,56,0.18)]">
                        <div class="p-8 text-center">
                            <div id="transactionResultIconWrap" class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl border border-slate-200">
                                <div id="transactionResultIcon"></div>
                            </div>
                            <div id="transactionResultTitle" class="mt-4 text-2xl font-extrabold text-slate-900">Status</div>
                            <div id="transactionResultMessage" class="mt-2 text-sm text-slate-500">Message</div>
                            <div class="mt-6 flex items-center justify-center">
                                <button type="button" id="transactionResultOk" class="btn-primary min-w-[140px]">Okay</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="transactionContinueOverlay" class="fixed inset-0 z-[95] hidden items-center justify-center px-4">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" aria-hidden="true"></div>
                    <div class="relative w-full max-w-xl overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_22px_55px_rgba(18,31,56,0.18)]">
                        <div class="p-8 text-center">
                            <div class="rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-extrabold uppercase tracking-wide text-emerald-700">
                                Transaction Successful
                            </div>
                            <div class="mt-5 flex items-center justify-center">
                                <button type="button" id="transactionContinueBtn" class="btn-primary min-w-[170px]">Continue</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{ $slot }}
            </div>
        </main>

        <nav class="app-mobile-bottom-nav fixed inset-x-0 bottom-0 z-40 md:hidden" style="padding-bottom: env(safe-area-inset-bottom);">
            <div class="grid grid-cols-5 px-2 py-2">
                @foreach($primaryNav as $item)
                    <a href="{{ route($item['route']) }}"
                       class="app-mobile-tab {{ $isRoute($item['match']) ? 'active' : '' }}">
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>
    </div>

    <a href="{{ $whatsApp }}" target="_blank" rel="noopener"
       class="fixed bottom-24 right-5 z-50 flex h-16 w-16 items-center justify-center rounded-full bg-[#17233d] text-white shadow-[0_18px_34px_rgba(23,35,61,0.28)] hover:bg-[#101a31]">
        <svg viewBox="0 0 24 24" class="h-7 w-7" fill="currentColor" aria-hidden="true">
            <path d="M12 2a10 10 0 0 0-8.7 14.9L2 22l5.3-1.4A10 10 0 1 0 12 2zm.1 18.2a8.1 8.1 0 0 1-4.1-1.1l-.3-.2-3.2.8.9-3.1-.2-.3a8.2 8.2 0 1 1 6.9 3.9zm4.4-5.9c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.5.7c-.1.2-.3.2-.5.1a6.8 6.8 0 0 1-2-1.3 7.6 7.6 0 0 1-1.4-1.8c-.1-.2 0-.4.1-.5l.3-.4.2-.4c.1-.1 0-.3 0-.4l-.8-1.9c-.1-.3-.3-.3-.5-.3h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1.9 2.3c.1.1 1.6 2.5 4 3.5.6.3 1 .4 1.4.5.6.1 1.1.1 1.6-.1.5-.1 1.4-.6 1.5-1.2.2-.5.2-1 .1-1.1-.1-.1-.2-.1-.5-.3z"/>
        </svg>
    </a>

    <script>
        function showGlobalLoader(text) {
            const ov = document.getElementById('globalLoader');
            const tx = document.getElementById('globalLoaderText');
            if (!ov) return;
            if (tx && text) tx.textContent = text;
            ov.classList.remove('hidden');
        }

        function hideGlobalLoader() {
            const ov = document.getElementById('globalLoader');
            if (!ov) return;
            ov.classList.add('hidden');
        }

        function escapeToastHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function closeFlashToast() {
            const el = document.getElementById('flashToast');
            if (el) el.remove();
        }

        function showFlashToast(type, message) {
            const ok = type === 'success';
            const safeMessage = escapeToastHtml(message || '');
            closeFlashToast();

            const wrap = document.createElement('div');
            wrap.id = 'flashToast';
            wrap.className = 'fixed inset-0 z-[85] flex items-center justify-center px-4';
            wrap.innerHTML = `
                <div class="absolute inset-0 bg-black/45 backdrop-blur-sm" aria-hidden="true"></div>
                <div class="relative w-full max-w-md overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_22px_55px_rgba(18,31,56,0.18)]">
                    <div class="p-6">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xl font-extrabold text-slate-900">${ok ? 'Success' : 'Failed'}</div>
                                <div class="mt-1 text-sm text-slate-500">Transaction status</div>
                            </div>
                            <button type="button" onclick="closeFlashToast()"
                                    class="flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-500">
                                ×
                            </button>
                        </div>
                        <div class="mt-4 rounded-2xl ${ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'} p-4 text-sm font-semibold">
                            ${safeMessage}
                        </div>
                        <div class="mt-6 flex items-center justify-end">
                            <button type="button" onclick="closeFlashToast()" class="btn-primary min-w-[120px]">OK</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(wrap);
            setTimeout(closeFlashToast, 8000);
        }

        function updateWalletBalance(balanceKobo) {
            const el = document.getElementById('walletBalance');
            const raw = Number(balanceKobo);
            if (!el || !Number.isFinite(raw)) return;
            el.dataset.walletKobo = String(raw);
            const naira = raw / 100;
            el.textContent = '\u20A6' + naira.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        const receiptBaseUrl = @json(url('/vtu/receipt'));

        function getReceiptUrl(orderId) {
            if (!orderId) return receiptBaseUrl;
            return receiptBaseUrl + '/' + encodeURIComponent(orderId);
        }

        function showOverlay(el) {
            if (!el) return;
            el.classList.remove('hidden');
            el.classList.add('flex');
        }

        function hideOverlay(el) {
            if (!el) return;
            el.classList.add('hidden');
            el.classList.remove('flex');
        }

        function showTransactionResult(opts) {
            const overlay = document.getElementById('transactionResultOverlay');
            if (!overlay) return;

            const ok = !!opts?.ok;
            const message = String(opts?.message || (ok ? 'Transaction successful.' : 'Transaction failed.'));
            const orderId = opts?.orderId || '';

            const titleEl = document.getElementById('transactionResultTitle');
            const msgEl = document.getElementById('transactionResultMessage');
            const iconEl = document.getElementById('transactionResultIcon');
            const iconWrap = document.getElementById('transactionResultIconWrap');

            if (titleEl) titleEl.textContent = ok ? 'Success' : 'Failed';
            if (msgEl) msgEl.textContent = message;

            if (iconWrap) {
                iconWrap.className = 'mx-auto flex h-16 w-16 items-center justify-center rounded-2xl border ' + (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700');
            }

            if (iconEl) {
                iconEl.innerHTML = ok
                    ? '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>'
                    : '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"></path><path d="M6 6l12 12"></path></svg>';
            }

            overlay.dataset.ok = ok ? '1' : '0';
            overlay.dataset.orderId = orderId;
            hideOverlay(document.getElementById('transactionContinueOverlay'));
            closeFlashToast();
            showOverlay(overlay);
        }

        function showTransactionContinue(orderId) {
            const overlay = document.getElementById('transactionContinueOverlay');
            if (!overlay) return;
            overlay.dataset.orderId = orderId || '';
            showOverlay(overlay);
        }

        function bindTransactionModals() {
            const resultOverlay = document.getElementById('transactionResultOverlay');
            const continueOverlay = document.getElementById('transactionContinueOverlay');
            const okBtn = document.getElementById('transactionResultOk');
            const continueBtn = document.getElementById('transactionContinueBtn');

            if (okBtn && resultOverlay) {
                okBtn.addEventListener('click', () => {
                    const ok = resultOverlay.dataset.ok === '1';
                    const orderId = resultOverlay.dataset.orderId || '';
                    hideOverlay(resultOverlay);
                    if (ok && orderId) showTransactionContinue(orderId);
                });
            }

            if (continueBtn && continueOverlay) {
                continueBtn.addEventListener('click', () => {
                    const orderId = continueOverlay.dataset.orderId || '';
                    hideOverlay(continueOverlay);
                    if (orderId) {
                        window.location.href = getReceiptUrl(orderId);
                    }
                });
            }

            if (resultOverlay) {
                resultOverlay.addEventListener('click', (e) => {
                    if (e.target === resultOverlay) hideOverlay(resultOverlay);
                });
            }

            if (continueOverlay) {
                continueOverlay.addEventListener('click', (e) => {
                    if (e.target === continueOverlay) hideOverlay(continueOverlay);
                });
            }
        }

        window.showFlashToast = showFlashToast;
        window.closeFlashToast = closeFlashToast;
        window.updateWalletBalance = updateWalletBalance;
        window.showTransactionResult = showTransactionResult;
        window.getReceiptUrl = getReceiptUrl;
        bindTransactionModals();
    </script>

    <script>
        (function () {
            if (!('serviceWorker' in navigator)) return;
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function () {});
            });
        })();
    </script>
</body>
</html>
