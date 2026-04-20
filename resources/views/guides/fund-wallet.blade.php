<x-guest-layout
    page-title="How to Fund Your VTU Wallet"
    meta-description="How to fund your VTU wallet on BelovedSubP using Flutterwave and dedicated virtual account transfer in Nigeria."
    meta-keywords="how to fund vtu wallet, fund wallet nigeria, flutterwave wallet funding, dedicated virtual account">
    <div class="max-w-4xl mx-auto px-4 py-10 space-y-6">
        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h1 class="text-3xl font-extrabold">How to Fund Your VTU Wallet</h1>
            <p class="mt-3 text-sm opacity-80">
                You can fund your BelovedSubP wallet with Flutterwave card/bank checkout or by transferring to your dedicated virtual account.
            </p>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-4">
            <h2 class="text-xl font-extrabold">Option 1: Fund with Flutterwave</h2>
            <ol class="list-decimal pl-5 space-y-2 text-sm opacity-90">
                <li>Open the Wallet Funding page.</li>
                <li>Enter amount and click Pay with Flutterwave.</li>
                <li>Choose transfer, bank app, or card and complete payment.</li>
                <li>Your wallet is credited after successful payment confirmation.</li>
            </ol>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-4">
            <h2 class="text-xl font-extrabold">Option 2: Dedicated Virtual Account</h2>
            <ol class="list-decimal pl-5 space-y-2 text-sm opacity-90">
                <li>Generate your dedicated account on the wallet page.</li>
                <li>Transfer from your bank app to that account number.</li>
                <li>Payment is auto-detected and wallet is credited.</li>
            </ol>
        </div>

        <div class="flex flex-wrap gap-3">
            @auth
                <a href="{{ route('wallet.fund') }}" class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Fund Wallet
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
