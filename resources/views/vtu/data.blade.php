<x-app-layout>
    @php
        $netLogos = [
            'mtn_awoof' => '/networks/mtn.png',
            'mtn_gifting' => '/networks/mtn.png',
            'mtn_sme' => '/networks/mtn.png',
            'mtn_cg' => '/networks/mtn.png',
            'mtn_cg_lite' => '/networks/mtn.png',
            'mtn_coupon' => '/networks/mtn.png',
            'mtncg' => '/networks/mtn.png',
            'airtel_sme' => '/networks/Airtel.png',
            'airtel_cg' => '/networks/Airtel.png',
            'airtel_gifting' => '/networks/Airtel.png',
            'glo_data' => '/networks/glo.png',
            'glo_sme' => '/networks/glo.png',
            'etisalat_data' => '/networks/9mobile.png',
        ];

        $markupData = (float) setting('markup_data', 0);
    @endphp

    <div class="mx-auto max-w-4xl space-y-5 sm:space-y-6">
        <section class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="app-kicker">Data Subscription</div>
                <h1 class="app-page-title mt-2 text-[1.7rem] leading-tight sm:text-[2.3rem]">Buy {{ $serviceLabel }}</h1>
            </div>
            <a href="{{ route('vtu.data') }}" class="btn-outline sm:w-auto">All Data Services</a>
        </section>

        <section class="app-form-shell space-y-5 sm:space-y-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <p class="max-w-xl text-sm leading-6 text-slate-500 sm:text-[0.95rem]">Select a plan, enter the phone number and continue with wallet checkout.</p>
                <div class="app-icon-ring shrink-0">
                    <img src="{{ $netLogos[$serviceSlug] ?? asset('images/providers/mtn.png') }}" alt="{{ $serviceLabel }}" class="h-10 w-10 object-contain">
                </div>
            </div>

            <form id="dataPurchaseForm" method="POST" action="{{ route('vtu.data.buy') }}" class="space-y-4 sm:space-y-5">
                @csrf
                <input type="hidden" id="service_id" name="service_id" value="{{ $serviceSlug }}">

                <div>
                    <label class="block text-sm font-bold text-slate-700">Plan</label>
                    <select id="plan" name="plan" required disabled class="input-field mt-2">
                        <option value="">Loading plans...</option>
                    </select>
                    <div class="mt-2 text-xs text-slate-500">Service charge: &#8358;{{ number_format($markupData, 2) }}</div>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                        <div class="contact-picker-row mt-2">
                            <input id="phone" type="tel" name="phone" required placeholder="Enter Phone Number" list="dataPhoneSuggestionList" class="input-field" inputmode="tel" autocomplete="tel-national" data-contact-picker-input>
                            <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#phone" aria-label="Pick phone contact">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                    <path d="M17 21v-8H7v8"></path>
                                    <path d="M7 3v5h8"></path>
                                </svg>
                            </button>
                        </div>
                        @if(!empty($phoneSuggestions ?? []))
                            <datalist id="dataPhoneSuggestionList">
                                @foreach(($phoneSuggestions ?? []) as $suggestion)
                                    <option value="{{ $suggestion['phone'] }}">{{ $suggestion['label'] }}</option>
                                @endforeach
                            </datalist>
                            <div class="mt-3 flex flex-nowrap gap-2 overflow-x-auto pb-1">
                                @foreach(($phoneSuggestions ?? []) as $suggestion)
                                    <button type="button" class="data-phone-suggestion shrink-0 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-phone="{{ $suggestion['phone'] }}">
                                        {{ $suggestion['phone'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700">Amount</label>
                        <input id="amount" type="number" name="amount" required readonly placeholder="Select plan to see amount" class="input-field mt-2 bg-slate-50">
                        <div id="payTotalText" class="mt-2 text-xs text-slate-500">You will pay: <span class="font-extrabold text-slate-900">&#8358;0</span></div>
                    </div>
                </div>

                <div id="planLoader" class="rounded-2xl bg-slate-50 px-4 py-2.5 text-xs font-medium text-slate-600">
                    Loading plans from provider...
                </div>

                <button type="button" id="dataActionBtn" class="btn-primary w-full justify-center py-3.5 text-[0.98rem]">
                    Continue
                </button>
            </form>
        </section>

        <x-confirm-modal id="confirmData" title="Confirm Data Purchase" confirmText="Confirm & Buy Data" />
    </div>

    <script>
        (function () {
            const serviceId = @json($serviceSlug);
            const serviceLabel = @json($serviceLabel);
            const markupData = Number(@json($markupData));

            const planSelect = document.getElementById('plan');
            const phoneInput = document.getElementById('phone');
            const amountInput = document.getElementById('amount');
            const actionBtn = document.getElementById('dataActionBtn');
            const form = document.getElementById('dataPurchaseForm');
            const loader = document.getElementById('planLoader');
            const payTotalText = document.getElementById('payTotalText').querySelector('span');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmData"]');

            document.querySelectorAll('.data-phone-suggestion').forEach(btn => {
                btn.addEventListener('click', () => {
                    phoneInput.value = btn.getAttribute('data-phone') || '';
                    phoneInput.focus();
                });
            });

            function getPlanId(p) {
                return (p.plan_id ?? p.planID ?? p.planId ?? p.value ?? p.code ?? p.id ?? p.plan ?? '').toString();
            }

            function getPlanPrice(p) {
                const raw = (p.price ?? p.amount ?? p.plan_amount ?? p.planAmount ?? p.cost ?? 0);
                const num = Number(raw);
                return Number.isFinite(num) ? num : 0;
            }

            function getPlanLabel(p, planId) {
                const name = p.displayName ?? p.name ?? p.plan_name ?? p.planName ?? p.description ?? p.product ?? p.bundle ?? '';
                const size = p.size ?? p.data ?? p.datavolume ?? p.dataVolume ?? p.volume ?? '';
                const validity = p.validity ?? p.duration ?? p.days ?? p.valid_days ?? p.validDays ?? '';
                const price = getPlanPrice(p);
                let label = (name && String(name).trim()) ? String(name).trim() : `Plan #${planId || 'N/A'}`;
                if (size) label += ` - ${size}`;
                if (validity) label += ` (${validity})`;
                if (price > 0) label += ` - ₦${price.toLocaleString()}`;
                return label;
            }

            async function loadPlans() {
                try {
                    const url = `{{ route('gsubz.plans') }}?service=${encodeURIComponent(serviceId)}`;
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    const plans = data.plans || data.data || data || [];

                    if (!Array.isArray(plans) || plans.length === 0) {
                        planSelect.innerHTML = '<option value="">No plans found</option>';
                        planSelect.disabled = true;
                        return;
                    }

                    planSelect.innerHTML = '<option value="">Select Plan</option>';
                    plans.forEach(p => {
                        const planId = getPlanId(p);
                        if (!planId) return;
                        const opt = document.createElement('option');
                        opt.value = planId;
                        opt.textContent = getPlanLabel(p, planId);
                        opt.dataset.amount = String(getPlanPrice(p));
                        planSelect.appendChild(opt);
                    });
                    planSelect.disabled = false;
                } catch (e) {
                    planSelect.innerHTML = '<option value="">Failed to load plans</option>';
                    planSelect.disabled = true;
                } finally {
                    loader.classList.add('hidden');
                }
            }

            planSelect.addEventListener('change', () => {
                const opt = planSelect.options[planSelect.selectedIndex];
                const base = Number(opt?.dataset?.amount || 0);
                if (!base || base <= 0) {
                    amountInput.value = '';
                    payTotalText.textContent = '₦0';
                    return;
                }
                amountInput.value = base;
                payTotalText.textContent = '₦' + Number(base + markupData).toLocaleString();
            });

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

            async function submitData() {
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
                    try { data = await res.json(); } catch (e) {}

                    const ok = data && data.ok === true;
                    const message = ok ? (data.message || 'Data purchase successful.') : getErrorMessage(res, data, 'Transaction failed. Please try again.');

                    if (typeof window.showTransactionResult === 'function') {
                        window.showTransactionResult({ ok, message, orderId: data?.order_id });
                    } else {
                        notify(ok ? 'success' : 'error', message);
                    }

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }
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
                submitData();
            });

            actionBtn.addEventListener('click', () => {
                const planId = planSelect.value;
                const phone = (phoneInput.value || '').trim();
                const baseAmount = Number(amountInput.value || 0);
                const planLabel = planSelect.options[planSelect.selectedIndex]?.textContent || planId;

                if (!planId) return notify('error', 'Please select a plan.');
                if (!phone || phone.length < 8) return notify('error', 'Please enter a valid phone number.');
                if (!baseAmount || baseAmount <= 0) return notify('error', 'Amount is not set. Please select a valid plan.');

                openConfirmModal('confirmData', {
                    service: 'Data',
                    network: serviceLabel,
                    customer: phone,
                    amount: '₦' + Number(baseAmount + markupData).toLocaleString(),
                    extra: planLabel,
                }, 'dataPurchaseForm');
            });

            loadPlans();
        })();
    </script>
</x-app-layout>
