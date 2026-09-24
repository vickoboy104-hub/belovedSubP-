<x-app-layout>
    @php
        $netLogos = [
            'mtn' => '/networks/mtn.png',
            'airtel' => '/networks/Airtel.png',
            'glo' => '/networks/glo.png',
            'etisalat' => '/networks/9mobile.png',
        ];

        $markupAirtime = (float) setting('markup_airtime', 0);
    @endphp

    <div class="mx-auto max-w-4xl space-y-5 sm:space-y-6">
        <section class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="app-kicker">Airtime Purchase</div>
                <h1 class="app-page-title mt-2 text-[1.7rem] leading-tight sm:text-[2.3rem]">Buy {{ $serviceLabel }}</h1>
            </div>
            <a href="{{ route('vtu.airtime') }}" class="btn-outline sm:w-auto">All Airtime Services</a>
        </section>

        <section class="app-form-shell space-y-5 sm:space-y-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <p class="max-w-xl text-sm leading-6 text-slate-500 sm:text-[0.95rem]">Enter the phone number, choose the amount, and continue with wallet checkout.</p>
                <div class="app-icon-ring shrink-0">
                    <img src="{{ $netLogos[$serviceSlug] ?? asset('images/providers/mtn.png') }}" alt="{{ $serviceLabel }}" class="h-10 w-10 object-contain">
                </div>
            </div>

            <form id="airtimePurchaseForm" method="POST" action="{{ route('vtu.airtime.buy') }}" class="space-y-4 sm:space-y-5">
                @csrf
                <input type="hidden" id="service_id" name="service_id" value="{{ $serviceSlug }}">

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                        <div class="contact-picker-row mt-2">
                            <input id="phone" type="tel" name="phone" required placeholder="Enter Phone Number" list="airtimePhoneSuggestionList" class="input-field" inputmode="tel" autocomplete="tel-national" data-contact-picker-input>
                            <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#phone" aria-label="Pick phone contact">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                    <path d="M17 21v-8H7v8"></path>
                                    <path d="M7 3v5h8"></path>
                                </svg>
                            </button>
                        </div>
                        @if(!empty($phoneSuggestions ?? []))
                            <datalist id="airtimePhoneSuggestionList">
                                @foreach(($phoneSuggestions ?? []) as $suggestion)
                                    <option value="{{ $suggestion['phone'] }}">{{ $suggestion['label'] }}</option>
                                @endforeach
                            </datalist>
                            <div class="mt-3 flex flex-nowrap gap-2 overflow-x-auto pb-1">
                                @foreach(($phoneSuggestions ?? []) as $suggestion)
                                    <button type="button" class="airtime-phone-suggestion shrink-0 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-phone="{{ $suggestion['phone'] }}">
                                        {{ $suggestion['phone'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700">Amount</label>
                        <input id="amount" type="number" name="amount" required min="50" step="1" placeholder="e.g. 100" class="input-field mt-2">
                        <div class="mt-2 text-xs text-slate-500">Service charge: &#8358;{{ number_format($markupAirtime, 2) }}</div>
                        <div id="payTotalText" class="mt-2 text-xs text-slate-500">You will pay: <span class="font-extrabold text-slate-900">&#8358;0</span></div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach([100, 200, 500, 1000, 2000, 5000] as $presetAmount)
                        <button type="button" class="airtime-amount-preset rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-amount="{{ $presetAmount }}">
                            &#8358;{{ number_format($presetAmount) }}
                        </button>
                    @endforeach
                </div>

                <button type="button" id="airtimeActionBtn" class="btn-primary w-full justify-center py-3.5 text-[0.98rem]">
                    Continue
                </button>
            </form>
        </section>

        <x-confirm-modal id="confirmAirtime" title="Confirm Airtime Purchase" confirmText="Confirm & Buy Airtime" />
    </div>

    <script>
        (function () {
            const serviceLabel = @json($serviceLabel);
            const markupAirtime = Number(@json($markupAirtime));

            const phoneInput = document.getElementById('phone');
            const amountInput = document.getElementById('amount');
            const actionBtn = document.getElementById('airtimeActionBtn');
            const form = document.getElementById('airtimePurchaseForm');
            const payTotalText = document.getElementById('payTotalText').querySelector('span');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmAirtime"]');

            document.querySelectorAll('.airtime-phone-suggestion').forEach((btn) => {
                btn.addEventListener('click', () => {
                    phoneInput.value = btn.getAttribute('data-phone') || '';
                    phoneInput.focus();
                });
            });

            document.querySelectorAll('.airtime-amount-preset').forEach((btn) => {
                btn.addEventListener('click', () => {
                    amountInput.value = btn.getAttribute('data-amount') || '';
                    amountInput.dispatchEvent(new Event('input', { bubbles: true }));
                    amountInput.focus();
                });
            });

            function updatePayTotal() {
                const amount = Number(amountInput.value || 0);
                if (!amount || amount <= 0) {
                    payTotalText.textContent = '₦0';
                    return;
                }

                payTotalText.textContent = '₦' + Number(amount + markupAirtime).toLocaleString();
            }

            amountInput.addEventListener('input', updatePayTotal);

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                } else {
                    alert(message);
                }
            }

            function getErrorMessage(res, data, fallback) {
                if (data && typeof data.message === 'string' && data.message.trim() !== '') return data.message;
                if (data && data.errors) {
                    const firstKey = Object.keys(data.errors)[0];
                    if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey][0]) return data.errors[firstKey][0];
                }
                return fallback;
            }

            async function submitAirtime() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                actionBtn.disabled = true;
                if (typeof window.showGlobalLoader === 'function') window.showGlobalLoader('Processing transaction...');

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(form),
                    });

                    let data = {};
                    try { data = await res.json(); } catch (error) {}

                    const ok = data && data.ok === true;
                    const message = ok ? (data.message || 'Airtime purchase successful.') : getErrorMessage(res, data, 'Transaction failed. Please try again.');

                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok, message, orderId: data?.order_id });
                    } else {
                        notify(ok ? 'success' : 'error', message);
                    }

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }
                } catch (error) {
                    notify('error', 'Network error. Please try again.');
                } finally {
                    if (typeof window.hideGlobalLoader === 'function') window.hideGlobalLoader();
                    form.dataset.submitting = '0';
                    actionBtn.disabled = false;
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.classList.remove('opacity-60');
                    }
                }
            }

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitAirtime();
            });

            actionBtn.addEventListener('click', () => {
                const phone = (phoneInput.value || '').trim();
                const baseAmount = Number(amountInput.value || 0);

                if (!phone || phone.length < 8) return notify('error', 'Please enter a valid phone number.');
                if (!baseAmount || baseAmount < 50) return notify('error', 'Please enter a valid amount (min ₦50).');

                openConfirmModal('confirmAirtime', {
                    service: 'Airtime',
                    network: serviceLabel,
                    customer: phone,
                    amount: '₦' + Number(baseAmount + markupAirtime).toLocaleString(),
                    extra: 'Base amount ₦' + Number(baseAmount).toLocaleString(),
                }, 'airtimePurchaseForm');
            });

            updatePayTotal();
        })();
    </script>
</x-app-layout>
