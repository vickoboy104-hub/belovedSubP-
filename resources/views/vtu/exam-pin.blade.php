<x-app-layout>
    @php
        $examLogos = [
            'jamb' => '/exams/jamb.png',
            'waec' => '/images/providers/waec.png',
            'neco' => '/images/providers/neco.png',
            'nabteb' => '/images/providers/nabteb.png',
        ];

        $transactionFee = (float) setting('price_exam_transaction_fee', setting('markup_exam', 100));
        $prices = [
            'jamb' => (float) setting('price_exam_jamb', 0),
            'waec' => (float) setting('price_exam_waec', 0),
            'neco' => (float) setting('price_exam_neco', 0),
            'nabteb' => (float) setting('price_exam_nabteb', 0),
        ];
    @endphp

    <div class="mx-auto max-w-3xl space-y-8">
        <section class="flex items-center justify-between gap-4">
            <div>
                <div class="app-kicker">Education Services</div>
                <h1 class="app-page-title mt-2 text-[2.1rem] sm:text-[2.6rem]">Buy {{ $selectedServiceLabel }}</h1>
                <p class="app-page-subtitle">Use this dedicated page for {{ $selectedServiceLabel }} only.</p>
            </div>
            <a href="{{ route('vtu.exam') }}" class="btn-outline">All Education Services</a>
        </section>

        <section class="app-form-shell">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-3xl font-extrabold text-slate-900">{{ $selectedServiceLabel }}</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Enter the required details below and continue with wallet checkout.</p>
                </div>
                <div class="app-icon-ring">
                    <img src="{{ asset($examLogos[$selectedService] ?? '/images/providers/waec.png') }}" alt="{{ $selectedServiceLabel }}" class="h-10 w-10 object-contain">
                </div>
            </div>

            <form id="examPurchaseForm" method="POST" action="{{ route('vtu.exam.buy') }}" class="mt-8 space-y-4">
                @csrf
                <input type="hidden" id="pin_code" name="pin_code" value="{{ $selectedService }}">

                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Selected service: <span class="font-extrabold text-slate-900">{{ $selectedServiceLabel }}</span>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Plan</label>
                    <select id="plan" name="plan" class="input-field mt-2">
                        <option value="">Loading plans...</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Amount</label>
                    <input id="amountPreview" type="text" readonly class="input-field mt-2 bg-slate-50" placeholder="Loading price">
                    <input id="amount" type="hidden" name="amount">
                </div>

                <div id="profileIdWrap" class="{{ $selectedService === 'jamb' ? '' : 'hidden' }}">
                    <label class="block text-sm font-bold text-slate-700">Profile ID (JAMB only)</label>
                    <input id="profile_id" name="profile_id" type="text" placeholder="Enter profile ID" class="input-field mt-2">
                    <p class="mt-2 text-xs text-slate-500">For JAMB, send "NIN 12345678901" to 55019 to generate your profile code.</p>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                    <div class="contact-picker-row mt-2">
                        <input id="phone" type="tel" name="phone" required placeholder="e.g. 08012345678" class="input-field" inputmode="tel" autocomplete="tel-national" data-contact-picker-input>
                        <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#phone" aria-label="Pick phone contact">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                <path d="M17 21v-8H7v8"></path>
                                <path d="M7 3v5h8"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
                    A transaction service charge of &#8358;{{ number_format($transactionFee, 2) }} applies after you click Continue.
                </div>

                <button type="button" id="examActionBtn" class="btn-primary w-full justify-center py-4 text-base">Continue</button>
            </form>
        </section>

        <x-confirm-modal id="confirmExam" title="Confirm Education Purchase" confirmText="Confirm & Buy" />
    </div>

    <script>
        (function () {
            const selectedService = @json($selectedService);
            const serviceLabel = @json($selectedServiceLabel);
            const transactionFee = Number(@json($transactionFee));
            const prices = @json($prices);

            const planSelect = document.getElementById('plan');
            const phone = document.getElementById('phone');
            const amountPreview = document.getElementById('amountPreview');
            const amountInput = document.getElementById('amount');
            const profileInput = document.getElementById('profile_id');
            const actionBtn = document.getElementById('examActionBtn');
            const form = document.getElementById('examPurchaseForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmExam"]');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') window.showFlashToast(type, message);
                else alert(message);
            }

            function normalizePhone(raw) {
                let p = (raw || '').toString().trim();
                p = p.replace(/\s+/g, '').replace(/[^0-9+]/g, '');
                if (p.startsWith('+234')) p = '0' + p.slice(4);
                if (p.startsWith('234')) p = '0' + p.slice(3);
                return p;
            }

            function getPlanId(plan) {
                return String(plan.plan_id ?? plan.planID ?? plan.planId ?? plan.value ?? plan.code ?? plan.id ?? '').trim();
            }

            function getPlanPrice(plan) {
                const raw = plan.price ?? plan.amount ?? plan.plan_amount ?? plan.planAmount ?? plan.cost ?? 0;
                const n = Number(raw);
                return Number.isFinite(n) ? n : 0;
            }

            function applyAmount(amount) {
                if (!amount || amount <= 0) {
                    amountPreview.value = '';
                    amountPreview.placeholder = 'Price not configured in admin settings.';
                    amountInput.value = '';
                    return;
                }
                amountPreview.value = '₦' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                amountInput.value = amount.toFixed(2);
            }

            async function loadPlans() {
                try {
                    const url = '{{ route('gsubz.plans') }}?service=' + encodeURIComponent(selectedService);
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await response.json();
                    const plans = Array.isArray(data?.plans) ? data.plans : [];

                    if (plans.length === 0) {
                        planSelect.innerHTML = '<option value="">Standard plan</option>';
                        planSelect.disabled = true;
                        applyAmount(Number(prices[selectedService] || 0));
                        return;
                    }

                    planSelect.innerHTML = '<option value="">Select plan</option>';
                    plans.forEach((plan) => {
                        const planId = getPlanId(plan);
                        if (!planId) return;
                        const amount = getPlanPrice(plan);
                        const option = document.createElement('option');
                        option.value = planId;
                        option.dataset.amount = String(amount);
                        option.textContent = String(plan.displayName ?? plan.name ?? planId) + (amount > 0 ? ' - ₦' + amount.toLocaleString() : '');
                        planSelect.appendChild(option);
                    });

                    if (planSelect.options.length <= 1) {
                        planSelect.innerHTML = '<option value="">Standard plan</option>';
                        planSelect.disabled = true;
                        applyAmount(Number(prices[selectedService] || 0));
                        return;
                    }

                    planSelect.disabled = false;
                    planSelect.selectedIndex = 1;
                    const selectedAmount = Number(planSelect.options[1]?.dataset?.amount || 0);
                    applyAmount(selectedAmount > 0 ? selectedAmount : Number(prices[selectedService] || 0));
                } catch (e) {
                    planSelect.innerHTML = '<option value="">Plan loading failed, using default</option>';
                    planSelect.disabled = true;
                    applyAmount(Number(prices[selectedService] || 0));
                }
            }

            function getErrorMessage(response, data, fallback) {
                if (data && typeof data.message === 'string' && data.message.trim() !== '') return data.message;
                if (data && data.errors) {
                    const firstKey = Object.keys(data.errors)[0];
                    if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey][0]) return data.errors[firstKey][0];
                }
                return fallback;
            }

            async function submitForm() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                actionBtn.disabled = true;
                if (confirmBtn) confirmBtn.disabled = true;
                if (typeof window.showGlobalLoader === 'function') window.showGlobalLoader('Processing transaction...');

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(form),
                    });

                    let data = {};
                    try { data = await response.json(); } catch (e) {}

                    const ok = data && data.ok === true;
                    const message = ok ? (data.message || 'Purchase successful.') : getErrorMessage(response, data, 'Transaction failed. Please try again.');

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

            actionBtn.addEventListener('click', () => {
                const amount = Number(amountInput.value || prices[selectedService] || 0);
                const normalizedPhone = normalizePhone(phone.value);
                const profileId = (profileInput?.value || '').trim();
                const selectedPlanOption = planSelect.options[planSelect.selectedIndex];

                if (!normalizedPhone || normalizedPhone.length < 10) return notify('error', 'Please enter a valid phone number.');
                if (amount <= 0 && transactionFee <= 0) return notify('error', 'Price is not configured yet for this service.');
                if (selectedService === 'jamb' && !profileId) return notify('error', 'Profile ID is required for JAMB.');

                openConfirmModal('confirmExam', {
                    service: 'Education',
                    network: serviceLabel,
                    customer: normalizedPhone,
                    amount: '₦' + (amount + transactionFee).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                    extra: (selectedPlanOption?.textContent || serviceLabel) + ' | Service charge: ₦' + transactionFee.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                }, 'examPurchaseForm');
            });

            planSelect.addEventListener('change', () => {
                const selected = planSelect.options[planSelect.selectedIndex];
                const selectedAmount = Number(selected?.dataset?.amount || 0);
                applyAmount(selectedAmount > 0 ? selectedAmount : Number(prices[selectedService] || 0));
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitForm();
            });

            loadPlans();
        })();
    </script>
</x-app-layout>
