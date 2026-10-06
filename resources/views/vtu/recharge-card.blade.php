<x-app-layout>
    @php
        $networkLabels = $networkLabels ?? [
            'mtn' => 'MTN',
            'airtel' => 'Airtel',
            'glo' => 'Glo',
            'etisalat' => '9mobile',
        ];

        $networkLogos = [
            'mtn' => '/networks/mtn.png',
            'airtel' => '/networks/Airtel.png',
            'glo' => '/networks/glo.png',
            'etisalat' => '/networks/9mobile.png',
        ];

        $values = $values ?? [100, 200, 400, 500, 1000];
        $markup = (float) ($markup ?? 0);
    @endphp

    <style>
        /* A tile that looks identical whether or not it is chosen is the one
           complaint this page kept getting. */
        .card-network.is-selected {
            border-color: #ea580c !important;
            box-shadow: 0 0 0 2px rgba(234, 88, 12, .35);
            background: #fff7ed;
        }
    </style>

    <x-page-hero class="reference-shared-banner" title="Recharge Card Printing" subtitle="Select network, value and number of PINs, then confirm before purchase." />

    <div class="reference-flow-page max-w-3xl mx-auto w-full px-4 sm:px-0 space-y-5">
        <div class="grid grid-cols-4 gap-2"
             style="display:grid !important;grid-template-columns:repeat(4,minmax(0,1fr)) !important;gap:0.5rem;">
            @foreach($networkLabels as $key => $label)
                <button type="button"
                        class="card-network rounded-xl p-2 border border-gray-200 bg-white text-center"
                        data-network="{{ $key }}"
                        style="min-width:0;">
                    <div class="w-8 h-8 mx-auto rounded-lg overflow-hidden border border-gray-200 bg-slate-100 flex items-center justify-center">
                        <img src="{{ $networkLogos[$key] ?? '' }}" alt="{{ $label }}" class="w-full h-full object-cover"
                             onerror="this.style.display='none';this.parentElement.innerHTML='<span class=&quot;text-xs font-extrabold text-gray-500&quot;>{{ $label }}</span>';">
                    </div>
                    <div class="mt-1 font-extrabold text-xs">{{ $label }}</div>
                    <div class="card-network-state text-[10px] text-gray-600">Tap</div>
                </button>
            @endforeach
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 bg-white">
            <form id="rechargeCardForm" method="POST" action="{{ route('vtu.recharge-card.buy') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-extrabold text-gray-500">Network</label>
                        <select id="network" name="network" required
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <option value="">Select Network</option>
                            @foreach($networkLabels as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-extrabold text-gray-500">Recharge Value</label>
                        <select id="value" name="value" required
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <option value="">Select Value</option>
                            @foreach($values as $value)
                                <option value="{{ $value }}">N{{ number_format($value) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-extrabold text-gray-500">Number of Pins</label>
                        <input id="num_voucher" name="num_voucher" type="number" min="1" step="1" required placeholder="e.g. 10"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                    </div>

                    <div>
                        <label class="text-sm font-extrabold text-gray-500">You Will Pay</label>
                        <input id="amount_display" type="text" readonly value="N0"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <div class="mt-2 text-xs text-gray-500">Markup: N{{ number_format($markup, 2) }}</div>
                    </div>
                </div>

                <button type="button" id="rechargeCardAction"
                        class="w-full px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                    Proceed
                </button>

                <div class="text-xs text-gray-600">
                    You will confirm these details before final submission.
                </div>
            </form>
        </div>

        <x-confirm-modal id="confirmRechargeCard" title="Confirm Recharge Card Purchase" confirmText="Confirm & Buy" />
    </div>

    <script>
        (function () {
            const networkLabels = @json($networkLabels);
            const markup = Number(@json($markup));

            const form = document.getElementById('rechargeCardForm');
            const network = document.getElementById('network');
            const value = document.getElementById('value');
            const qty = document.getElementById('num_voucher');
            const amountDisplay = document.getElementById('amount_display');
            const actionBtn = document.getElementById('rechargeCardAction');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                } else {
                    alert(message);
                }
            }

            function computeTotal() {
                const v = Number(value.value || 0);
                const q = Number(qty.value || 0);
                if (!v || !q) {
                    amountDisplay.value = 'N0';
                    return 0;
                }
                const total = (v * q) + markup;
                amountDisplay.value = 'N' + total.toLocaleString();
                return total;
            }

            const networkTiles = Array.from(document.querySelectorAll('.card-network'));

            function markSelectedNetwork() {
                networkTiles.forEach(btn => {
                    const isSelected = btn.dataset.network === network.value;
                    btn.classList.toggle('is-selected', isSelected);

                    const state = btn.querySelector('.card-network-state');
                    if (state) {
                        state.textContent = isSelected ? 'Selected' : 'Tap';
                    }
                });
            }

            networkTiles.forEach(btn => {
                btn.addEventListener('click', () => {
                    network.value = btn.dataset.network || '';
                    markSelectedNetwork();
                });
            });

            network.addEventListener('change', markSelectedNetwork);
            markSelectedNetwork();

            value.addEventListener('change', computeTotal);
            qty.addEventListener('input', computeTotal);

            actionBtn.addEventListener('click', () => {
                const n = network.value;
                const v = Number(value.value || 0);
                const q = Number(qty.value || 0);
                const total = computeTotal();

                if (!n) return notify('error', 'Please select a network.');
                if (!v) return notify('error', 'Please select a recharge value.');
                if (!q || q < 1) return notify('error', 'Please enter number of pins.');

                const summary = {
                    service: 'Recharge Card Printing',
                    network: networkLabels[n] ?? n,
                    value: 'N' + v.toLocaleString(),
                    pins: String(q),
                    amount: 'N' + total.toLocaleString(),
                };

                openConfirmModal('confirmRechargeCard', summary, 'rechargeCardForm');
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (form.dataset.submitting === '1') return;

                form.dataset.submitting = '1';
                actionBtn.disabled = true;

                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Processing transaction...');
                }

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(form),
                    });

                    let data = {};
                    try { data = await res.json(); } catch (err) { data = {}; }

                    const ok = data && data.ok === true;
                    const message = (data && data.message) ? data.message : (ok ? 'Purchase successful.' : 'Transaction failed.');

                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok, message, orderId: data?.order_id });
                    } else {
                        notify(ok ? 'success' : 'error', message);
                    }

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }

                    if (ok) {
                        form.reset();
                        amountDisplay.value = 'N0';
                    }
                } catch (err) {
                    const fallback = 'Network error. Please try again.';
                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok: false, message: fallback });
                    } else {
                        notify('error', fallback);
                    }
                } finally {
                    if (typeof window.hideGlobalLoader === 'function') {
                        window.hideGlobalLoader();
                    }
                    form.dataset.submitting = '0';
                    actionBtn.disabled = false;
                }
            });
        })();
    </script>
</x-app-layout>
