<x-app-layout>
    @php
        // GSUBZ Electricity serviceIDs (as documented)
        $discos = [
            'abuja-electric'        => ['name' => 'Abuja Electric (AEDC)',         'logo' => '/electricity/aedc.png'],
            'eko-electric'          => ['name' => 'Eko Electric (EKEDC)',          'logo' => '/electricity/ekedc.png'],
            'ibadan-electric'       => ['name' => 'Ibadan Electric (IBEDC)',       'logo' => '/electricity/ibedc.png'],
            'ikeja-electric'        => ['name' => 'Ikeja Electric (IKEDC)',        'logo' => '/electricity/ikedc.png'],
            'jos-electic'           => ['name' => 'Jos Electric (JED)',            'logo' => '/electricity/jed.png'],
            'kaduna-electric'       => ['name' => 'Kaduna Electric (KAEDCO)',      'logo' => '/electricity/kaduna.png'],
            'kano-electric'         => ['name' => 'Kano Electric (KEDCO)',         'logo' => '/electricity/kedco.png'],
            'portharcourt-electric' => ['name' => 'Port Harcourt (PHED)',          'logo' => '/electricity/phed.png'],
            'aba-electric'          => ['name' => 'Aba Electric (ABA)',            'logo' => '/electricity/aba.png'],
            'yola-electric'         => ['name' => 'Yola Electric (YEDC)',          'logo' => '/electricity/yola.png'],
            'benin-electric'        => ['name' => 'Benin Electric (BEDC)',         'logo' => '/electricity/benin.png'],
            'enugu-electric'        => ['name' => 'Enugu Electric (EEDC)',         'logo' => '/electricity/enugu.png'],
        ];

        $markup = (float) setting('markup_electricity', 0);
    @endphp

    <div class="max-w-2xl space-y-5 mx-auto w-full px-4 sm:px-0">

        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">Electricity</h2>
                    <p class="text-gray-600 dark:text-white/60 text-sm mt-1">
                        Select DISCO, enter meter number, choose meter type, confirm details, then proceed.
                    </p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-2xl">
                    ⚡
                </div>
            </div>
        </div>

        {{-- DISCO Quick Select --}}
        <div class="grid grid-cols-4 gap-2"
             style="display:grid !important;grid-template-columns:repeat(4,minmax(0,1fr)) !important;gap:0.5rem;">
            @foreach($discos as $key => $d)
                <button type="button"
                        class="disco-card group rounded-xl p-1.5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 hover:bg-gray-50 dark:hover:bg-white/10 transition relative overflow-hidden"
                        data-disco="{{ $key }}"
                        style="min-width:0;">

                    <span class="pointer-events-none absolute -inset-10 opacity-0 group-hover:opacity-100 transition duration-500 blur-2xl bg-white/10"></span>

                    <div class="w-8 h-8 rounded-lg bg-black/5 dark:bg-white/10 border border-white/10 overflow-hidden flex items-center justify-center mx-auto">
                        <img src="{{ $d['logo'] }}"
                             onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=&quot;text-white/70 font-extrabold text-xs&quot;>LOGO</span>';"
                             alt="{{ $d['name'] }}"
                             class="w-full h-full object-cover">
                    </div>

                    <div class="mt-1 text-center font-bold text-[10px] leading-tight">{{ $d['name'] }}</div>
                    <div class="text-center text-[9px] text-gray-600 dark:text-white/50">Tap</div>
                </button>
            @endforeach
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <form id="electricityPurchaseForm" method="POST" action="{{ route('vtu.electricity.buy') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="text-sm font-extrabold text-white/80">DISCO</label>
                    <select id="disco" name="service_id" required
                            class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <option value="">Select DISCO</option>
                        @foreach($discos as $key => $d)
                            <option value="{{ $key }}">{{ $d['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-sm font-extrabold text-white/80">Meter Number</label>
                    <input id="meter" type="text" name="customer_ref" required placeholder="e.g. 12345678901"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                </div>

                <div>
                    <label class="text-sm font-extrabold text-white/80">Meter Type</label>
                    <select id="meterType" name="meter_type" required
                            class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                        <option value="prepaid">Prepaid</option>
                        <option value="postpaid">Postpaid</option>
                    </select>
                </div>

                <div>
                    <label class="text-sm font-extrabold text-white/80">Amount (₦)</label>
                    <input id="amount" type="number" name="amount" required min="100" step="1" placeholder="e.g. 2000"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                    @if($markup > 0)
                        <div class="mt-2 text-xs text-white/50">
                            Note: additional charges of ₦{{ number_format($markup, 2) }} will be added at checkout.
                        </div>
                    @endif
                </div>

                <button type="button" id="electricityActionBtn"
                        class="w-full px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition relative overflow-hidden">
                    <span class="absolute inset-0 opacity-40 animate-pulse bg-white/10"></span>
                    <span class="relative">Proceed</span>
                </button>
            </form>
        </div>

        <x-confirm-modal id="confirmElectricity" title="Confirm Electricity Purchase" confirmText="Confirm & Buy" />
    </div>

    <script>
        (function () {
            const discos = @json($discos);
            const discoSelect = document.getElementById('disco');
            const meterInput  = document.getElementById('meter');
            const meterType   = document.getElementById('meterType');
            const amountInput = document.getElementById('amount');
            const actionBtn   = document.getElementById('electricityActionBtn');
            const form = document.getElementById('electricityPurchaseForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmElectricity"]');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                } else {
                    alert(message);
                }
            }

            function getErrorMessage(res, data, fallback) {
                if (data && typeof data.message === 'string' && data.message.trim() !== '') {
                    return data.message;
                }
                if (data && data.errors) {
                    const firstKey = Object.keys(data.errors)[0];
                    if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey][0]) {
                        return data.errors[firstKey][0];
                    }
                }
                if (!res.ok) return fallback;
                return fallback;
            }

            document.querySelectorAll('.disco-card').forEach(btn => {
                btn.addEventListener('click', () => {
                    discoSelect.value = btn.getAttribute('data-disco');
                });
            });

            actionBtn.addEventListener('click', () => {
                const serviceId = discoSelect.value;
                const customer  = (meterInput.value || '').trim();
                const mt        = meterType.value;
                const amount    = amountInput.value;

                if (!serviceId) return notify('error', 'Please select a DISCO.');
                if (!customer || customer.length < 6) return notify('error', 'Please enter a valid meter number.');
                if (!amount || Number(amount) < 100) return notify('error', 'Please enter a valid amount (min ₦100).');

                const summary = {
                    service: 'Electricity',
                    disco: (discos[serviceId]?.name || serviceId),
                    meter: customer,
                    type: mt,
                    amount: '₦' + Number(amount).toLocaleString(),
                    extra: 'Wallet will be charged',
                };

                openConfirmModal('confirmElectricity', summary, 'electricityPurchaseForm');
            });

            async function submitElectricity() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                actionBtn.disabled = true;
                if (confirmBtn) confirmBtn.disabled = true;

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
                    try {
                        data = await res.json();
                    } catch (e) {
                        data = {};
                    }

                    const ok = data && data.ok === true;
                    const message = ok
                        ? (data.message || 'Electricity purchase successful.')
                        : getErrorMessage(res, data, 'Transaction failed. Please try again.');

                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok, message, orderId: data?.order_id });
                    } else {
                        notify(ok ? 'success' : 'error', message);
                    }

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }

                    if (ok) form.reset();
                } catch (e) {
                    const fallbackMessage = 'Network error. Please try again.';
                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok: false, message: fallbackMessage });
                    } else {
                        notify('error', fallbackMessage);
                    }
                } finally {
                    if (typeof window.hideGlobalLoader === 'function') {
                        window.hideGlobalLoader();
                    }
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
