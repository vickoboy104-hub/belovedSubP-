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
        $authUser = auth()->user();
        $walletKobo = (int) ($authUser?->wallet->balance ?? 0);

        // Signed-in pages wear the site logo; the splash and page loader always
        // wear the separate square mark the admin uploads for them.
        $logoUrl = site_logo_url();
        $brandMark = site_loader_logo_url();

        $pageTitle = match (true) {
            request()->routeIs('vtu.nin*') => 'Verify NIN',
            request()->routeIs('vtu.bvn*') => 'BVN Services',
            request()->routeIs('wallet.fund*') => 'Fund Wallet',
            request()->routeIs('wallet.transactions*') => 'Funding History',
            request()->routeIs('referral.*') => 'Invite & Earn',
            request()->routeIs('vtu.orders*', 'vtu.receipt*') => 'Transactions',
            request()->routeIs('vtu.data*') => 'Buy Data',
            request()->routeIs('vtu.airtime*') => 'Buy Airtime',
            request()->routeIs('vtu.cable*') => 'Pay TV Bill',
            request()->routeIs('vtu.electricity*') => 'Pay Electricity Bill',
            request()->routeIs('vtu.exam*') => 'Education',
            request()->routeIs('vtu.recharge-card*') => 'Recharge PIN',
            request()->routeIs('vtu.premium-apps*') => 'Premium Apps',
            request()->routeIs('vtu.profit-calculator*') => 'Profit Calculator',
            request()->routeIs('notifications.*') => 'Notifications',
            request()->routeIs('support.chat*') => 'Support Chat',
            request()->routeIs('profile.*') => 'Profile',
            request()->routeIs('admin.*') => 'Admin',
            default => 'Services',
        };

        $drawerSections = [
            [
                'title' => 'Identity Services',
                'items' => [
                    ['label' => 'NIN Verification Services', 'route' => 'vtu.nin'],
                    ['label' => 'BVN Services / BVN Printout', 'route' => 'vtu.bvn'],
                    ['label' => 'NIN Validation', 'route' => 'vtu.nin-validation'],
                    ['label' => 'All Identity Services', 'route' => 'identity.index'],
                ],
            ],
            [
                'title' => 'Subscriptions & Payment Services',
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
                'title' => 'Wallet & Activity',
                'items' => [
                    ['label' => 'Fund Wallet', 'route' => 'wallet.fund'],
                    ['label' => 'Invite & Earn', 'route' => 'referral.index'],
                    ['label' => 'Transactions', 'route' => 'wallet.transactions'],
                    ['label' => 'Orders', 'route' => 'vtu.orders'],
                    ['label' => 'Profile', 'route' => 'profile.edit'],
                ],
            ],
            [
                'title' => 'Support',
                'items' => [
                    ['label' => 'Help Centre', 'route' => 'support.bot'],
                    ['label' => 'WhatsApp Support', 'route' => null],
                ],
            ],
        ];
    @endphp

    <title>{{ $pageTitle }} · {{ $siteName }}</title>

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

    <div x-data="{ drawerOpen: false, desktopNavOpen: true, profileMenuOpen: false }"
         x-effect="document.body.style.overflow = drawerOpen ? 'hidden' : ''"
         @resize.window="if (window.innerWidth >= 768) drawerOpen = false"
         :class="{ 'desktop-nav-collapsed': !desktopNavOpen, 'mobile-nav-open': drawerOpen }" class="reference-app-shell min-h-screen">
        <a href="{{ route('dashboard') }}" class="reference-sidebar-brand hidden md:flex" aria-label="{{ $siteName }} dashboard">
            <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-10 w-auto max-w-[170px] object-contain">
        </a>
        <aside id="desktop-navigation" class="reference-sidebar desktop-sidebar-scroll hidden fixed left-0 top-[64px] z-30 h-[calc(100vh-64px)] w-[246px] overflow-y-auto overscroll-contain md:block" :aria-hidden="!desktopNavOpen" :inert="!desktopNavOpen">
            <div class="reference-profile">
                <a href="{{ route('profile.edit') }}" class="reference-profile-link">
                    <x-avatar :user="$authUser" />
                    <span class="block font-semibold">{{ $authUser?->name ?? 'User' }}</span>
                    <span class="block text-xs opacity-75">User</span>
                </a>
            </div>

            <div class="reference-nav">
                <x-nav-sections :sections="$drawerSections" :support-url="$whatsApp" />
            </div>
        </aside>

        <header class="reference-header fixed top-0 right-0 left-0 z-40">
            <div class="flex w-full items-center justify-between gap-4 px-4 py-3 sm:px-6 md:px-8">
                <div class="flex items-center gap-3">
                    <button type="button"
                            class="flex h-11 w-11 items-center justify-center text-white"
                            @click="window.innerWidth >= 768 ? desktopNavOpen = !desktopNavOpen : (drawerOpen = true, profileMenuOpen = false)"
                            aria-label="Toggle navigation" :aria-controls="window.innerWidth >= 768 ? 'desktop-navigation' : 'mobile-navigation'" :aria-expanded="window.innerWidth >= 768 ? desktopNavOpen : drawerOpen">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 7h16"></path>
                            <path d="M4 12h16"></path>
                            <path d="M4 17h16"></path>
                        </svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 md:hidden">
                        <img src="{{ $logoUrl }}" alt="{{ $siteName }} logo" class="h-8 w-auto max-w-[156px] object-contain sm:h-10 sm:max-w-[180px]">
                    </a>
                </div>

                <a href="{{ route('download.app') }}" class="reference-install hidden md:inline-flex">Install app</a>

                <div class="flex items-center gap-2">
                    <x-notification-bell />
                </div>

                <div class="flex items-center gap-2 md:hidden">
                    <a href="{{ route('download.app') }}" class="reference-install">Install app</a>
                    <button type="button"
                            class="mobile-topbar-icon"
                            @click="profileMenuOpen = !profileMenuOpen; drawerOpen = false"
                            @click.outside="profileMenuOpen = false"
                            aria-label="Open profile menu" :aria-expanded="profileMenuOpen">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor">
                            <circle cx="12" cy="5" r="1.8"></circle>
                            <circle cx="12" cy="12" r="1.8"></circle>
                            <circle cx="12" cy="19" r="1.8"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <div x-cloak x-show="profileMenuOpen"
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

        {{-- Push navigation: the drawer does not cover the page, the page steps
             aside for it. This catcher sits over the shifted page only, so a tap
             anywhere on the content closes the drawer while the rail itself stays
             clickable. --}}
        <div x-cloak x-show="drawerOpen" x-transition:enter="transition-opacity duration-500 ease-out" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-500 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="reference-drawer-scrim fixed inset-0 z-[45] md:hidden" @click="drawerOpen = false"></div>

        <aside id="mobile-navigation" x-cloak x-show="drawerOpen"
               :class="{ 'drawer-is-open': drawerOpen }"
               x-transition:enter="transition transform duration-[450ms] ease-out"
               x-transition:enter-start="-translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition transform duration-[450ms] ease-in"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="-translate-x-full"
               @keydown.escape.window="drawerOpen = false; profileMenuOpen = false"
               :aria-hidden="!drawerOpen" :inert="!drawerOpen"
               role="dialog" aria-label="Navigation" aria-modal="true"
               class="reference-sidebar reference-drawer fixed left-0 top-0 z-[50] h-full overflow-y-auto overscroll-contain md:hidden">
            <div class="flex items-center justify-end p-2">
                <button type="button"
                        class="reference-drawer-close flex h-11 w-11 items-center justify-center"
                        @click="drawerOpen = false"
                        aria-label="Close navigation">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18"></path>
                        <path d="M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="reference-profile">
                <a href="{{ route('profile.edit') }}" class="reference-profile-link">
                    <x-avatar :user="$authUser" />
                    <span class="block font-semibold">{{ $authUser?->name ?? 'User' }}</span>
                    <span class="block text-xs opacity-75">{{ $authUser?->email }}</span>
                    <span class="block text-xs opacity-75 mt-1">Edit profile</span>
                </a>
            </div>

            <div class="reference-nav">
                <x-nav-sections :sections="$drawerSections" :support-url="$whatsApp" />
            </div>
        </aside>

        <main class="reference-main pt-[64px] pb-6 sm:pb-8">
            <div class="app-page">
                <x-toast />

                {{ $slot }}
            </div>
        </main>

        @if(request()->routeIs('admin.*') && ($authUser?->is_admin ?? false))
            <x-admin-quick-nav />
        @endif

        <x-whatsapp-support :href="$whatsApp" />

    </div>

    <script>
        (function () {
            const icons = {
                success: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>',
                error: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"></path><path d="M6 6l12 12"></path></svg>',
                info: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>',
            };

            const receiptBaseUrl = @json(url('/vtu/receipt'));

            let returnFocus = null;
            let dismissable = false;

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function closeAppDialog() {
                const el = document.getElementById('appDialog');
                if (el) el.remove();
                document.removeEventListener('keydown', onKeyDown);
                if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
                returnFocus = null;
            }

            function onKeyDown(event) {
                if (event.key === 'Escape' && dismissable) {
                    event.preventDefault();
                    closeAppDialog();
                }
            }

            /**
             * The one dialog this site shows results in. A server flash, a fetch
             * answer and a failed submission are the same event, so they share this
             * markup rather than keeping three copies of it in step by hand.
             *
             * It waits for the customer to dismiss it on purpose: a transfer of
             * money that vanishes from the screen after a few seconds leaves no
             * proof of what just happened.
             */
            function showAppDialog(options) {
                const tone = options.tone === 'error' || options.tone === 'info' ? options.tone : 'success';
                const actions = Array.isArray(options.actions) && options.actions.length
                    ? options.actions
                    : [{ label: 'Okay', variant: 'warm' }];
                dismissable = options.dismissable !== false;

                closeAppDialog();
                returnFocus = document.activeElement;

                const wrap = document.createElement('div');
                wrap.id = 'appDialog';
                wrap.className = 'app-modal-overlay fixed inset-0 z-[99] flex items-center justify-center px-4';
                wrap.setAttribute('role', tone === 'error' ? 'alertdialog' : 'dialog');
                wrap.setAttribute('aria-modal', 'true');
                wrap.innerHTML = `
                    <div class="app-modal-panel app-dialog relative w-full max-w-sm overflow-hidden" aria-labelledby="appDialogTitle">
                        <div class="app-dialog-accent is-${tone}"></div>
                        ${dismissable ? '<button type="button" data-dialog-close class="app-modal-close app-dialog-close" aria-label="Close">&times;</button>' : ''}
                        <div class="px-5 pb-5 pt-6 text-center">
                            <div class="app-result-icon is-${tone} mx-auto flex h-14 w-14 items-center justify-center rounded-2xl border">${icons[tone]}</div>
                            <div id="appDialogTitle" class="app-dialog-title mt-3 text-xl font-extrabold">${escapeHtml(options.title)}</div>
                            <div class="app-dialog-message mt-2 text-sm leading-6">${escapeHtml(options.message)}</div>
                            <div data-dialog-actions class="app-modal-actions app-dialog-actions mt-5"></div>
                        </div>
                    </div>
                `;

                const actionsWrap = wrap.querySelector('[data-dialog-actions]');
                actions.forEach((action) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'app-modal-btn app-modal-btn-' + (action.variant || 'warm');
                    button.textContent = action.label;
                    button.addEventListener('click', () => {
                        closeAppDialog();
                        if (action.href) window.location.href = action.href;
                        if (typeof action.onClick === 'function') action.onClick();
                    });
                    actionsWrap.appendChild(button);
                });

                wrap.querySelectorAll('[data-dialog-close]').forEach((button) => {
                    button.addEventListener('click', closeAppDialog);
                });
                wrap.addEventListener('click', (event) => {
                    if (event.target === wrap && dismissable) closeAppDialog();
                });

                document.body.appendChild(wrap);
                if (typeof window.promoteViewportLayer === 'function') window.promoteViewportLayer(wrap);
                document.addEventListener('keydown', onKeyDown);
                actionsWrap.querySelector('button').focus();
            }

            function getReceiptUrl(orderId) {
                if (!orderId) return receiptBaseUrl;
                return receiptBaseUrl + '/' + encodeURIComponent(orderId);
            }

            function showTransactionResult(options) {
                const ok = !!options?.ok;
                const orderId = options?.orderId || '';

                showAppDialog({
                    tone: ok ? 'success' : 'error',
                    title: ok ? 'Successful' : 'Failed',
                    message: options?.message || (ok ? 'Your transaction went through.' : 'Your transaction could not be completed.'),
                    actions: ok && orderId
                        ? [
                            { label: 'View receipt', variant: 'primary', href: getReceiptUrl(orderId) },
                            { label: 'Close', variant: 'muted' },
                        ]
                        : [{ label: 'Okay', variant: 'warm' }],
                });
            }

            function showFlashToast(type, message) {
                showAppDialog({
                    tone: type === 'success' ? 'success' : 'error',
                    title: type === 'success' ? 'Success' : 'Failed',
                    message,
                });
            }

            function updateWalletBalance(balanceKobo) {
                const el = document.getElementById('walletBalance');
                const raw = Number(balanceKobo);
                if (!el || !Number.isFinite(raw)) return;
                el.dataset.walletKobo = String(raw);
                const naira = raw / 100;
                el.textContent = '\u20A6' + naira.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            // The flash handover and the deposit card both render above this
            // script, so they leave their answer here rather than calling a
            // function that does not exist yet. Never write a component tag in
            // this file's comments: Blade compiles it right into the script.
            if (window.pendingFlashDialog) {
                showFlashToast(window.pendingFlashDialog.type, window.pendingFlashDialog.message);
            } else if (window.pendingDepositDialog) {
                showAppDialog(window.pendingDepositDialog);
            }

            window.escapeToastHtml = escapeHtml;
            window.showAppDialog = showAppDialog;
            window.closeAppDialog = closeAppDialog;
            window.closeFlashToast = closeAppDialog;
            window.showFlashToast = showFlashToast;
            window.showTransactionResult = showTransactionResult;
            window.notify = showFlashToast;
            window.updateWalletBalance = updateWalletBalance;
            window.getReceiptUrl = getReceiptUrl;
        })();
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
