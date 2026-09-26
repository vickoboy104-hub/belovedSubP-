@php
    $siteName = setting('site_name', config('app.name', 'BelovedSubP'));
    $siteLogo = setting('logo_url', setting('site_logo', asset('images/logo.png')));
@endphp

<aside class="hidden lg:flex fixed left-0 top-0 h-full w-64 bg-white dark:bg-[#0b1220] border-r border-gray-200 dark:border-white/10 flex-col z-40">
    <div class="h-16 flex items-center gap-2 px-5 border-b border-gray-200 dark:border-white/10">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
            <img src="{{ $siteLogo }}" class="h-9 w-9 rounded-xl object-cover" alt="{{ $siteName }} Logo">
            <span class="font-extrabold tracking-tight text-lg text-gray-900 dark:text-white">
                {{ $siteName }}
            </span>
        </a>
    </div>

    <div class="p-4 space-y-2 overflow-y-auto">
        <a href="{{ route('dashboard') }}" class="nav-item">Dashboard</a>
        <div class="identity-nav-label">Identity services</div>
        <a href="{{ route('identity.index') }}" class="nav-item">All identity services</a>
        <a href="{{ route('vtu.nin') }}" class="nav-item">NIN verification</a>
        <a href="{{ route('vtu.bvn') }}" class="nav-item">BVN verification</a>
        <a href="{{ route('vtu.nin-validation') }}" class="nav-item">NIN validation</a>
        <div class="identity-nav-label">Wallet & activity</div>
        <a href="{{ route('wallet.fund') }}" class="nav-item">Fund Wallet</a>
        <a href="{{ route('wallet.transactions') }}" class="nav-item">Transactions</a>

        <div class="pt-3 mt-3 border-t border-gray-200 dark:border-white/10 space-y-2">
            <div class="identity-nav-label">Subscriptions &amp; Payment Services</div>
            <a href="{{ route('vtu.airtime') }}" class="nav-item">Buy Airtime</a>
            <a href="{{ route('vtu.data') }}" class="nav-item">Buy Data</a>
            <a href="{{ route('vtu.cable') }}" class="nav-item">Cable TV</a>
            <a href="{{ route('vtu.electricity') }}" class="nav-item">Electricity</a>
            <a href="{{ route('vtu.exam') }}" class="nav-item">Exam Pins</a>
            <a href="{{ route('vtu.recharge-card') }}" class="nav-item">Recharge PIN</a>
            <a href="{{ route('vtu.premium-apps') }}" class="nav-item">Premium Apps</a>
            <a href="{{ route('vtu.orders') }}" class="nav-item">Orders</a>
        </div>

        @if(auth()->user()->is_admin ?? false)
            <div class="pt-3 mt-3 border-t border-gray-200 dark:border-white/10">
                <a href="{{ route('admin.dashboard') }}"
                   class="block px-4 py-3 rounded-xl bg-orange-600 hover:bg-orange-700 transition font-bold text-center">
                    Admin Panel
                </a>
            </div>
        @endif
    </div>
</aside>
