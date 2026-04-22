<x-app-layout>
    @php
        $logos = [
            'abuja-electric' => '/electricity/aedc.png',
            'eko-electric' => '/electricity/ekedc.png',
            'ibadan-electric' => '/electricity/ibedc.png',
            'ikeja-electric' => '/electricity/ikedc.png',
            'jos-electic' => '/electricity/jed.png',
            'kaduna-electric' => '/electricity/kaduna.png',
            'kano-electric' => '/electricity/kedco.png',
            'portharcourt-electric' => '/electricity/phed.png',
            'aba-electric' => '/electricity/aba.png',
            'yola-electric' => '/electricity/yola.png',
            'benin-electric' => '/electricity/benin.png',
            'enugu-electric' => '/electricity/enugu.png',
        ];
        $markup = (float) setting('markup_electricity', 0);
    @endphp

    <div class="mx-auto max-w-3xl space-y-5 sm:space-y-6">
        <section class="flex items-start justify-between gap-3">
            <div>
                <div class="app-kicker">Electricity</div>
                <h1 class="app-page-title mt-2 text-[1.7rem] leading-tight sm:text-[2.3rem]">Pay {{ $selectedServiceLabel }}</h1>
            </div>
            <a href="{{ route('vtu.electricity') }}" class="btn-outline shrink-0">All Electricity Services</a>
        </section>

        <section class="app-form-shell space-y-4 sm:space-y-5">
            <div class="flex items-start justify-between gap-3">
                <p class="max-w-xl text-sm leading-6 text-slate-500 sm:text-[0.95rem]">Enter the meter details and amount, then continue to checkout.</p>
                <div class="app-icon-ring shrink-0">
                    <img src="{{ asset($logos[$selectedService] ?? '/electricity/electricity.png') }}" alt="{{ $selectedServiceLabel }}" class="h-10 w-10 object-contain">
                </div>
            </div>

            <form id="electricityPurchaseForm" method="POST" action="{{ route('vtu.electricity.buy') }}" class="space-y-4">
                @csrf
                <input type="hidden" id="disco" name="service_id" value="{{ $selectedService }}">

                <div>
                    <label class="block text-sm font-bold text-slate-700">Meter Number</label>
                    <input id="meter" type="text" name="customer_ref" required placeholder="e.g. 12345678901" class="input-field mt-2">
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Meter Type</label>
                    <select id="meterType" name="meter_type" required class="input-field mt-2">
                        <option value="prepaid">Prepaid</option>
                        <option value="postpaid">Postpaid</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Amount</label>
                    <input id="amount" type="number" name="amount" required min="100" step="1" placeholder="e.g. 2000" class="input-field mt-2">
                    @if($markup > 0)
                        <div class="mt-2 text-xs text-slate-500">Additional charges of ₦{{ number_format($markup, 2) }} will be added at checkout.</div>
                    @endif
                </div>

                <button type="button" id="electricityActionBtn" class="btn-primary w-full justify-center py-3.5 text-[0.98rem]">Continue</button>
            </form>
        </section>

        <x-confirm-modal id="confirmElectricity" title="Confirm Electricity Purchase" confirmText="Confirm & Buy" />
    </div>

    <script>
        (function () {
            const discoName = @json($selectedServiceLabel);
            const markup = Number(@json($markup));
            const meterInput = document.getElementById('meter');
            const meterType = document.getElementById('meterType');
            const amountInput = document.getElementById('amount');
            const actionBtn = document.getElementById('electricityActionBtn');
            const form = document.getElementById('electricityPurchaseForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmElectricity"]');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') window.showFlashToast(type, message);
                else alert(message);
            }

            function getErrorMessage(res, data, fallback) {
                if (data && typeof data.message === 'string' && data.message.trim() !== '') return data.message;
                if (data && data.errors) {
                    const firstKey = Object.keys(data.errors)[0];
                    if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey][0]) return data.errors[firstKey][0];
                }
                return fallback;
            }

            actionBtn.addEventListener('click', () => {
                const customer = (meterInput.value || '').trim();
                const mt = meterType.value;
                const amount = Number(amountInput.value || 0);
                const totalDebit = amount + markup;

                if (!customer || customer.length < 6) return notify('error', 'Please enter a valid meter number.');
                if (!amount || amount < 100) return notify('error', 'Please enter a valid amount (min ₦100).');

                openConfirmModal('confirmElectricity', {
                    service: 'Electricity',
                    disco: discoName,
                    meter: customer,
                    type: mt,
                    amount: '₦' + totalDebit.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                    extra: markup > 0
                        ? 'Wallet will be charged (includes ₦' + markup.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' charge)'
                        : 'Wallet will be charged',
                }, 'electricityPurchaseForm');
            });

            async function submitElectricity() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                actionBtn.disabled = true;
                if (confirmBtn) confirmBtn.disabled = true;
                if (typeof window.showGlobalLoader === 'function') window.showGlobalLoader('Processing transaction...');

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(form),
                    });
                    let data = {};
                    try { data = await res.json(); } catch (e) {}

                    const ok = data && data.ok === true;
                    const message = ok ? (data.message || 'Electricity purchase successful.') : getErrorMessage(res, data, 'Transaction failed. Please try again.');

                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok, message, orderId: data?.order_id });
                    } else {
                        notify(ok ? 'success' : 'error', message);
                    }

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') window.updateWalletBalance(balanceKobo);
                } catch (e) {
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

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                submitElectricity();
            });
        })();
    </script>
</x-app-layout>
