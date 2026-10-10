<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $siteName = setting('site_name', config('app.name', 'BelovedSubP'));
        $siteLogo = setting('logo_url', setting('site_logo', asset('images/logo.png')));
        $siteFavicon = setting('favicon_url', setting('site_favicon', ''));
        $whatsApp = setting('whatsapp_link', 'https://wa.me/2348165587119');
    @endphp

    <title>{{ $siteName }}</title>

    {{-- Favicon --}}
    @if(!empty($siteFavicon))
        <link rel="icon" href="{{ $siteFavicon }}">
        <link rel="shortcut icon" href="{{ $siteFavicon }}">
    @else
        <link rel="icon" href="/favicon.ico">
        <link rel="shortcut icon" href="/favicon.ico">
    @endif
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0b1220">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $siteName }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="/icons/pwa-192x192.png">

    <script>
        // Theme: default dark
        (function(){
            try {
                const t = localStorage.getItem('theme') || 'dark';
                if (t === 'light') document.documentElement.classList.remove('dark');
                else document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-gray-50 text-gray-900 dark:bg-[#070b14] dark:text-white">
<style>
    @keyframes toast-pop {
        from { opacity: 0; transform: translateY(12px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    @keyframes toast-progress {
        from { width: 100%; }
        to { width: 0%; }
    }
    @keyframes loader-bar {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
    .toast-pop { animation: toast-pop 180ms ease-out; }
    .toast-progress { height: 100%; animation: toast-progress 8s linear forwards; }
    .toast-icon { width: 44px; height: 44px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; }
    .toast-icon-success { background: rgba(16, 185, 129, 0.16); border: 1px solid rgba(16, 185, 129, 0.35); }
    .toast-icon-error { background: rgba(239, 68, 68, 0.16); border: 1px solid rgba(239, 68, 68, 0.35); }
    .loader-bar { height: 100%; background: linear-gradient(90deg, rgba(255,255,255,0.0), rgba(255,255,255,0.7), rgba(255,255,255,0.0)); animation: loader-bar 1.2s ease-in-out infinite; }
    .result-icon-success { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.35); color: rgba(16, 185, 129, 0.95); }
    .result-icon-error { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.35); color: rgba(239, 68, 68, 0.95); }
</style>

<div x-data="{ sidebarOpen: false }" class="min-h-screen">

    {{-- Desktop Sidebar --}}
    <x-sidebar />

    {{-- Mobile Overlay --}}
    <div
        x-show="sidebarOpen"
        x-transition.opacity
        class="fixed inset-0 bg-black/60 z-50 lg:hidden"
        @click="sidebarOpen = false">
    </div>

    {{-- Mobile Sidebar Drawer --}}
    <div
        x-show="sidebarOpen"
        x-transition
        class="fixed left-0 top-0 h-full w-72 bg-[#0b1220] z-50 lg:hidden border-r border-white/10 overflow-y-auto overscroll-contain">

        <div class="h-16 flex items-center px-5 border-b border-white/10 justify-between">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <img src="{{ $siteLogo }}" class="h-9 w-9 rounded-xl object-cover" alt="{{ $siteName }} Logo">
                <span class="font-extrabold tracking-tight text-lg">{{ $siteName }}</span>
            </a>

            <button @click="sidebarOpen=false" class="text-white/70 hover:text-white text-xl">
                X
            </button>
        </div>

        <div class="p-4 space-y-2 pb-10">
            <a href="{{ route('dashboard') }}" class="nav-item">Dashboard</a>
            <div class="identity-nav-label">Identity services</div>
            <a href="{{ route('identity.index') }}" class="nav-item">All identity services</a>
            <a href="{{ route('vtu.nin') }}" class="nav-item">NIN verification</a>
            <a href="{{ route('vtu.bvn') }}" class="nav-item">BVN verification</a>
            <a href="{{ route('vtu.nin-validation') }}" class="nav-item">NIN validation</a>
            <div class="identity-nav-label">Wallet & activity</div>
            <a href="{{ route('wallet.fund') }}" class="nav-item">Fund Wallet</a>
            <a href="{{ route('wallet.transactions') }}" class="nav-item">Transactions</a>
            <a href="{{ route('vtu.orders') }}" class="nav-item">Orders</a>

            <div class="mt-4 pt-4 border-t border-white/10 space-y-2">
                <div class="identity-nav-label">More services</div>
                <a href="{{ route('vtu.airtime') }}" class="nav-item">Buy Airtime</a>
                <a href="{{ route('vtu.data') }}" class="nav-item">Buy Data</a>
                <a href="{{ route('vtu.cable') }}" class="nav-item">Cable TV</a>
                <a href="{{ route('vtu.electricity') }}" class="nav-item">Electricity</a>
                <a href="{{ route('vtu.exam') }}" class="nav-item">Exam Pins</a>
                <a href="{{ route('vtu.recharge-card') }}" class="nav-item">Recharge PIN</a>
                <a href="{{ route('vtu.premium-apps') }}" class="nav-item">Premium Apps</a>
            </div>

            @if(auth()->user()->is_admin ?? false)
                <div class="mt-4 pt-4 border-t border-white/10">
                    <a href="{{ route('admin.dashboard') }}"
                       class="block w-full px-4 py-3 rounded-xl bg-orange-600 hover:bg-orange-700 transition font-semibold text-center">
                        Admin Panel
                    </a>
                </div>
            @endif

            <div class="mt-4 pt-4 border-t border-white/10">
                <form method="POST" action="{{ route('logout', absolute: false) }}">
                    @csrf
                    <button type="submit"
                            class="w-full px-4 py-3 rounded-xl bg-white/10 hover:bg-white/20 transition font-semibold">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Page content area --}}
    <div class="lg:ml-64">

        {{-- Top Bar --}}
        <header class="sticky top-0 z-30 bg-white/80 dark:bg-[#0b1220]/90 backdrop-blur border-b border-gray-200 dark:border-white/10">
            <div class="px-4 sm:px-6 py-2.5">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <button class="lg:hidden text-gray-900/80 hover:text-gray-900 dark:text-white/80 dark:hover:text-white text-2xl"
                                @click="sidebarOpen = true"
                                aria-label="Open navigation">
                            &#9776;
                        </button>

                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 min-w-0">
                            <img src="{{ $siteLogo }}" class="h-8 w-8 rounded-lg object-cover" alt="{{ $siteName }} Logo">
                            <span class="font-extrabold tracking-tight text-sm sm:text-base truncate">{{ $siteName }}</span>
                        </a>
                    </div>

                    <a href="{{ $whatsApp }}" target="_blank" rel="noopener"
                       class="px-2.5 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-semibold transition">
                        Chat
                    </a>
                </div>
            </div>
        </header>

        <div class="px-4 sm:px-6 py-2 border-b border-gray-200 dark:border-white/10 bg-white/70 dark:bg-[#0b1220]/70">
            <div class="flex items-center justify-end gap-2">
                <div class="rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 px-2 py-1 leading-tight">
                    <div class="text-[10px] text-gray-600 dark:text-white/60">Wallet</div>
                    <span id="walletBalance"
                          data-wallet-kobo="{{ (int) (auth()->user()->wallet->balance ?? 0) }}"
                          class="font-extrabold text-xs text-orange-600 dark:text-orange-400">
                        &#8358;{{ number_format((auth()->user()->wallet->balance ?? 0) / 100, 2) }}
                    </span>
                </div>

                <button type="button"
                        onclick="toggleTheme()"
                        class="h-8 px-2 rounded-lg bg-white dark:bg-white/5 hover:bg-gray-100 dark:hover:bg-white/10 border border-gray-200 dark:border-white/10 transition text-[11px] font-semibold"
                        title="Toggle theme">
                    Theme
                </button>
            </div>
        </div>

        <main class="p-4 sm:p-6 lg:p-8">
            <x-global-loader />
            <x-toast />
            <div id="transactionResultOverlay" class="app-modal-overlay fixed inset-0 z-[96] hidden items-center justify-center px-4">
                <div class="app-modal-panel relative w-full max-w-sm overflow-hidden">
                    <div class="p-5 text-center">
                        <div id="transactionResultIconWrap" class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-200">
                            <div id="transactionResultIcon"></div>
                        </div>
                        <div id="transactionResultTitle" class="mt-3 text-xl font-extrabold text-slate-950">Status</div>
                        <div id="transactionResultMessage" class="mt-2 text-sm leading-6 text-slate-700">Message</div>
                        <div class="mt-5 flex items-center justify-center gap-3">
                            <button type="button" id="transactionResultOk"
                                    class="app-modal-btn app-modal-btn-warm min-w-[112px]">
                                Okay
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="transactionContinueOverlay" class="app-modal-overlay fixed inset-0 z-[95] hidden items-center justify-center px-4">
                <div class="app-modal-panel relative w-full max-w-sm overflow-hidden">
                    <div class="p-5 text-center">
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-extrabold uppercase tracking-wide text-emerald-800">
                            Transaction Successful
                        </div>
                        <div class="mt-4 flex items-center justify-center">
                            <button type="button" id="transactionContinueBtn"
                                    class="app-modal-btn app-modal-btn-success min-w-[132px]">
                                Continue
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            {{ $slot }}
        </main>
    </div>

</div>

<script>
    function toggleTheme(){
        const isDark = document.documentElement.classList.contains('dark');
        if (isDark) {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('theme','light');
        } else {
            document.documentElement.classList.add('dark');
            localStorage.setItem('theme','dark');
        }
    }
    // global loader helpers
    function showGlobalLoader(text){
        const ov = document.getElementById('globalLoader');
        const tx = document.getElementById('globalLoaderText');
        if (!ov) return;
        if (tx && text) tx.textContent = text;
        ov.classList.remove('hidden');
    }
    function hideGlobalLoader(){
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
        const safeType = type === 'success' ? 'success' : 'error';
        const label = safeType === 'success' ? 'Success' : 'Failed';
        const safeMessage = escapeToastHtml(message || '');
        const icon = safeType === 'success'
            ? `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 6L9 17l-5-5"></path>
               </svg>`
            : `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6L6 18"></path>
                    <path d="M6 6l12 12"></path>
               </svg>`;

        closeFlashToast();

        const wrap = document.createElement('div');
        wrap.id = 'flashToast';
        wrap.className = 'app-modal-overlay fixed inset-0 z-[85] flex items-center justify-center px-4';
        wrap.innerHTML = `
            <div class="app-modal-panel relative w-full max-w-sm overflow-hidden">
                <div class="p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="toast-icon ${safeType === 'success' ? 'toast-icon-success text-emerald-700' : 'toast-icon-error text-red-700'}">
                                ${icon}
                            </div>
                            <div>
                                <div class="text-lg font-extrabold text-slate-950">${label}</div>
                                <div class="mt-1 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Transaction status</div>
                            </div>
                        </div>
                        <button type="button" onclick="closeFlashToast()"
                                class="app-modal-close">
                            &times;
                        </button>
                    </div>
                    <div class="mt-4 rounded-2xl border ${safeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'} p-4 text-sm font-semibold leading-6">
                        ${safeMessage}
                    </div>
                    <div class="mt-4 h-1 overflow-hidden rounded-full bg-slate-200">
                        <div class="toast-progress ${safeType === 'success' ? 'bg-emerald-500' : 'bg-red-500'}"></div>
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" onclick="closeFlashToast()"
                                class="app-modal-btn app-modal-btn-warm min-w-[96px]">
                            OK
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(wrap);
        setTimeout(closeFlashToast, 8000);
    }

    window.showFlashToast = showFlashToast;
    window.closeFlashToast = closeFlashToast;

    function updateWalletBalance(balanceKobo) {
        const el = document.getElementById('walletBalance');
        const raw = Number(balanceKobo);
        if (!el || !Number.isFinite(raw)) return;
        el.dataset.walletKobo = String(raw);
        const naira = raw / 100;
        el.textContent = '\u20A6' + naira.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    window.updateWalletBalance = updateWalletBalance;

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
            iconWrap.classList.remove('result-icon-success', 'result-icon-error');
            iconWrap.classList.add(ok ? 'result-icon-success' : 'result-icon-error');
        }

        if (iconEl) {
            iconEl.innerHTML = ok
                ? `<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6L9 17l-5-5"></path>
                   </svg>`
                : `<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6L6 18"></path>
                        <path d="M6 6l12 12"></path>
                   </svg>`;
        }

        overlay.dataset.ok = ok ? '1' : '0';
        overlay.dataset.orderId = orderId;
        const continueOverlay = document.getElementById('transactionContinueOverlay');
        hideOverlay(continueOverlay);
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
