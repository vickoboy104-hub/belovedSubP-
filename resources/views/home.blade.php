<x-guest-layout>
    @php
        $homeMarquee = setting('home_marquee_message', setting('popup_message', 'Need NIN services? Click WhatsApp Support to chat with us instantly.'));
        $homeMarqueeEnabled = (string) setting('editor_home_marquee_enabled', '1') === '1';
        $footerPhone = setting('footer_phone', '08165587119');
        $footerEmail = setting('footer_email', 'vickoboy104@gmail.com');
    @endphp

    <div class="app-page space-y-10">
        @if($homeMarqueeEnabled && trim((string) $homeMarquee) !== '')
            <x-nin-marquee :message="$homeMarquee" />
        @endif

        <section class="grid gap-8 lg:grid-cols-[minmax(0,1.2fr)_420px] lg:items-center">
            <div class="space-y-6">
                <div class="app-chip">Fast and Reliable</div>
                <div>
                    <h1 class="app-page-title max-w-3xl">Buy airtime, data, cable, electricity, education and identity services from one clean wallet dashboard.</h1>
                    <p class="app-page-subtitle max-w-2xl">
                        A polished VTU experience built for mobile and desktop, with instant checkout flows, wallet funding and clear service navigation.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn-primary">Go to Dashboard</a>
                    @else
                        <a href="{{ route('register') }}" class="btn-primary">Create Account</a>
                        <a href="{{ route('login') }}" class="btn-outline">Login</a>
                    @endauth
                    <a href="{{ route('download.app') }}" class="btn-soft">Download App</a>
                </div>

                <div id="pricing" class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="app-stat">
                        <div class="app-stat-value">Instant</div>
                        <p class="mt-1 text-xs text-slate-500">Order delivery</p>
                    </div>
                    <div class="app-stat">
                        <div class="app-stat-value">24/7</div>
                        <p class="mt-1 text-xs text-slate-500">Service access</p>
                    </div>
                    <div class="app-stat">
                        <div class="app-stat-value">Secure</div>
                        <p class="mt-1 text-xs text-slate-500">Wallet funding</p>
                    </div>
                    <div class="app-stat">
                        <div class="app-stat-value">Mobile</div>
                        <p class="mt-1 text-xs text-slate-500">First design</p>
                    </div>
                </div>
            </div>

            <div class="app-section-muted p-6 sm:p-8">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="app-kicker">Quick Access</div>
                        <h2 class="mt-2 text-2xl font-extrabold text-slate-900">Most used services</h2>
                    </div>
                    <div class="app-icon-ring">
                        <img src="{{ asset('images/providers/mtn.png') }}" alt="provider" class="h-10 w-10 object-contain">
                    </div>
                </div>

                <div class="mt-6 space-y-3">
                    <a href="{{ route('vtu.data') }}" class="app-service-card block">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">Buy Data</div>
                                <div class="mt-1 text-sm text-slate-500">MTN, Glo, Airtel and 9mobile plans</div>
                            </div>
                            <span class="text-2xl font-bold text-slate-300">›</span>
                        </div>
                    </a>
                    <a href="{{ route('vtu.airtime') }}" class="app-service-card block">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">Buy Airtime</div>
                                <div class="mt-1 text-sm text-slate-500">Instant recharge for all major networks</div>
                            </div>
                            <span class="text-2xl font-bold text-slate-300">›</span>
                        </div>
                    </a>
                    <a href="{{ route('wallet.fund') }}" class="app-service-card block">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">Fund Wallet</div>
                                <div class="mt-1 text-sm text-slate-500">Virtual account and checkout funding</div>
                            </div>
                            <span class="text-2xl font-bold text-slate-300">›</span>
                        </div>
                    </a>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <div class="app-page-title text-[2rem] sm:text-[2.4rem]">Services</div>
                <div class="app-divider mt-4 w-full max-w-6xl"></div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <a href="{{ route('vtu.data') }}" class="app-service-card block">
                    <div class="app-service-title">Data Subscription</div>
                    <p class="app-service-copy">Flexible bundles, quick plan selection and smooth mobile checkout.</p>
                </a>
                <a href="{{ route('vtu.airtime') }}" class="app-service-card block">
                    <div class="app-service-title">Airtime Recharge</div>
                    <p class="app-service-copy">Fast top-up flow with phone suggestions and balance updates.</p>
                </a>
                <a href="{{ route('vtu.cable') }}" class="app-service-card block">
                    <div class="app-service-title">Cable TV</div>
                    <p class="app-service-copy">Pay subscriptions with a simple account lookup and guided form.</p>
                </a>
                <a href="{{ route('vtu.electricity') }}" class="app-service-card block">
                    <div class="app-service-title">Electricity Bills</div>
                    <p class="app-service-copy">Meter-friendly payment flow with clean summaries and receipts.</p>
                </a>
                <a href="{{ route('vtu.exam') }}" class="app-service-card block">
                    <div class="app-service-title">Education Services</div>
                    <p class="app-service-copy">Purchase WAEC, JAMB, NECO and NABTEB products quickly.</p>
                </a>
                <a href="{{ route('vtu.premium-apps') }}" class="app-service-card block">
                    <div class="app-service-title">Premium Apps</div>
                    <p class="app-service-copy">Offer premium digital subscriptions with the same wallet flow.</p>
                </a>
                <a href="{{ route('vtu.nin') }}" class="app-service-card block">
                    <div class="app-service-title">NIN Services</div>
                    <p class="app-service-copy">Identity-related services presented in a clear supportable layout.</p>
                </a>
                <a href="{{ route('wallet.fund') }}" class="app-service-card block">
                    <div class="app-service-title">Wallet Funding</div>
                    <p class="app-service-copy">Top up through virtual account or payment checkout without friction.</p>
                </a>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="app-section p-6 sm:p-8">
                <div class="app-kicker">How it works</div>
                <h2 class="mt-3 text-3xl font-extrabold text-slate-900">A cleaner transaction experience</h2>
                <div class="mt-6 space-y-4">
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="font-extrabold text-slate-900">1. Pick a service</div>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Choose data, airtime, bills, education, premium apps or identity services.</p>
                    </div>
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="font-extrabold text-slate-900">2. Complete your details</div>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Use focused forms with provider plans, recent numbers and confirmation modals.</p>
                    </div>
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="font-extrabold text-slate-900">3. Receive instant result</div>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Track wallet balance, order receipt and recent activity from one dashboard.</p>
                    </div>
                </div>
            </div>

            <div class="app-section p-6 sm:p-8">
                <div class="app-kicker">Helpful Guides</div>
                <div class="mt-4 grid gap-3">
                    <a href="{{ route('guides.cheap-data') }}" class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-900 hover:bg-slate-100">How to Buy Cheap Data in Nigeria</a>
                    <a href="{{ route('guides.fund-wallet') }}" class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-900 hover:bg-slate-100">How to Fund Your VTU Wallet</a>
                    <a href="{{ route('guides.electricity-bills') }}" class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-900 hover:bg-slate-100">How to Buy Electricity Bills Online</a>
                    <a href="{{ route('guides.nin-services') }}" class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-900 hover:bg-slate-100">NIN Services Guide</a>
                    <a href="{{ route('guides.education-services') }}" class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-900 hover:bg-slate-100">Education Services Guide</a>
                    <a href="{{ route('guides.premium-apps') }}" class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-900 hover:bg-slate-100">Premium Apps Guide</a>
                </div>
            </div>
        </section>

        <footer class="app-section p-6 sm:p-8">
            <div class="grid gap-6 md:grid-cols-4 text-sm">
                <div>
                    <div class="text-lg font-extrabold text-slate-900">{{ config('app.name', 'Beloved VTU') }}</div>
                    <p class="mt-2 leading-6 text-slate-500">We provide fast, secure, and reliable VTU services for everyday use.</p>
                </div>

                <div>
                    <div class="font-bold text-slate-900">Quick Links</div>
                    <div class="mt-3 space-y-2 text-slate-500">
                        <a class="block hover:text-slate-900" href="{{ route('home') }}">Home</a>
                        @auth
                            <a class="block hover:text-slate-900" href="{{ route('dashboard') }}">Dashboard</a>
                        @else
                            <a class="block hover:text-slate-900" href="{{ route('login') }}">Login</a>
                            <a class="block hover:text-slate-900" href="{{ route('register') }}">Register</a>
                        @endauth
                    </div>
                </div>

                <div>
                    <div class="font-bold text-slate-900">Services</div>
                    <div class="mt-3 space-y-2 text-slate-500">
                        <div>Data Subscription</div>
                        <div>Airtime Recharge</div>
                        <div>Cable Subscription</div>
                        <div>Electricity Bills</div>
                        <div>Exam Pins</div>
                    </div>
                </div>

                <div>
                    <div class="font-bold text-slate-900">Contact</div>
                    <div class="mt-3 space-y-2 text-slate-500">
                        <div>Phone: {{ $footerPhone }}</div>
                        <div>Email: {{ $footerEmail }}</div>
                        <div>Support: WhatsApp</div>
                    </div>
                </div>
            </div>

            <div class="mt-8 border-t border-slate-200 pt-4 text-xs text-slate-500">
                {{ date('Y') }} {{ config('app.name', 'Beloved VTU') }}. All rights reserved.
            </div>
        </footer>
    </div>
</x-guest-layout>
