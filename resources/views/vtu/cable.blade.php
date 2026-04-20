@php
    $markupCable = (float) setting('markup_cable', 0);
    $icons = [
        'dstv' => asset('cable/dstv.png'),
        'gotv' => asset('cable/gotv.png'),
        'startimes' => asset('cable/startimes.png'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Cable Subscription</x-slot>

    <div class="max-w-2xl mx-auto space-y-5">

        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Cable TV</h2>
                    <p class="text-gray-700/70 dark:text-white/60 text-sm mt-1">
                        Choose provider, select plan, enter smartcard/IUC number, confirm, then proceed.
                    </p>
                </div>

                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-gray-200 dark:border-white/10 flex items-center justify-center text-2xl">
                    📺
                </div>
            </div>
        </div>

        <form id="cableForm" method="POST" action="{{ route('vtu.cable.buy') }}"
              class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 space-y-5">
            @csrf

            {{-- Provider --}}
            <div>
                <label class="text-sm font-semibold text-gray-800 dark:text-white/80">Provider</label>
                <div class="mt-2 grid grid-cols-4 gap-2"
                     style="display:grid !important;grid-template-columns:repeat(4,minmax(0,1fr)) !important;gap:0.5rem;">
                    @foreach($services as $id => $label)
                        <button type="button"
                                class="providerBtn group rounded-xl p-2 border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 hover:bg-gray-100 dark:hover:bg-white/10 transition text-center"
                                data-service="{{ $id }}"
                                data-label="{{ $label }}"
                                style="min-width:0;">
                            <div class="w-8 h-8 mx-auto rounded-lg bg-white dark:bg-white/10 border border-gray-200 dark:border-white/10 overflow-hidden flex items-center justify-center">
                                <img src="{{ $icons[$id] ?? '' }}" class="w-full h-full object-cover" alt="{{ $label }}">
                            </div>
                            <div class="mt-1 font-extrabold text-xs text-gray-900 dark:text-white">{{ strtoupper($id) }}</div>
                        </button>
                    @endforeach
                </div>

                <input type="hidden" name="service_id" id="service_id" required>
            </div>

            {{-- Plan --}}
            <div>
                <label class="text-sm font-semibold text-gray-800 dark:text-white/80">Bouquet / Plan</label>
                <select id="plan" name="plan" required
                        class="w-full mt-2 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-200 dark:border-white/10 text-gray-900 dark:text-white">
                    <option value="">Select provider first...</option>
                </select>
                <input type="hidden" name="base_amount" id="base_amount" value="">

                <div class="mt-2 text-sm text-gray-700/70 dark:text-white/60">
                    Amount to pay: <span id="amountPreview" class="font-extrabold text-gray-900 dark:text-white">₦0.00</span>
                    <span class="text-xs text-gray-600/80 dark:text-white/50">(includes charges: ₦{{ number_format($markupCable, 2) }})</span>
                </div>
            </div>

            {{-- Smartcard / IUC --}}
            <div>
                <label class="text-sm font-semibold text-gray-800 dark:text-white/80">Smartcard / IUC Number</label>
                <input type="text" name="customer_ref" id="customer_ref" required
                       class="w-full mt-2 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-200 dark:border-white/10 text-gray-900 dark:text-white"
                       placeholder="e.g. 1234567890">
            </div>

            {{-- Action --}}
            <div class="pt-2">
                <button type="button" id="openConfirm"
                        class="w-full px-6 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold shadow-lg transition">
                    Continue
                </button>
            </div>
        </form>
    </div>

    <x-confirm-modal id="cableConfirm" title="Confirm Cable Subscription" confirmText="Confirm & Pay" />

    <script>
        (function(){
            const markup = Number(@json($markupCable));
            const providerBtns = Array.from(document.querySelectorAll('.providerBtn'));
            const serviceInput = document.getElementById('service_id');
            const planSelect = document.getElementById('plan');
            const baseAmountInput = document.getElementById('base_amount');
            const amountPreview = document.getElementById('amountPreview');
            const openConfirmBtn = document.getElementById('openConfirm');
            const form = document.getElementById('cableForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="cableConfirm"]');

            function setActiveProvider(serviceId){
                providerBtns.forEach(btn => {
                    const isActive = btn.dataset.service === serviceId;
                    btn.classList.toggle('ring-2', isActive);
                    btn.classList.toggle('ring-orange-500', isActive);
                });
            }

            function formatNaira(n){
                try { return '₦' + (Number(n) || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}); }
                catch(e){ return '₦' + (Number(n) || 0).toFixed(2); }
            }

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

            function updateAmountPreview(){
                const base = Number(baseAmountInput.value || 0);
                const total = base + markup;
                amountPreview.textContent = formatNaira(total);
            }

            async function loadPlans(serviceId){
                planSelect.innerHTML = '<option value="">Loading plans...</option>';
                baseAmountInput.value = '';
                updateAmountPreview();

                try {
                    const url = @json(route('gsubz.plans')) + '?service=' + encodeURIComponent(serviceId);
                    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const json = await res.json();

                    if (!json.ok) {
                        planSelect.innerHTML = '<option value="">Could not load plans. Try again.</option>';
                        return;
                    }

                    const plans = Array.isArray(json.plans) ? json.plans : [];
                    if (plans.length === 0) {
                        planSelect.innerHTML = '<option value="">No plans returned for this provider.</option>';
                        return;
                    }

                    planSelect.innerHTML = '<option value="">Select a plan...</option>';
                    plans.forEach(p => {
                        const planId = (p.value ?? p.plan ?? p.variation_code ?? p.code ?? p.id ?? '').toString();
                        if (!planId) return;

                        const label = (p.displayName ?? p.name ?? p.plan ?? p.value ?? planId).toString();
                        const rawPrice = p.price ?? p.amount ?? p.cost ?? 0;
                        const priceNum = Number(rawPrice);
                        const priceText = Number.isFinite(priceNum) && priceNum > 0
                            ? ('₦' + priceNum.toLocaleString())
                            : '';

                        const option = document.createElement('option');
                        option.value = planId;
                        option.textContent = priceText ? `${label} - ${priceText}` : label;
                        option.dataset.baseAmount = String(rawPrice ?? '');
                        planSelect.appendChild(option);
                    });
                } catch (e) {
                    planSelect.innerHTML = '<option value="">Network error loading plans.</option>';
                }
            }

            providerBtns.forEach(btn => {
                btn.addEventListener('click', async () => {
                    const serviceId = btn.dataset.service;
                    serviceInput.value = serviceId;
                    setActiveProvider(serviceId);
                    await loadPlans(serviceId);
                });
            });

            planSelect.addEventListener('change', () => {
                const selected = planSelect.options[planSelect.selectedIndex];
                baseAmountInput.value = selected?.dataset?.baseAmount || '';
                updateAmountPreview();
            });

            openConfirmBtn.addEventListener('click', () => {
                const serviceId = serviceInput.value;
                const plan = planSelect.value;
                const planLabel = planSelect.options[planSelect.selectedIndex]?.textContent || plan;
                const customerRef = document.getElementById('customer_ref').value;
                const base = Number(baseAmountInput.value || 0);

                if (!serviceId || !plan || !customerRef || !base) {
                    notify('error', 'Please select provider, plan, and enter your smartcard/IUC number.');
                    return;
                }

                const providerLabel = (providerBtns.find(b => b.dataset.service === serviceId)?.dataset.label) || serviceId;
                const total = base + markup;

                window.openConfirmModal(
                    'cableConfirm',
                    {
                        service: 'Cable TV',
                        provider: providerLabel,
                        plan: planLabel,
                        'smartcard number': customerRef,
                        amount: formatNaira(total),
                    },
                    'cableForm'
                );
            });


            async function submitCable() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                openConfirmBtn.disabled = true;
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
                        ? (data.message || 'Cable subscription successful.')
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

                    if (ok) {
                        form.reset();
                        planSelect.innerHTML = '<option value="">Select provider first...</option>';
                        baseAmountInput.value = '';
                        updateAmountPreview();
                        setActiveProvider('');
                    }
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
                    openConfirmBtn.disabled = false;
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.classList.remove('opacity-60');
                    }
                }
            }

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                submitCable();
            });

            updateAmountPreview();
        })();
    </script>
</x-app-layout>
