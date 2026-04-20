<x-guest-layout
    page-title="How to Buy Cheap Data in Nigeria (2026 Guide)"
    meta-description="Learn how to buy cheap MTN, Airtel, Glo and 9mobile data online in Nigeria with fast delivery on BelovedSubP."
    meta-keywords="cheap data nigeria, buy mtn data online, airtel data online, glo data plans, 9mobile data, best vtu website in nigeria">
    <div class="max-w-4xl mx-auto px-4 py-10 space-y-6">
        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h1 class="text-3xl font-extrabold">How to Buy Cheap Data in Nigeria (2026 Guide)</h1>
            <p class="mt-3 text-sm opacity-80">
                BelovedSubP helps you buy cheap and affordable MTN, Airtel, Glo, and 9mobile data online in minutes.
                You can compare available services, choose a plan, and get delivery instantly.
            </p>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-4">
            <h2 class="text-xl font-extrabold">Steps</h2>
            <ol class="list-decimal pl-5 space-y-2 text-sm opacity-90">
                <li>Sign in to your account and open the Data page.</li>
                <li>Select your network: MTN, Airtel, Glo, or 9mobile.</li>
                <li>Choose the service type and data plan you need.</li>
                <li>Enter your phone number and confirm the amount.</li>
                <li>Complete payment from wallet and wait for instant delivery.</li>
            </ol>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-3">
            <h2 class="text-xl font-extrabold">Why users search BelovedSubP</h2>
            <p class="text-sm opacity-90">People looking for these terms can use this guide:</p>
            <p class="text-sm opacity-80">
                cheap data, buy MTN data online, best VTU website in Nigeria, cheap Airtel data, cheap Glo data, buy 9mobile data.
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            @auth
                <a href="{{ route('vtu.data') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Buy Data Now
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
