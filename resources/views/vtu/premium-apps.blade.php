<x-app-layout>
    @php
        $markupPremium = (float) setting('markup_premium', 0);
    @endphp

    <x-page-hero class="reference-shared-banner" title="Premium Apps" subtitle="Choose the app, pick a plan, enter your details and continue." />

    <div class="reference-flow-page max-w-3xl space-y-5 mx-auto w-full px-4 sm:px-0">
        <div class="rounded-3xl p-6 border border-gray-200 bg-white">
            <form id="premiumAppsForm" method="POST" action="{{ route('vtu.premium-apps.buy') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-extrabold text-gray-500">Service</label>
                        <select id="service_id" name="service_id" required
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <option value="">Select service</option>
                            @foreach(($services ?? []) as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-extrabold text-gray-500">Plan</label>
                        <select id="plan" name="plan" required disabled
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                            <option value="">Select service first...</option>
                        </select>
                        <div id="premiumPlanNotice" class="hidden mt-2 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2.5">
                            <p id="premiumPlanNoticeText" class="text-xs font-medium text-amber-800">This service has no plans right now.</p>
                            <button type="button" id="premiumPlanRetryBtn" class="btn-outline px-3 py-1.5 text-xs font-bold">Try again</button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-extrabold text-gray-500">Email Address</label>
                        <input id="email" name="email" type="email" required placeholder="name@example.com"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                    </div>
                    <div>
                        <label class="text-sm font-extrabold text-gray-500">WhatsApp Number (optional)</label>
                        <div class="contact-picker-row mt-1">
                            <input id="whatsapp" name="whatsapp" type="tel" placeholder="08012345678"
                                   inputmode="tel" autocomplete="tel-national" data-contact-picker-input
                                   class="w-full px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                            <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#whatsapp" aria-label="Pick WhatsApp contact">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                    <path d="M17 21v-8H7v8"></path>
                                    <path d="M7 3v5h8"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-extrabold text-gray-500">Amount (N)</label>
                    <input id="amount" name="amount" type="number" step="0.01" required readonly
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-500">
                    <div class="mt-2 text-xs text-gray-600">
                        You will pay: <span id="amountToPay" class="font-extrabold text-white">N0</span> (includes N{{ number_format($markupPremium, 2) }} charge)
                    </div>
                </div>

                <button type="button" id="premiumActionBtn"
                        class="w-full px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                    Continue
                </button>
            </form>
        </div>

        <x-confirm-modal id="confirmPremium" title="Confirm Premium App Purchase" confirmText="Confirm & Buy" />
    </div>

    <script>
        (function () {
            const markupPremium = Number(@json($markupPremium));
            const serviceSelect = document.getElementById('service_id');
            const planSelect = document.getElementById('plan');
            const amountInput = document.getElementById('amount');
            const amountToPay = document.getElementById('amountToPay');
            const actionBtn = document.getElementById('premiumActionBtn');
            const form = document.getElementById('premiumAppsForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmPremium"]');
            const planNotice = document.getElementById('premiumPlanNotice');
            const planRetryBtn = document.getElementById('premiumPlanRetryBtn');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                } else {
                    alert(message);
                }
            }

            function toCurrency(value) {
                const n = Number(value || 0);
                return 'N' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function getPlanId(plan) {
                return String(plan.plan_id ?? plan.planID ?? plan.planId ?? plan.value ?? plan.code ?? plan.id ?? '').trim();
            }

            function getPlanPrice(plan) {
                const raw = plan.price ?? plan.amount ?? plan.plan_amount ?? plan.planAmount ?? plan.cost ?? 0;
                const n = Number(raw);
                return Number.isFinite(n) ? n : 0;
            }

            function getPlanLabel(plan, planId) {
                const name = String(plan.displayName ?? plan.name ?? plan.plan_name ?? plan.planName ?? plan.description ?? planId || '').trim();
                const price = getPlanPrice(plan);
                return price > 0 ? name + ' - N' + price.toLocaleString() : name;
            }

            function normalizeServiceId(raw) {
                const key = String(raw || '').trim().toLowerCase().replace(/\s+/g, '_');
                if (!key) return '';

                const aliases = {
                    canva_pro: 'canva',
                    'canva-pro': 'canva',
                    canvapro: 'canva',
                    canva_premium: 'canva',
                };

                return aliases[key] || key;
            }

            function updateAmount() {
                const option = planSelect.options[planSelect.selectedIndex];
                const baseAmount = Number(option?.dataset?.amount || 0);

                if (!baseAmount || baseAmount <= 0) {
                    amountInput.value = '';
                    amountToPay.textContent = 'N0';
                    return;
                }

                amountInput.value = baseAmount.toFixed(2);
                amountToPay.textContent = toCurrency(baseAmount + markupPremium);
            }

            function setPlanNotice(visible) {
                planNotice.classList.toggle('hidden', !visible);
            }

            async function loadPlans(serviceId) {
                const normalizedService = normalizeServiceId(serviceId);
                const fallbackServices = normalizedService === 'canva' ? [] : ['canva'];
                const servicesToTry = [normalizedService, ...fallbackServices].filter(Boolean);

                setPlanNotice(false);
                planSelect.innerHTML = '<option value="">Loading plans...</option>';
                planSelect.disabled = true;
                amountInput.value = '';
                amountToPay.textContent = 'N0';

                for (const serviceKey of servicesToTry) {
                    try {
                        const url = '{{ route('gsubz.plans') }}?service=' + encodeURIComponent(serviceKey);
                        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        const plans = Array.isArray(data?.plans) ? data.plans : [];

                        if (plans.length === 0) {
                            continue;
                        }

                        planSelect.innerHTML = '<option value="">Select plan</option>';
                        plans.forEach((plan) => {
                            const planId = getPlanId(plan);
                            if (!planId) return;

                            const option = document.createElement('option');
                            option.value = planId;
                            option.textContent = getPlanLabel(plan, planId);
                            option.dataset.amount = String(getPlanPrice(plan));
                            planSelect.appendChild(option);
                        });

                        if (planSelect.options.length > 1) {
                            planSelect.disabled = false;
                            return;
                        }
                    } catch (e) {
                        // Try next fallback service id.
                    }
                }

                // Without a retry the customer's only way out of this state is a
                // page reload, which reads as a dead button.
                planSelect.innerHTML = '<option value="">No plans returned</option>';
                planSelect.disabled = true;
                setPlanNotice(true);
            }

            planRetryBtn.addEventListener('click', () => {
                if (serviceSelect.value) {
                    loadPlans(serviceSelect.value);
                }
            });

            function getErrorMessage(response, data, fallback) {
                if (data && typeof data.message === 'string' && data.message.trim() !== '') {
                    return data.message;
                }
                if (data && data.errors) {
                    const firstKey = Object.keys(data.errors)[0];
                    if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey][0]) {
                        return data.errors[firstKey][0];
                    }
                }
                if (!response.ok) return fallback;
                return fallback;
            }

            async function submitForm() {
                if (form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                actionBtn.disabled = true;
                if (confirmBtn) confirmBtn.disabled = true;

                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Processing transaction...');
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(form),
                    });

                    let data = {};
                    try {
                        data = await response.json();
                    } catch (e) {
                        data = {};
                    }

                    const ok = data && data.ok === true;
                    const message = ok
                        ? (data.message || 'Purchase successful.')
                        : getErrorMessage(response, data, 'Transaction failed. Please try again.');

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
                        planSelect.innerHTML = '<option value="">Select service first...</option>';
                        planSelect.disabled = true;
                        amountInput.value = '';
                        amountToPay.textContent = 'N0';
                    }
                } catch (e) {
                    notify('error', 'Network error. Please try again.');
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

            serviceSelect.addEventListener('change', () => {
                const serviceId = serviceSelect.value;
                if (!serviceId) {
                    planSelect.innerHTML = '<option value="">Select service first...</option>';
                    planSelect.disabled = true;
                    setPlanNotice(false);
                    amountInput.value = '';
                    amountToPay.textContent = 'N0';
                    return;
                }
                loadPlans(serviceId);
            });

            planSelect.addEventListener('change', updateAmount);

            actionBtn.addEventListener('click', () => {
                const serviceText = serviceSelect.selectedOptions[0]?.textContent || '';
                const planText = planSelect.selectedOptions[0]?.textContent || '';
                const email = document.getElementById('email').value.trim();
                const amount = Number(amountInput.value || 0);

                if (!serviceSelect.value) return notify('error', 'Please select a service.');
                if (!planSelect.value) return notify('error', 'Please select a plan.');
                if (!email) return notify('error', 'Please enter a valid email address.');
                if (!amount || amount <= 0) return notify('error', 'Unable to compute amount for this plan.');

                openConfirmModal('confirmPremium', {
                    service: serviceText,
                    network: serviceText,
                    customer: email,
                    amount: toCurrency(amount + markupPremium),
                    extra: planText,
                }, 'premiumAppsForm');
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitForm();
            });
        })();
    </script>
</x-app-layout>
