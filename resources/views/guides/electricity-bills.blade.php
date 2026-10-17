<x-guest-layout
    page-title="How to Buy Electricity Bills Online in Nigeria"
    meta-description="Simple guide on how to buy electricity bills online in Nigeria on BelovedSubP with fast token delivery."
    meta-keywords="how to buy electricity bills online, pay electricity bills nigeria, buy prepaid meter token online">
    <div class="reference-guide-page max-w-4xl mx-auto px-4 py-10 space-y-6">
        <x-page-hero class="reference-guest-banner" title="How to Buy Electricity Bills Online in Nigeria" subtitle="BelovedSubP lets you pay electricity bills online and receive your token quickly for supported Nigerian discos." :battery="false" />

        <div class="rounded-3xl p-6 border border-gray-200 bg-white">
            <h2 class="text-xl font-extrabold">Quick Steps</h2>
            <ol class="list-decimal pl-5 mt-3 space-y-2 text-sm opacity-90">
                <li>Open the Electricity page and choose your disco.</li>
                <li>Enter meter number and amount to pay.</li>
                <li>Confirm details and submit payment.</li>
                <li>Check receipt page for token and transaction details.</li>
            </ol>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 bg-white">
            <h2 class="text-xl font-extrabold">Need support?</h2>
            <p class="mt-2 text-sm opacity-90">
                If your meter details fail validation or you need help, contact support from the receipt page or homepage.
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            @auth
                <a href="{{ route('vtu.electricity') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Pay Electricity Bill
                </a>
            @else
                <a href="{{ route('register') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Create Account
                </a>
                <a href="{{ route('login') }}" class="px-5 py-3 rounded-2xl border border-gray-200 bg-white text-sm font-bold">
                    Login
                </a>
            @endauth
            <a href="{{ route('home') }}" class="px-5 py-3 rounded-2xl border border-gray-200 bg-white text-sm font-bold">
                Back to Home
            </a>
        </div>
    </div>
</x-guest-layout>
