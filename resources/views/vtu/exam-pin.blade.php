<x-app-layout>
    @php
        $services = is_array($services ?? null) ? $services : [
            'jamb' => 'JAMB PIN (UTME & Direct Entry)',
            'waec' => 'WAEC Result Checker PIN',
            'neco' => 'NECO Result Checker PIN',
            'nabteb' => 'NABTEB Result Checker PIN',
        ];

        $examLogos = [
            'jamb' => '/exams/jamb.png',
            'waec' => '/images/providers/waec.png',
            'neco' => '/images/providers/neco.png',
            'nabteb' => '/images/providers/nabteb.png',
        ];

        $transactionFee = (float) setting('price_exam_transaction_fee', setting('markup_exam', 100));
        $basePrices = [
            'jamb' => (float) setting('price_exam_jamb', 0),
            'waec' => (float) setting('price_exam_waec', 0),
            'neco' => (float) setting('price_exam_neco', 0),
            'nabteb' => (float) setting('price_exam_nabteb', 0),
        ];

        $prices = [];
        foreach ($services as $slug => $label) {
            $prices[$slug] = (float) ($basePrices[$slug] ?? 0);
        }
    @endphp

    <div class="max-w-3xl space-y-5 mx-auto w-full px-4 sm:px-0">
        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">Education Services</h2>
                    <p class="text-gray-600 dark:text-white/60 text-sm mt-1">
                        Choose a service, select plan, enter details, then continue.
                    </p>
                </div>

                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-2xl">
                    ED
                </div>
            </div>
        </div>

        <div class="grid grid-cols-4 gap-2"
             style="display:grid !important;grid-template-columns:repeat(4,minmax(0,1fr)) !important;gap:0.5rem;">
            @foreach($services as $slug => $label)
                <button type="button"
                        class="exam-card group rounded-xl p-2 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 hover:bg-gray-50 dark:hover:bg-white/10 transition relative overflow-hidden"
                        data-service="{{ $slug }}"
                        style="min-width:0;">
                    <span class="pointer-events-none absolute -inset-10 opacity-0 group-hover:opacity-100 transition duration-500 blur-2xl bg-white/10"></span>

                    <div class="w-8 h-8 rounded-lg bg-black/5 dark:bg-white/10 border border-white/10 overflow-hidden flex items-center justify-center mx-auto">
                        <img src="{{ $examLogos[$slug] ?? '' }}"
                             onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=&quot;text-white/70 font-extrabold text-xs&quot;>{{ strtoupper($slug) }}</span>';"
                             alt="{{ $label }}"
                             class="w-full h-full object-cover">
                    </div>

                    <div class="mt-1 text-center font-extrabold text-xs">{{ strtoupper($slug) }}</div>
                    <div class="text-center text-[10px] text-gray-600 dark:text-white/60">{{ $label }}</div>
                </button>
            @endforeach
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <form id="examPurchaseForm" method="POST" action="{{ route('vtu.exam.buy') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-extrabold text-white/80">Service</label>
                        <select id="pin_code" name="pin_code" required
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            <option value="">Choose service</option>
                            @foreach($services as $slug => $label)
                                <option value="{{ $slug }}">{{ strtoupper($slug) }} - {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-extrabold text-white/80">Plan</label>
                        <select id="plan" name="plan"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white">
                            <option value="">Select service first...</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-sm font-extrabold text-white/80">Amount (N)</label>
                        <input id="amountPreview" type="text" readonly
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/10 dark:bg-black/40 border border-gray-200 dark:border-white/10 text-white"
                               placeholder="Select service to see amount">
                        <input id="amount" type="hidden" name="amount">
                    </div>
                </div>

                <div id="profileIdWrap" class="hidden">
                    <label class="text-sm font-extrabold text-white/80">Profile ID (JAMB only)</label>
                    <input id="profile_id" name="profile_id" type="text" placeholder="Enter profile ID"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                    <p class="mt-2 text-xs text-gray-600 dark:text-white/60">
                        For JAMB, send "NIN 12345678901" to 55019 to generate your profile code.
                    </p>
                </div>

                <div>
                    <label class="text-sm font-extrabold text-white/80">Phone Number</label>
                    <input id="phone" type="text" name="phone" required placeholder="e.g. 08012345678"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-black/5 dark:bg-black/30 border border-gray-200 dark:border-white/10 text-white placeholder:text-white/30">
                </div>

                <div class="rounded-2xl border border-amber-500/20 bg-amber-500/10 text-amber-100 p-3 text-sm">
                    Note: A transaction service charge of N{{ number_format($transactionFee, 2) }} applies after you click Continue.
                </div>

                <button type="button" id="examActionBtn"
                        class="w-full px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                    Continue
                </button>
            </form>
        </div>

        <x-confirm-modal id="confirmExam" title="Confirm Education Purchase" confirmText="Confirm & Buy" />
    </div>

    <script>
        (function () {
            const prices = @json($prices);
            const serviceLabels = @json($services);
            const transactionFee = Number(@json($transactionFee));

            const select = document.getElementById('pin_code');
            const planSelect = document.getElementById('plan');
            const phone = document.getElementById('phone');
            const amountPreview = document.getElementById('amountPreview');
            const amountInput = document.getElementById('amount');
            const profileWrap = document.getElementById('profileIdWrap');
            const profileInput = document.getElementById('profile_id');
            const actionBtn = document.getElementById('examActionBtn');
            const form = document.getElementById('examPurchaseForm');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmExam"]');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                } else {
                    alert(message);
                }
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

                amountPreview.value = 'N' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                amountInput.value = amount.toFixed(2);
            }

            async function loadPlans(serviceKey) {
                if (!serviceKey) {
                    planSelect.innerHTML = '<option value="">Select service first...</option>';
                    planSelect.disabled = true;
                    return;
                }

                planSelect.innerHTML = '<option value="">Loading plans...</option>';
                planSelect.disabled = true;

                try {
                    const url = '{{ route('gsubz.plans') }}?service=' + encodeURIComponent(serviceKey);
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await response.json();
                    const plans = Array.isArray(data?.plans) ? data.plans : [];

                    if (plans.length === 0) {
                        planSelect.innerHTML = '<option value="">Standard plan</option>';
                        planSelect.disabled = true;
                        applyAmount(Number(prices[serviceKey] || 0));
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
                        option.textContent = String(plan.displayName ?? plan.name ?? planId) + (amount > 0 ? ' - N' + amount.toLocaleString() : '');
                        planSelect.appendChild(option);
                    });

                    if (planSelect.options.length <= 1) {
                        planSelect.innerHTML = '<option value="">Standard plan</option>';
                        planSelect.disabled = true;
                        applyAmount(Number(prices[serviceKey] || 0));
                        return;
                    }

                    planSelect.disabled = false;
                    planSelect.selectedIndex = 1;
                    const selectedAmount = Number(planSelect.options[1]?.dataset?.amount || 0);
                    applyAmount(selectedAmount > 0 ? selectedAmount : Number(prices[serviceKey] || 0));
                } catch (e) {
                    planSelect.innerHTML = '<option value="">Plan loading failed, using default</option>';
                    planSelect.disabled = true;
                    applyAmount(Number(prices[serviceKey] || 0));
                }
            }

            function updateState() {
                const key = select.value;
                const amount = Number(prices[key] || 0);
                const isJamb = key === 'jamb';

                profileWrap.classList.toggle('hidden', !isJamb);
                profileInput.required = isJamb;

                if (!key) {
                    applyAmount(0);
                    planSelect.innerHTML = '<option value="">Select service first...</option>';
                    planSelect.disabled = true;
                    return;
                }

                applyAmount(amount);
                loadPlans(key);
            }

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
                if (!form || form.dataset.submitting === '1') return;
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
                        updateState();
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

            document.querySelectorAll('.exam-card').forEach((button) => {
                button.addEventListener('click', () => {
                    select.value = button.dataset.service || '';
                    updateState();
                });
            });

            select.addEventListener('change', updateState);

            actionBtn.addEventListener('click', () => {
                const service = select.value;
                const selectedPlanOption = planSelect.options[planSelect.selectedIndex];
                const amount = Number(amountInput.value || prices[service] || 0);
                const normalizedPhone = normalizePhone(phone.value);
                const profileId = profileInput.value.trim();

                if (!service) return notify('error', 'Please select a service.');
                if (!normalizedPhone || normalizedPhone.length < 10) return notify('error', 'Please enter a valid phone number.');
                if (amount <= 0 && transactionFee <= 0) return notify('error', 'Price is not configured yet for this service.');
                if (service === 'jamb' && !profileId) return notify('error', 'Profile ID is required for JAMB.');

                openConfirmModal('confirmExam', {
                    service: 'Education',
                    network: service.toUpperCase(),
                    customer: normalizedPhone,
                    amount: 'N' + (amount + transactionFee).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                    extra: (selectedPlanOption?.textContent || serviceLabels[service] || service) + ' | Service charge: N' + transactionFee.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                }, 'examPurchaseForm');
            });

            planSelect.addEventListener('change', () => {
                const selected = planSelect.options[planSelect.selectedIndex];
                const selectedAmount = Number(selected?.dataset?.amount || 0);
                const service = select.value;
                applyAmount(selectedAmount > 0 ? selectedAmount : Number(prices[service] || 0));
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitForm();
            });

            updateState();
        })();
    </script>
</x-app-layout>
