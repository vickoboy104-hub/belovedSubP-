@php
    $user = auth()->user();
    $walletBalance = optional($user->wallet)->balance ?? 0;
@endphp

<div x-data="{ open: false }" class="relative">

    {{-- âœ… TOP BAR --}}
    <div class="sticky top-0 z-50 bg-[#0b1220]/95 backdrop-blur border-b border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between">

            {{-- Left: mobile hamburger + brand --}}
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    @click="open = true"
                    class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10"
                >
                    â˜°
                </button>

                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    <img src="{{ asset('images/logo.png') }}" class="h-9 w-auto" alt="BelovedSubP Logo">
                    <span class="font-extrabold text-lg text-white">
                        Beloved<span class="text-orange-500">SubP</span>
                    </span>
                </a>
            </div>

            {{-- Right: wallet + dropdown --}}
            <div class="flex items-center gap-3">
                <div class="hidden sm:flex items-center gap-2 bg-white/5 border border-white/10 rounded-xl px-3 py-2">
                    <span class="text-xs text-white/60">Wallet:</span>
                    <span class="font-bold text-orange-400">
                        â‚¦{{ number_format($walletBalance / 100, 2) }}
                    </span>
                </div>

                <div class="relative">
                    <details class="group">
                        <summary class="cursor-pointer list-none px-4 py-2 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 font-semibold">
                            {{ $user->name }}
                        </summary>

                        <div class="absolute right-0 mt-2 w-48 rounded-xl bg-[#0b1220] border border-white/10 shadow-lg overflow-hidden">
                            <a href="{{ route('profile.edit') }}" class="block px-4 py-3 hover:bg-white/5 text-sm">
                                Profile
                            </a>

                            <form method="POST" action="{{ route('logout', absolute: false) }}">
                                @csrf
                                <button type="submit" class="w-full text-left px-4 py-3 hover:bg-white/5 text-sm">
                                    Logout
                                </button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>

        </div>
    </div>

    {{-- âœ… DESKTOP SIDEBAR --}}
    <aside class="hidden lg:flex fixed top-[64px] left-0 h-[calc(100vh-64px)] w-72 bg-[#07101f] border-r border-white/10">
        <div class="flex flex-col w-full">

            <div class="p-5 border-b border-white/10">
                <p class="text-xs text-white/50">Signed in as</p>
                <p class="font-bold mt-1">{{ $user->name }}</p>
                <p class="text-xs text-white/50">{{ $user->email }}</p>
            </div>

            <nav class="p-4 space-y-2 overflow-y-auto">
                <a href="{{ route('dashboard') }}" class="nav-item">Dashboard</a>
                <a href="{{ route('wallet.fund') }}" class="nav-item">Fund Wallet</a>
                <a href="{{ route('wallet.transactions') }}" class="nav-item">Transactions</a>

                <div class="pt-3 border-t border-white/10 mt-3"></div>

                <a href="{{ route('vtu.airtime') }}" class="nav-item">Buy Airtime</a>
                <a href="{{ route('vtu.data') }}" class="nav-item">Buy Data</a>
                <a href="{{ route('vtu.cable') }}" class="nav-item">Cable TV</a>
                <a href="{{ route('vtu.electricity') }}" class="nav-item">Electricity</a>
                <a href="{{ route('vtu.exam') }}" class="nav-item">Exam Pins</a>
                <a href="{{ route('vtu.recharge-card') }}" class="nav-item">Recharge PIN</a>
                <a href="{{ route('vtu.premium-apps') }}" class="nav-item">Premium Apps</a>
                <a href="{{ route('vtu.nin') }}" class="nav-item">NIN Services</a>
                <a href="{{ route('vtu.bvn') }}" class="nav-item">BVN Services</a>
                <a href="{{ route('vtu.nin-validation') }}" class="nav-item">NIN Validation</a>
                <a href="{{ route('vtu.orders') }}" class="nav-item">My Orders</a>

                <div class="pt-3 border-t border-white/10 mt-3"></div>

                @if(Route::has('admin.dashboard'))
                    <a href="{{ route('admin.dashboard') }}" class="nav-item">Admin Panel</a>
                @endif
            </nav>

            <div class="p-4 mt-auto border-t border-white/10">
                <form method="POST" action="{{ route('logout', absolute: false) }}">
                    @csrf
                    <button class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 font-semibold">
                        Logout
                    </button>
                </form>
            </div>

        </div>
    </aside>

    {{-- âœ… MOBILE SIDEBAR --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-[999]">
        <div class="absolute inset-0 bg-black/60" @click="open = false"></div>

        <div class="absolute top-0 left-0 h-full w-80 max-w-[85%] bg-[#07101f] border-r border-white/10 p-4">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('images/logo.png') }}" class="h-8 w-auto" alt="BelovedSubP Logo">
                    <span class="font-extrabold text-white">
                        Beloved<span class="text-orange-500">SubP</span>
                    </span>
                </div>

                <button
                    class="w-10 h-10 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10"
                    @click="open = false"
                >
                    âœ•
                </button>
            </div>

            <div class="p-4 bg-white/5 border border-white/10 rounded-2xl mb-4">
                <p class="text-xs text-white/50">Signed in as</p>
                <p class="font-bold mt-1">{{ $user->name }}</p>
                <p class="text-xs text-white/50">{{ $user->email }}</p>
                <p class="mt-3 text-sm">
                    Wallet:
                    <span class="font-bold text-orange-400">
                        â‚¦{{ number_format($walletBalance / 100, 2) }}
                    </span>
                </p>
            </div>

            <nav class="space-y-2">
                <a href="{{ route('dashboard') }}" class="nav-item" @click="open=false">Dashboard</a>
                <a href="{{ route('wallet.fund') }}" class="nav-item" @click="open=false">Fund Wallet</a>
                <a href="{{ route('wallet.transactions') }}" class="nav-item" @click="open=false">Transactions</a>

                <div class="pt-3 border-t border-white/10 mt-3"></div>

                <a href="{{ route('vtu.airtime') }}" class="nav-item" @click="open=false">Buy Airtime</a>
                <a href="{{ route('vtu.data') }}" class="nav-item" @click="open=false">Buy Data</a>
                <a href="{{ route('vtu.cable') }}" class="nav-item" @click="open=false">Cable TV</a>
                <a href="{{ route('vtu.electricity') }}" class="nav-item" @click="open=false">Electricity</a>
                <a href="{{ route('vtu.exam') }}" class="nav-item" @click="open=false">Exam Pins</a>
                <a href="{{ route('vtu.recharge-card') }}" class="nav-item" @click="open=false">Recharge PIN</a>
                <a href="{{ route('vtu.premium-apps') }}" class="nav-item" @click="open=false">Premium Apps</a>
                <a href="{{ route('vtu.nin') }}" class="nav-item" @click="open=false">NIN Services</a>
                <a href="{{ route('vtu.bvn') }}" class="nav-item" @click="open=false">BVN Services</a>
                <a href="{{ route('vtu.nin-validation') }}" class="nav-item" @click="open=false">NIN Validation</a>
                <a href="{{ route('vtu.orders') }}" class="nav-item" @click="open=false">My Orders</a>

                @if(Route::has('admin.dashboard'))
                    <div class="pt-3 border-t border-white/10 mt-3"></div>
                    <a href="{{ route('admin.dashboard') }}" class="nav-item" @click="open=false">Admin Panel</a>
                @endif
            </nav>

            <div class="mt-6">
                <form method="POST" action="{{ route('logout', absolute: false) }}">
                    @csrf
                    <button class="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10 font-semibold">
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>

