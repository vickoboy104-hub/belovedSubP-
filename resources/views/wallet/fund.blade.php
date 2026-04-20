<x-app-layout>
    @php
        $hasVirtualAccount = !empty($virtual_account['account_number'] ?? null);
        $profileReadyForVirtualAccount = !empty($user?->first_name) && !empty($user?->last_name) && !empty($user?->email);
        $virtualAccountMeta = (array) ($user?->virtual_account_metadata ?? []);
        $usesCustomerIdentity = !empty($virtualAccountMeta['assigned_using_customer_identity'])
            && in_array((string) ($virtualAccountMeta['identity_type'] ?? ''), ['bvn', 'nin'], true);
        $legacyVirtualAccount = $hasVirtualAccount && !$usesCustomerIdentity;
        $needsPermanentVirtualAccountSetup = !$usesCustomerIdentity;
        $savedIdentityType = old(
            'identity_type',
            !empty($virtualAccountMeta['identity_type'])
                ? (string) $virtualAccountMeta['identity_type']
                : (!empty($user?->flutterwave_nin) ? 'nin' : 'bvn')
        );
        $savedIdentityMask = (string) ($virtualAccountMeta['identity_masked'] ?? '');
    @endphp

    <div class="mx-auto max-w-5xl space-y-8">
        <section>
            <h1 class="app-page-title">Fund Wallet</h1>
            <p class="app-page-subtitle">Top up by permanent virtual account transfer or use Flutterwave checkout for instant funding.</p>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_420px]">
            <section class="app-section p-6 sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="app-kicker">Current Balance</div>
                        <div class="mt-3 text-4xl font-extrabold text-slate-900">₦{{ number_format($walletBalanceNaira, 2) }}</div>
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
                            <div class="rounded-[20px] border border-slate-200 bg-white p-4">
                                <div class="text-xs text-slate-500">Account Name</div>
                                <div class="mt-2 text-lg font-extrabold text-slate-900">{{ $virtual_account['account_name'] ?: '-' }}</div>
                            </div>
                            <div class="rounded-[20px] border border-slate-200 bg-white p-4">
                                <div class="text-xs text-slate-500">Status</div>
                                <div class="mt-2 text-sm font-extrabold {{ $usesCustomerIdentity ? 'text-emerald-700' : 'text-amber-700' }}">
                                    {{ $usesCustomerIdentity ? 'Permanent customer-bound account' : 'Needs customer identity upgrade' }}
                                </div>
                                @if($savedIdentityMask !== '')
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ strtoupper((string) ($virtualAccountMeta['identity_type'] ?? '')) }} on file: {{ $savedIdentityMask }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="mt-4 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-700">You do not have a dedicated virtual account yet. Generate one below.</div>
                    @endif

                    @if($legacyVirtualAccount)
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            This account came from the older setup. To move to the proper fixed Flutterwave account per user, replace it with your own BVN or NIN below.
                        </div>
                    @endif

                    @if(!$profileReadyForVirtualAccount)
                        <div class="mt-4 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            First name, last name and email are required for virtual account generation.
                        </div>
                        <div class="mt-4">
                            <a href="{{ route('profile.edit') }}" class="btn-outline">Complete Profile Details</a>
                        </div>
                    @elseif($needsPermanentVirtualAccountSetup)
                        <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                            <div class="text-sm font-extrabold text-slate-900">Flutterwave permanent account requirement</div>
                            <div class="mt-2 text-sm leading-6 text-slate-600">
                                Flutterwave requires a customer's BVN or NIN before it can issue a fixed NGN virtual account. If you do not want to provide that yet, you can still use Flutterwave checkout on this page.
                            </div>
                        </div>

                        <form method="POST" action="{{ route('wallet.virtual-account.assign') }}" class="mt-5 space-y-4">
                            @csrf

                            <div>
                                <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                                <div class="contact-picker-row mt-2">
                                    <input type="tel"
                                           id="fund-wallet-phone"
                                           name="phone"
                                           value="{{ old('phone', $user->phone) }}"
                                           required
                                           class="input-field"
                                           placeholder="+2348012345678 or 08012345678"
                                           inputmode="tel"
                                           autocomplete="tel-national"
                                           data-contact-picker-input>
                                    <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#fund-wallet-phone" aria-label="Pick phone contact">
                                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                            <path d="M17 21v-8H7v8"></path>
                                            <path d="M7 3v5h8"></path>
                                        </svg>
                                    </button>
                                </div>
                                <div class="mt-2 text-xs text-slate-500">Use the phone linked to your bank profile where possible.</div>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700">Identity Type</label>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                        <input type="radio" name="identity_type" value="bvn" @checked($savedIdentityType === 'bvn')>
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-900">BVN</span>
                                            <span class="block text-xs text-slate-500">Bank Verification Number</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                        <input type="radio" name="identity_type" value="nin" @checked($savedIdentityType === 'nin')>
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-900">NIN</span>
                                            <span class="block text-xs text-slate-500">National Identification Number</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div id="wallet-bvn-field" class="{{ $savedIdentityType === 'bvn' ? '' : 'hidden' }}">
                                <label class="block text-sm font-bold text-slate-700">BVN</label>
                                <input type="text"
                                       name="bvn"
                                       inputmode="numeric"
                                       maxlength="11"
                                       value="{{ old('bvn') }}"
                                       class="input-field mt-2"
                                       placeholder="Enter your 11-digit BVN">
                            </div>

                            <div id="wallet-nin-field" class="{{ $savedIdentityType === 'nin' ? '' : 'hidden' }}">
                                <label class="block text-sm font-bold text-slate-700">NIN</label>
                                <input type="text"
                                       name="nin"
                                       inputmode="numeric"
                                       maxlength="11"
                                       value="{{ old('nin') }}"
                                       class="input-field mt-2"
                                       placeholder="Enter your 11-digit NIN">
                            </div>

                            <div class="rounded-2xl bg-slate-900 px-4 py-3 text-xs leading-6 text-slate-100">
                                Your BVN or NIN is only used for Flutterwave permanent account assignment and is stored securely on your account.
                            </div>

                            <button class="btn-primary">
                                {{ $legacyVirtualAccount ? 'Replace with Permanent Account' : 'Generate Permanent Account' }}
                            </button>
                        </form>
                    @else
                        <div class="mt-4 rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            Your permanent Flutterwave virtual account is active. You can keep using the same account for wallet funding.
                        </div>
                    @endif
                </div>
            </section>

            <section class="space-y-6">
                <div class="app-section-muted p-6">
                    <div class="text-2xl font-extrabold text-slate-900">Flutterwave Checkout</div>
                    <div class="mt-2 text-sm leading-6 text-slate-500">You can still fund instantly with card or bank checkout.</div>
                    <div class="mt-3 text-xs text-amber-700">A ₦{{ number_format((float) ($funding_fee_naira ?? 0), 2) }} fee is deducted from all Flutterwave deposits.</div>

                    <form method="POST" action="{{ route('wallet.fund.submit') }}" class="mt-6 space-y-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-bold text-slate-700">Amount</label>
                            <input type="number" name="amount" min="100" step="1" required id="fundAmountInput" class="input-field mt-2" placeholder="e.g. 2000" value="{{ old('amount') }}">
                            <div class="mt-2 text-xs text-slate-500">Minimum ₦100</div>
                            <div class="mt-1 text-xs text-slate-500" id="fundNetText">You will receive: <span class="font-extrabold text-slate-900">₦0.00</span></div>
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
            const radios = Array.from(document.querySelectorAll('input[name="identity_type"]'));
            const bvnField = document.getElementById('wallet-bvn-field');
            const ninField = document.getElementById('wallet-nin-field');

            function updateNet() {
                if (!input || !netEl) return;
                const raw = Number(input.value || 0);
                const net = Math.max(0, raw - fee);
                netEl.querySelector('span').textContent =
                    '₦' + net.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function updateIdentityFields() {
                const selected = radios.find((radio) => radio.checked)?.value || 'bvn';
                if (bvnField) bvnField.classList.toggle('hidden', selected !== 'bvn');
                if (ninField) ninField.classList.toggle('hidden', selected !== 'nin');
            }

            if (input) {
                input.addEventListener('input', updateNet);
                updateNet();
            }

            radios.forEach((radio) => radio.addEventListener('change', updateIdentityFields));
            updateIdentityFields();
        })();
    </script>
</x-app-layout>
