<x-app-layout>
    @php
        $hasVirtualAccount = !empty($virtual_account['account_number'] ?? null);
        $profileReadyForVirtualAccount = !empty($user?->first_name) && !empty($user?->last_name) && !empty($user?->email);
    @endphp

    <div class="mx-auto max-w-5xl space-y-8">
        <section>
            <h1 class="app-page-title">Fund Wallet</h1>
            <p class="app-page-subtitle">Top up by virtual account transfer or use Flutterwave checkout for instant funding.</p>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_420px]">
            <section class="app-section p-6 sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="app-kicker">Current Balance</div>
                        <div class="mt-3 text-4xl font-extrabold text-slate-900">&#8358;{{ number_format($walletBalanceNaira, 2) }}</div>
                    </div>
                </div>

                @if(session('success'))
                    <div class="mt-5 rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ session('success') }}</div>
                @endif

                @if(session('error'))
                    <div class="mt-5 rounded-2xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">{{ session('error') }}</div>
                @endif

                @if($errors->any())
                    <div class="mt-5 rounded-2xl bg-rose-50 px-4 py-4 text-sm text-rose-700">
                        <div class="font-bold">Please fix the following:</div>
                        <ul class="mt-2 list-disc pl-5">
                            @foreach($errors->all() as $e)
                                <li>{{ $e }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div id="virtual-account" class="mt-6 rounded-[24px] border border-slate-200 bg-slate-50 p-5">
                    <div class="text-2xl font-extrabold text-slate-900">Dedicated Virtual Account</div>
                    <div class="mt-2 text-sm leading-6 text-slate-500">Transfer to this personal account and your wallet will be credited automatically.</div>

                    @if($hasVirtualAccount)
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div class="rounded-[20px] border border-slate-200 bg-white p-4">
                                <div class="text-xs text-slate-500">Bank</div>
                                <div class="mt-2 text-lg font-extrabold text-slate-900">{{ $virtual_account['bank_name'] ?: '-' }}</div>
                            </div>
                            <div class="rounded-[20px] border border-slate-200 bg-white p-4">
                                <div class="text-xs text-slate-500">Account Number</div>
                                <div class="mt-2 text-lg font-extrabold tracking-wide text-slate-900">{{ $virtual_account['account_number'] }}</div>
                            </div>
                            <div class="rounded-[20px] border border-slate-200 bg-white p-4 md:col-span-2">
                                <div class="text-xs text-slate-500">Account Name</div>
                                <div class="mt-2 text-lg font-extrabold text-slate-900">{{ $virtual_account['account_name'] ?: '-' }}</div>
                            </div>
                        </div>
                    @else
                        <div class="mt-4 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-700">You do not have a dedicated virtual account yet. Generate one below.</div>
                    @endif

                    @if(!$profileReadyForVirtualAccount)
                        <div class="mt-4 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            First name, last name and email are required for virtual account generation.
                        </div>
                        <div class="mt-4">
                            <a href="{{ route('profile.edit') }}" class="btn-outline">Complete Profile Details</a>
                        </div>
                    @else
                        <form method="POST" action="{{ route('wallet.virtual-account.assign') }}" class="mt-5 space-y-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                                <input type="text"
                                       name="phone"
                                       value="{{ old('phone', $user->phone) }}"
                                       required
                                       class="input-field mt-2"
                                       placeholder="+2348012345678 or 08012345678">
                                <div class="mt-2 text-xs text-slate-500">Use the phone linked to your bank profile where possible.</div>
                            </div>

                            <button class="btn-primary">
                                {{ $hasVirtualAccount ? 'Regenerate / Refresh Account' : 'Generate Virtual Account' }}
                            </button>
                        </form>
                    @endif
                </div>
            </section>

            <section class="space-y-6">
                <div class="app-section-muted p-6">
                    <div class="text-2xl font-extrabold text-slate-900">Flutterwave Checkout</div>
                    <div class="mt-2 text-sm leading-6 text-slate-500">You can still fund instantly with card or bank checkout.</div>
                    <div class="mt-3 text-xs text-amber-700">A &#8358;{{ number_format((float) ($funding_fee_naira ?? 0), 2) }} fee is deducted from all Flutterwave deposits.</div>

                    <form method="POST" action="{{ route('wallet.fund.submit') }}" class="mt-6 space-y-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-bold text-slate-700">Amount</label>
                            <input type="number" name="amount" min="100" step="1" required id="fundAmountInput" class="input-field mt-2" placeholder="e.g. 2000" value="{{ old('amount') }}">
                            <div class="mt-2 text-xs text-slate-500">Minimum &#8358;100</div>
                            <div class="mt-1 text-xs text-slate-500" id="fundNetText">You will receive: <span class="font-extrabold text-slate-900">&#8358;0.00</span></div>
                        </div>

                        <button class="btn-primary w-full justify-center py-4 text-base">Pay with Flutterwave</button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    <script>
        (function () {
            const fee = Number(@json((float) ($funding_fee_naira ?? 0)));
            const input = document.getElementById('fundAmountInput');
            const netEl = document.getElementById('fundNetText');
            if (!input || !netEl) return;

            function updateNet() {
                const raw = Number(input.value || 0);
                const net = Math.max(0, raw - fee);
                netEl.querySelector('span').textContent =
                    '₦' + net.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            input.addEventListener('input', updateNet);
            updateNet();
        })();
    </script>
</x-app-layout>
