<x-guest-layout
    page-title="Education Result Checker Pins in Nigeria"
    meta-description="Buy JAMB, WAEC, NECO and NABTEB result checker pins online in Nigeria with fast delivery on BelovedSubP."
    meta-keywords="jamb pin nigeria, waec result checker pin, neco pin, nabteb pin, education services nigeria, belovedsubp education">
    <div class="max-w-4xl mx-auto px-4 py-10 space-y-6">
        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h1 class="text-3xl font-extrabold">Education Services Guide</h1>
            <p class="mt-3 text-sm opacity-80">
                BelovedSubP supports education services like JAMB PIN, WAEC result checker PIN, NECO, and NABTEB.
                Select your preferred service, choose a plan, and complete payment from your wallet.
            </p>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-4">
            <h2 class="text-xl font-extrabold">Available Education Services</h2>
            <ul class="list-disc pl-5 space-y-2 text-sm opacity-90">
                <li>JAMB PIN (UTME and Direct Entry)</li>
                <li>WAEC Result Checker PIN</li>
                <li>NECO Result Checker PIN</li>
                <li>NABTEB Result Checker PIN</li>
            </ul>
        </div>

        <div class="flex flex-wrap gap-3">
            @auth
                <a href="{{ route('vtu.exam') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Open Education Services
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
