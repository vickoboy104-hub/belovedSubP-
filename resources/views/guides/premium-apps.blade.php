<x-guest-layout
    page-title="Premium App Subscriptions in Nigeria"
    meta-description="Buy premium app subscriptions in Nigeria on BelovedSubP with easy plan selection and secure wallet payments."
    meta-keywords="premium apps nigeria, canva pro nigeria, app subscriptions nigeria, belovedsubp premium">
    <div class="max-w-4xl mx-auto px-4 py-10 space-y-6">
        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h1 class="text-3xl font-extrabold">Premium Apps Guide</h1>
            <p class="mt-3 text-sm opacity-80">
                Use BelovedSubP to subscribe to premium digital tools like Canva Pro.
                Choose service and plan, provide your email, then complete payment from your wallet.
            </p>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-4">
            <h2 class="text-xl font-extrabold">Why Use BelovedSubP</h2>
            <ul class="list-disc pl-5 space-y-2 text-sm opacity-90">
                <li>Simple service and plan selection.</li>
                <li>Clear pricing before confirmation.</li>
                <li>Fast order processing after payment.</li>
            </ul>
        </div>

        <div class="flex flex-wrap gap-3">
            @auth
                <a href="{{ route('vtu.premium-apps') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Open Premium Apps
                </a>
            @else
                <a href="{{ route('register') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Create Account
                </a>
                <a href="{{ route('login') }}" class="px-5 py-3 rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 text-sm font-bold">
                    Login
                </a>
            @endauth
            <a href="{{ route('home') }}" class="px-5 py-3 rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 text-sm font-bold">
                Back to Home
            </a>
        </div>
    </div>
</x-guest-layout>
