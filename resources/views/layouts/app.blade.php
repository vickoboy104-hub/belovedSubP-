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
        $whatsApp = 'https://wa.me/2347046246332';
        $authUser = auth()->user();
        $walletKobo = (int) ($authUser?->wallet->balance ?? 0);

        $logoUrl = trim((string) $siteLogo);
        if ($logoUrl === '') {
            $logoUrl = asset('images/logo.png');
        } elseif (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, '/')) {
            $logoUrl = asset($logoUrl);
        }

        $isRoute = fn (string $pattern) => request()->routeIs($pattern);

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
                    ['label' => 'NIN Services', 'route' => 'vtu.nin'],
                    ['label' => 'BVN Services', 'route' => 'vtu.bvn'],
                    ['label' => 'NIN Validation', 'route' => 'vtu.nin-validation'],
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
    <meta name="theme-color" content="#173f8a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $siteName }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="/icons/pwa-192x192.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell-bg min-h-screen text-slate-900">
    <x-maintenance-overlay />
    <x-global-loader />

    <div x-data="{ drawerOpen: false, profileMenuOpen: false }" class="min-h-screen">
        <aside class="app-sidebar-surface desktop-sidebar-scroll hidden fixed left-0 top-[84px] z-30 h-[calc(100vh-84px)] w-[300px] overflow-y-auto overscroll-contain border-r p-4 pb-8 shadow-[0_22px_55px_rgba(18,31,56,0.08)] md:block">
            <div class="app-section-muted p-4">
                <div class="text-xs font-semibold text-slate-500">Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}</div>
                <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $authUser?->name ?? 'User' }}</div>
            </div>

            <div class="mt-4 space-y-3">
                <a href="{{ route('dashboard') }}" class="nav-item {{ $isRoute('dashboard') ? 'nav-item-active' : '' }}">Home</a>

                @foreach($drawerSections as $section)
                    <div class="app-glass-card rounded-[22px] p-2">
                        <div class="px-3 py-3 text-sm font-extrabold text-slate-800">{{ $section['title'] }}</div>
                        <div class="space-y-1">
                            @foreach($section['items'] as $item)
                                <a href="{{ route($item['route']) }}"
                                   class="flex items-center justify-between rounded-2xl px-3 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50/70">
                                    <span>{{ $item['label'] }}</span>
                                    <span class="text-slate-400">&rsaquo;</span>
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

        <header class="app-header-bar fixed inset-x-0 top-0 z-40">
            <div class="flex w-full items-center justify-between gap-4 px-4 py-4 sm:px-6 md:px-8 md:py-5">
                <div class="flex items-center gap-3">
                    <button type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-2xl text-white md:hidden"
                            @click="drawerOpen = true"
                            aria-label="Open menu">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 7h16"></path>
                            <path d="M4 12h16"></path>
                            <path d="M4 17h16"></path>
                        </svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                        <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-8 w-auto max-w-[156px] object-contain sm:h-10 sm:max-w-[180px]">
                    </a>
                </div>

                <nav class="hidden items-center justify-end gap-8 md:flex">
                    <a href="{{ route('home') }}" class="app-topbar-link">About Us</a>
                    <a href="{{ route('profile.edit') }}" class="app-topbar-link">Account</a>
                    <a href="{{ $whatsApp }}" target="_blank" rel="noopener" class="app-topbar-link">Support</a>
                </nav>

                <div class="flex items-center gap-2 md:hidden">
                    <button type="button"
                            class="mobile-topbar-icon"
                            onclick="window.location.reload()"
                            aria-label="Refresh page">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 11a8 8 0 1 1-2.34-5.66"></path>
                            <path d="M20 4v7h-7"></path>
                        </svg>
                    </button>
                    <button type="button"
                            class="mobile-topbar-icon"
                            @click="profileMenuOpen = !profileMenuOpen"
                            @click.outside="profileMenuOpen = false"
                            aria-label="Open profile menu">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor">
                            <circle cx="12" cy="5" r="1.8"></circle>
                            <circle cx="12" cy="12" r="1.8"></circle>
                            <circle cx="12" cy="19" r="1.8"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <div x-show="profileMenuOpen"
                 x-transition
                 class="app-glass-card absolute right-4 top-[calc(100%-0.25rem)] z-50 w-52 overflow-hidden rounded-[22px] p-2 text-slate-800 md:hidden"
                 @click="profileMenuOpen = false">
                <a href="{{ route('profile.edit') }}" class="mobile-profile-menu-item">Profile</a>
                <a href="{{ route('profile.edit') }}#security-settings" class="mobile-profile-menu-item">Settings</a>
                <form method="POST" action="{{ route('logout', absolute: false) }}">
                    @csrf
                    <button type="submit" class="mobile-profile-menu-item w-full text-left">Logout</button>
                </form>
            </div>

        </header>

        <div x-show="drawerOpen" x-transition.opacity class="fixed inset-0 z-50 bg-black/45 md:hidden" @click="drawerOpen = false"></div>

        <aside x-show="drawerOpen"
               x-transition
               class="app-sidebar-surface fixed left-0 top-[84px] z-[60] h-[calc(100vh-84px)] w-[86%] max-w-sm overflow-y-auto rounded-r-[30px] border-r p-4 shadow-[0_22px_55px_rgba(18,31,56,0.18)] md:w-[360px] md:max-w-[360px]">
            <div class="mb-4 flex items-center justify-end md:justify-between">
                <div class="hidden text-sm font-semibold text-slate-500 md:block">Navigation</div>
                <button type="button"
                        class="app-glass-card flex h-10 w-10 items-center justify-center rounded-2xl text-slate-600"
                        @click="drawerOpen = false"
                        aria-label="Close navigation">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18"></path>
                        <path d="M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="app-section-muted p-4">
                <div class="text-xs font-semibold text-slate-500">Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}</div>
                <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $authUser?->name ?? 'User' }}</div>
            </div>

            <div class="mt-4 space-y-3">
                <a href="{{ route('dashboard') }}" class="nav-item {{ $isRoute('dashboard') ? 'nav-item-active' : '' }}">Home</a>

                @foreach($drawerSections as $section)
                    <div class="app-glass-card rounded-[22px] p-2">
                        <div class="px-3 py-3 text-sm font-extrabold text-slate-800">{{ $section['title'] }}</div>
                        <div class="space-y-1">
                            @foreach($section['items'] as $item)
                                <a href="{{ route($item['route']) }}"
                                   class="flex items-center justify-between rounded-2xl px-3 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50/70">
                                    <span>{{ $item['label'] }}</span>
                                    <span class="text-slate-400">&rsaquo;</span>
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

        <main class="pt-[100px] pb-6 sm:pb-8 md:ml-[300px] md:pt-[108px]">
            <div class="app-page">
                <x-toast />

                <div id="transactionResultOverlay" class="fixed inset-0 z-[96] hidden items-center justify-center px-4">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" aria-hidden="true"></div>
                    <div class="app-glass-card relative w-full max-w-md overflow-hidden rounded-[28px]">
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
                    <div class="app-glass-card relative w-full max-w-xl overflow-hidden rounded-[28px]">
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

    </div>

    <script>
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
                <div class="app-glass-card relative w-full max-w-md overflow-hidden rounded-[28px]">
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
            if (typeof window.promoteViewportLayer === 'function') {
                window.promoteViewportLayer(el);
            }
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
