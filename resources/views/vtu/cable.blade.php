@php
    $markupCable = (float) setting('markup_cable', 0);
    $icons = [
        'dstv' => asset('cable/dstv.png'),
        'gotv' => asset('cable/gotv.png'),
        'startimes' => asset('cable/startimes.png'),
    ];
@endphp

<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-8">
        <section class="flex items-center justify-between gap-4">
            <div>
                <div class="app-kicker">Cable Subscription</div>
                <h1 class="app-page-title mt-2 text-[2.1rem] sm:text-[2.6rem]">Buy {{ $selectedServiceLabel }}</h1>
                <p class="app-page-subtitle">This page is dedicated to {{ $selectedServiceLabel }} only.</p>
            </div>
            <a href="{{ route('vtu.cable') }}" class="btn-outline">All Cable Services</a>
        </section>

        <form id="cableForm" method="POST" action="{{ route('vtu.cable.buy') }}" class="app-form-shell space-y-5">
            @csrf
            <input type="hidden" name="service_id" id="service_id" value="{{ $selectedService }}">

            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-3xl font-extrabold text-slate-900">{{ $selectedServiceLabel }}</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Select the bouquet, enter the smartcard number and continue.</p>
                </div>
                <div class="app-icon-ring">
                    <img src="{{ $icons[$selectedService] ?? '' }}" class="h-10 w-10 object-contain" alt="{{ $selectedServiceLabel }}">
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-slate-700">Provider</label>
                <div class="mt-2 rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Selected provider: <span class="font-extrabold text-slate-900">{{ $selectedServiceLabel }}</span>
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-slate-700">Bouquet / Plan</label>
                <select id="plan" name="plan" required class="input-field mt-2">
                    <option value="">Loading plans...</option>
                </select>
                <input type="hidden" name="base_amount" id="base_amount" value="">
                <div class="mt-2 text-sm text-slate-500">
                    Amount to pay: <span id="amountPreview" class="font-extrabold text-slate-900">&#8358;0.00</span>
                    <span class="text-xs">(includes charges: &#8358;{{ number_format($markupCable, 2) }})</span>
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-slate-700">Smartcard / IUC Number</label>
                <input type="text" name="customer_ref" id="customer_ref" required class="input-field mt-2" placeholder="e.g. 1234567890">
            </div>

            <button type="button" id="openConfirm" class="btn-primary w-full justify-center py-4 text-base">Continue</button>
        </form>
    </div>

    <x-confirm-modal id="cableConfirm" title="Confirm Cable Subscription" confirmText="Confirm & Pay" />

    <script>
        (function () {
            const markup = Number(@json($markupCable));
            const serviceId = @json($selectedService);
            const serviceLabel = @json($selectedServiceLabel);
            const planSelect = document.getElementById('plan');
            const baseAmountInput = document.getElementById('base_amount');
            const amountPreview = document.getElementById('amountPreview');
            const openConfirmBtn = document.getElementById('openConfirm');
            const form = document.getElementById('cableForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="cableConfirm"]');

            function formatNaira(n) {
                try { return '₦' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
                catch (e) { return '₦' + (Number(n) || 0).toFixed(2); }
            }

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

            function updateAmountPreview() {
                const base = Number(baseAmountInput.value || 0);
                amountPreview.textContent = formatNaira(base + markup);
            }

            async function loadPlans() {
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
                        const priceText = Number.isFinite(priceNum) && priceNum > 0 ? ('₦' + priceNum.toLocaleString()) : '';

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

            planSelect.addEventListener('change', () => {
                const selected = planSelect.options[planSelect.selectedIndex];
                baseAmountInput.value = selected?.dataset?.baseAmount || '';
                updateAmountPreview();
            });

            openConfirmBtn.addEventListener('click', () => {
                const plan = planSelect.value;
                const planLabel = planSelect.options[planSelect.selectedIndex]?.textContent || plan;
                const customerRef = document.getElementById('customer_ref').value;
                const base = Number(baseAmountInput.value || 0);
                if (!plan || !customerRef || !base) {
                    notify('error', 'Please select plan and enter your smartcard/IUC number.');
                    return;
                }

                window.openConfirmModal('cableConfirm', {
                    service: 'Cable TV',
                    provider: serviceLabel,
                    plan: planLabel,
                    'smartcard number': customerRef,
                    amount: formatNaira(base + markup),
                }, 'cableForm');
            });

            async function submitCable() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                openConfirmBtn.disabled = true;
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
                    const message = ok ? (data.message || 'Cable subscription successful.') : getErrorMessage(res, data, 'Transaction failed. Please try again.');

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
            loadPlans();
        })();
    </script>
</x-app-layout>
