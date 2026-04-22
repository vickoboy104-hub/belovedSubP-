<x-app-layout>
    @php
        $hasVirtualAccount = !empty($virtual_account['account_number'] ?? null);
        $profileReadyForVirtualAccount = !empty($user?->first_name) && !empty($user?->last_name) && !empty($user?->email);
        $virtualAccountMeta = (array) ($user?->virtual_account_metadata ?? []);
        $usesCustomerIdentity = !empty($virtualAccountMeta['assigned_using_customer_identity'])
            && in_array((string) ($virtualAccountMeta['identity_type'] ?? ''), ['bvn', 'nin'], true);
        $savedIdentityType = old(
            'identity_type',
            !empty($virtualAccountMeta['identity_type'])
                ? (string) $virtualAccountMeta['identity_type']
                : (!empty($user?->flutterwave_nin) ? 'nin' : 'bvn')
        );
        $savedIdentityMask = (string) ($virtualAccountMeta['identity_masked'] ?? '');
        $temporaryAccount = (array) ($temporary_virtual_account ?? []);
        $temporaryExpiresAt = null;
        $temporaryExpired = true;

        if (!empty($temporaryAccount['expires_at'])) {
            try {
                $temporaryExpiresAt = \Illuminate\Support\Carbon::parse((string) $temporaryAccount['expires_at']);
                $temporaryExpired = $temporaryExpiresAt->isPast();
            } catch (\Throwable $e) {
                $temporaryExpiresAt = null;
                $temporaryExpired = false;
            }
        }

        $temporaryReady = !empty($temporaryAccount['account_number']) && !$temporaryExpired;
        $temporaryAmount = (float) ($temporaryAccount['amount_naira'] ?? 0);
        $temporaryExpectedCredit = max(0, $temporaryAmount - (float) ($funding_fee_naira ?? 0));
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <section class="app-section p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="app-kicker">Wallet Funding</div>
                    <h1 class="app-page-title mt-2 text-[1.8rem] sm:text-[2.2rem]">Fund Wallet</h1>
                    <p class="mt-2 text-sm text-slate-500">Use checkout, a one-time transfer account, or your permanent transfer account.</p>
                </div>
                <div class="rounded-[24px] border border-slate-200 bg-slate-50 px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Balance</div>
                    <div class="mt-1 text-3xl font-extrabold text-slate-900">&#8358;{{ number_format($walletBalanceNaira, 2) }}</div>
                    <div class="mt-1 text-xs text-slate-500">Flutterwave deposit fee: &#8358;{{ number_format((float) ($funding_fee_naira ?? 0), 2) }}</div>
                </div>
            </div>
        </section>

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-700">
                <div class="font-bold">Please fix the following:</div>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
            <div class="space-y-5">
                <section class="app-section p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xl font-extrabold text-slate-900">Temporary Virtual Account</div>
                            <p class="mt-1 text-sm text-slate-500">No BVN or NIN needed. Generate a time-limited account for one transfer.</p>
                        </div>
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">Fast option</span>
                    </div>

                    @if($temporaryReady)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Bank</div>
                                <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $temporaryAccount['bank_name'] ?? '-' }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Account Number</div>
                                <div class="mt-1 text-lg font-extrabold tracking-[0.08em] text-slate-900">{{ $temporaryAccount['account_number'] ?? '-' }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Expected Transfer</div>
                                <div class="mt-1 text-lg font-extrabold text-slate-900">&#8358;{{ number_format($temporaryAmount, 2) }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Wallet Gets</div>
                                <div class="mt-1 text-lg font-extrabold text-emerald-700">&#8358;{{ number_format($temporaryExpectedCredit, 2) }}</div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            <div class="font-bold">This account expires {{ $temporaryExpiresAt?->diffForHumans() ?? 'soon' }}.</div>
                            <div class="mt-1">Do not save it. Once it expires, generate a fresh one before sending money.</div>
                        </div>
                    @elseif(!empty($temporaryAccount['account_number']))
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Your last temporary account has expired. Generate a fresh one before making a transfer.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('wallet.virtual-account.temporary') }}" class="mt-4 space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div>
                                <label for="temporaryAmountInput" class="block text-sm font-bold text-slate-700">Amount to fund</label>
                                <input id="temporaryAmountInput"
                                       type="number"
                                       name="temporary_amount"
                                       min="100"
                                       step="1"
                                       required
                                       value="{{ old('temporary_amount') }}"
                                       class="input-field mt-2"
                                       placeholder="e.g. 3000">
                                <div class="mt-2 text-xs text-slate-500" id="temporaryNetText">Wallet gets: <span class="font-extrabold text-slate-900">&#8358;0.00</span></div>
                            </div>
                            <div class="flex items-end">
                                <button class="btn-primary w-full justify-center sm:w-auto">
                                    {{ $temporaryReady ? 'Generate Another' : 'Generate Account' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <details class="app-section p-5 sm:p-6" {{ $hasVirtualAccount || $usesCustomerIdentity ? 'open' : '' }}>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                        <div>
                            <div class="text-xl font-extrabold text-slate-900">Permanent Virtual Account</div>
                            <p class="mt-1 text-sm text-slate-500">Best for repeat funding. Flutterwave needs BVN or NIN for this one.</p>
                        </div>
                        <span class="rounded-full {{ $usesCustomerIdentity ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700' }} px-3 py-1 text-xs font-bold">
                            {{ $usesCustomerIdentity ? 'Active' : 'Setup needed' }}
                        </span>
                    </summary>

                    @if($hasVirtualAccount)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Bank</div>
                                <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $virtual_account['bank_name'] ?: '-' }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Account Number</div>
                                <div class="mt-1 text-lg font-extrabold tracking-[0.08em] text-slate-900">{{ $virtual_account['account_number'] }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Account Name</div>
                                <div class="mt-1 text-lg font-extrabold text-slate-900">{{ $virtual_account['account_name'] ?: '-' }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="text-xs text-slate-500">Identity on file</div>
                                <div class="mt-1 text-sm font-extrabold text-slate-900">
                                    {{ $savedIdentityMask !== '' ? strtoupper((string) ($virtualAccountMeta['identity_type'] ?? 'ID')).' '.$savedIdentityMask : 'Not available' }}
                                </div>
                            </div>
                        </div>
                    @endif

                    @if(!$profileReadyForVirtualAccount)
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Complete your first name, last name, and email in profile before generating a permanent account.
                        </div>
                        <div class="mt-4">
                            <a href="{{ route('profile.edit') }}" class="btn-outline">Complete Profile</a>
                        </div>
                    @elseif(!$usesCustomerIdentity)
                        <form method="POST" action="{{ route('wallet.virtual-account.assign') }}" class="mt-4 space-y-4">
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
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700">Identity Type</label>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <input type="radio" name="identity_type" value="bvn" @checked($savedIdentityType === 'bvn')>
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-900">BVN</span>
                                            <span class="block text-xs text-slate-500">11-digit bank ID</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                        <input type="radio" name="identity_type" value="nin" @checked($savedIdentityType === 'nin')>
                                        <span>
                                            <span class="block text-sm font-extrabold text-slate-900">NIN</span>
                                            <span class="block text-xs text-slate-500">11-digit national ID</span>
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

                            <div class="rounded-2xl bg-slate-50 px-4 py-3 text-xs text-slate-600">
                                Flutterwave uses this only to assign your fixed wallet-funding account.
                            </div>

                            <button class="btn-primary">
                                {{ $hasVirtualAccount ? 'Upgrade to Permanent Account' : 'Generate Permanent Account' }}
                            </button>
                        </form>
                    @else
                        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            Your permanent Flutterwave virtual account is active and ready for repeated wallet funding.
                        </div>
                    @endif
                </details>
            </div>

            <div class="space-y-5">
                <section class="app-section-muted p-5 sm:p-6">
                    <div class="text-xl font-extrabold text-slate-900">Flutterwave Checkout</div>
                    <p class="mt-1 text-sm text-slate-500">Use card, bank transfer, or other checkout options instantly.</p>

                    <form method="POST" action="{{ route('wallet.fund.submit') }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="fundAmountInput" class="block text-sm font-bold text-slate-700">Amount</label>
                            <input id="fundAmountInput"
                                   type="number"
                                   name="amount"
                                   min="100"
                                   step="1"
                                   required
                                   value="{{ old('amount') }}"
                                   class="input-field mt-2"
                                   placeholder="e.g. 2000">
                            <div class="mt-2 text-xs text-slate-500">Minimum &#8358;100</div>
                            <div class="mt-1 text-xs text-slate-500" id="fundNetText">Wallet gets: <span class="font-extrabold text-slate-900">&#8358;0.00</span></div>
                        </div>

                        <button class="btn-primary w-full justify-center">Pay with Flutterwave</button>
                    </form>
                </section>

                <section class="app-section p-5">
                    <div class="text-sm font-extrabold uppercase tracking-[0.16em] text-slate-500">Quick Notes</div>
                    <div class="mt-3 space-y-3 text-sm text-slate-600">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">Temporary accounts are one-time and must be used before expiry.</div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">Permanent accounts stay with your profile after BVN or NIN setup.</div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">All Flutterwave wallet deposits use the same funding fee shown above.</div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const fee = Number(@json((float) ($funding_fee_naira ?? 0)));
            const checkoutInput = document.getElementById('fundAmountInput');
            const checkoutNetEl = document.getElementById('fundNetText');
            const temporaryInput = document.getElementById('temporaryAmountInput');
            const temporaryNetEl = document.getElementById('temporaryNetText');
            const radios = Array.from(document.querySelectorAll('input[name="identity_type"]'));
            const bvnField = document.getElementById('wallet-bvn-field');
            const ninField = document.getElementById('wallet-nin-field');

            function updateNet(input, target) {
                if (!input || !target) return;
                const raw = Number(input.value || 0);
                const net = Math.max(0, raw - fee);
                const amountEl = target.querySelector('span');
                if (amountEl) {
                    amountEl.textContent = '\u20A6' + net.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                }
            }

            function updateIdentityFields() {
                const selected = radios.find((radio) => radio.checked)?.value || 'bvn';
                if (bvnField) bvnField.classList.toggle('hidden', selected !== 'bvn');
                if (ninField) ninField.classList.toggle('hidden', selected !== 'nin');
            }

            if (checkoutInput) {
                checkoutInput.addEventListener('input', () => updateNet(checkoutInput, checkoutNetEl));
                updateNet(checkoutInput, checkoutNetEl);
            }

            if (temporaryInput) {
                temporaryInput.addEventListener('input', () => updateNet(temporaryInput, temporaryNetEl));
                updateNet(temporaryInput, temporaryNetEl);
            }

            radios.forEach((radio) => radio.addEventListener('change', updateIdentityFields));
            updateIdentityFields();
        })();
    </script>
</x-app-layout>
