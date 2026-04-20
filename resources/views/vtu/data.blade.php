<x-app-layout>
    @php
        $netLogos = [
            'mtn' => '/networks/mtn.png',
            'airtel' => '/networks/Airtel.png',
            'glo' => '/networks/glo.png',
            'etisalat' => '/networks/9mobile.png',
        ];

        $serviceToNetwork = [
            'mtn_sme' => 'mtn',
            'mtn_cg' => 'mtn',
            'mtn_cg_lite' => 'mtn',
            'mtn_gifting' => 'mtn',
            'mtn_coupon' => 'mtn',
            'mtncg' => 'mtn',
            'mtn_awoof' => 'mtn',
            'airtel_sme' => 'airtel',
            'airtel_cg' => 'airtel',
            'airtel_gifting' => 'airtel',
            'glo_data' => 'glo',
            'glo_sme' => 'glo',
            'etisalat_data' => 'etisalat',
        ];

        $netNames = [
            'mtn' => 'MTN',
            'airtel' => 'Airtel',
            'glo' => 'Glo',
            'etisalat' => '9mobile',
        ];

        $allowedMtnDefaults = ['mtn_awoof', 'mtn_gifting', 'mtn_sme'];
        $defaultMtnService = (string) setting('data_default_mtn_service', 'mtn_gifting');
        if (!in_array($defaultMtnService, $allowedMtnDefaults, true)) {
            $defaultMtnService = 'mtn_gifting';
        }

        $allowedAirtelDefaults = ['airtel_sme', 'airtel_cg', 'airtel_gifting'];
        $defaultAirtelService = (string) setting('data_default_airtel_service', 'airtel_sme');
        if (!in_array($defaultAirtelService, $allowedAirtelDefaults, true)) {
            $defaultAirtelService = 'airtel_sme';
        }

        $allowedGloDefaults = ['glo_data', 'glo_sme'];
        $defaultGloService = (string) setting('data_default_glo_service', 'glo_data');
        if (!in_array($defaultGloService, $allowedGloDefaults, true)) {
            $defaultGloService = 'glo_data';
        }

        $networkDefaultService = [
            'mtn' => $defaultMtnService,
            'airtel' => $defaultAirtelService,
            'glo' => $defaultGloService,
            'etisalat' => 'etisalat_data',
        ];

        $markupData = (float) setting('markup_data', 0);
    @endphp

    <div class="mx-auto max-w-5xl space-y-8">
        <section>
            <h1 class="app-page-title text-[2.1rem] sm:text-[2.6rem]">Buy Data Subscription</h1>
            <div class="app-divider mt-4"></div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($netNames as $key => $label)
                <button type="button"
                        class="network-pick app-service-card text-left"
                        data-network="{{ $key }}"
                        data-service="{{ $networkDefaultService[$key] ?? '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xl font-extrabold text-slate-900">{{ $label }} Data</div>
                            <div class="mt-2 text-sm text-slate-500">Tap to load available bundles</div>
                        </div>
                        <div class="app-icon-ring h-16 w-16">
                            <img src="{{ $netLogos[$key] ?? '' }}"
                                 alt="{{ $label }}"
                                 class="h-10 w-10 object-contain"
                                 onerror="this.style.display='none';">
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        <section class="app-form-shell">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-3xl font-extrabold text-slate-900">Data Purchase</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Complete the form below to continue. Plans are loaded directly from your provider integration.</p>
                </div>
                <div class="app-icon-ring">
                    <img src="{{ asset('images/providers/mtn.png') }}" alt="Network" class="h-10 w-10 object-contain">
                </div>
            </div>

            <form id="dataPurchaseForm" method="POST" action="{{ route('vtu.data.buy') }}" class="mt-8 space-y-5">
                @csrf

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Service</label>
                        <select id="service_id" name="service_id" required class="input-field mt-2">
                            <option value="">Select Service</option>
                            @foreach(($services ?? []) as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        <div class="mt-3 flex items-center gap-2">
                            <div id="serviceBadge" class="hidden items-center gap-2 rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                <div class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full bg-white">
                                    <img id="serviceBadgeImg" src="" alt="Network" class="hidden h-full w-full object-cover">
                                    <span id="serviceBadgeTxt" class="hidden text-xs font-bold text-slate-700"></span>
                                </div>
                                <div>Network: <span id="serviceBadgeName" class="font-extrabold text-slate-900">--</span></div>
                            </div>
                        </div>

                        <div class="mt-2 text-xs text-slate-500">When you pick a service, we load the plans automatically.</div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700">Plan</label>
                        <select id="plan" name="plan" required disabled class="input-field mt-2">
                            <option value="">Select service first...</option>
                        </select>
                        <div class="mt-2 text-xs text-slate-500">Service charge: &#8358;{{ number_format($markupData, 2) }}</div>
                    </div>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                        <input id="phone" type="text" name="phone" required placeholder="Enter Phone Number" list="dataPhoneSuggestionList" class="input-field mt-2">
                        @if(!empty($phoneSuggestions ?? []))
                            <datalist id="dataPhoneSuggestionList">
                                @foreach(($phoneSuggestions ?? []) as $suggestion)
                                    <option value="{{ $suggestion['phone'] }}">{{ $suggestion['label'] }}</option>
                                @endforeach
                            </datalist>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach(($phoneSuggestions ?? []) as $suggestion)
                                    <button type="button"
                                            class="data-phone-suggestion rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                            data-phone="{{ $suggestion['phone'] }}">
                                        {{ $suggestion['phone'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-3 flex items-center gap-2">
                            <div id="phoneBadge" class="hidden items-center gap-2 rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                <div class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full bg-white">
                                    <img id="phoneBadgeImg" src="" alt="Detected" class="hidden h-full w-full object-cover">
                                    <span id="phoneBadgeTxt" class="hidden text-xs font-bold text-slate-700"></span>
                                </div>
                                <div>Phone detected: <span id="phoneBadgeName" class="font-extrabold text-slate-900">--</span></div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700">Amount</label>
                        <input id="amount" type="number" name="amount" required readonly placeholder="Select plan to see amount" class="input-field mt-2 bg-slate-50">
                        <div id="payTotalText" class="mt-2 text-xs text-slate-500">You will pay: <span class="font-extrabold text-slate-900">&#8358;0</span></div>
                    </div>
                </div>

                <div id="planLoader" class="hidden rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Loading plans from provider...
                </div>

                <button type="button" id="dataActionBtn" class="btn-primary w-full justify-center py-4 text-base">
                    Continue
                </button>
            </form>
        </section>

        <x-confirm-modal id="confirmData" title="Confirm Data Purchase" confirmText="Confirm & Buy Data" />
    </div>

    <script>
        (function () {
            const serviceToNetwork = @json($serviceToNetwork);
            const netNames = @json($netNames);
            const netLogos = @json($netLogos);
            const markupData = Number(@json($markupData));

            const serviceSelect = document.getElementById('service_id');
            const planSelect = document.getElementById('plan');
            const phoneInput = document.getElementById('phone');
            const amountInput = document.getElementById('amount');
            const actionBtn = document.getElementById('dataActionBtn');
            const form = document.getElementById('dataPurchaseForm');
            const loader = document.getElementById('planLoader');
            const payTotalText = document.getElementById('payTotalText').querySelector('span');
            const confirmBtn = document.querySelector('[data-modal-confirm="confirmData"]');

            const sBadge = document.getElementById('serviceBadge');
            const sBadgeName = document.getElementById('serviceBadgeName');
            const sBadgeImg = document.getElementById('serviceBadgeImg');
            const sBadgeTxt = document.getElementById('serviceBadgeTxt');

            const pBadge = document.getElementById('phoneBadge');
            const pBadgeName = document.getElementById('phoneBadgeName');
            const pBadgeImg = document.getElementById('phoneBadgeImg');
            const pBadgeTxt = document.getElementById('phoneBadgeTxt');

            const allServiceOptions = Array.from(serviceSelect.options)
                .filter(opt => opt.value !== '')
                .map(opt => ({ value: opt.value, label: opt.textContent || opt.value }));

            function resetPlanSelection(placeholder = 'Select service first...') {
                planSelect.innerHTML = `<option value="">${placeholder}</option>`;
                planSelect.disabled = true;
                amountInput.value = '';
                payTotalText.textContent = '₦0';
            }

            function getServicesForNetwork(networkKey) {
                if (!networkKey) return allServiceOptions;
                return allServiceOptions.filter(opt => (serviceToNetwork[opt.value] || null) === networkKey);
            }

            function renderServiceOptions(networkKey, preferredServiceId = '', autoSelect = true) {
                const options = getServicesForNetwork(networkKey);
                const placeholder = networkKey && netNames[networkKey]
                    ? `Select ${netNames[networkKey]} Service`
                    : 'Select Service';

                serviceSelect.innerHTML = `<option value="">${placeholder}</option>`;
                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.label;
                    serviceSelect.appendChild(option);
                });

                document.querySelectorAll('.network-pick').forEach((btn) => {
                    btn.setAttribute('aria-pressed', btn.getAttribute('data-network') === networkKey ? 'true' : 'false');
                });

                if (!autoSelect) {
                    serviceSelect.value = '';
                    showNetworkBadge(null, sBadge, sBadgeName, sBadgeImg, sBadgeTxt);
                    resetPlanSelection();
                    return;
                }

                if (options.length === 0) {
                    serviceSelect.value = '';
                    showNetworkBadge(networkKey, sBadge, sBadgeName, sBadgeImg, sBadgeTxt);
                    resetPlanSelection('No services available for this network');
                    return;
                }

                const preferredExists = preferredServiceId && options.some(opt => opt.value === preferredServiceId);
                serviceSelect.value = preferredExists ? preferredServiceId : options[0].value;
                serviceSelect.dispatchEvent(new Event('change'));
            }

            document.querySelectorAll('.network-pick').forEach(btn => {
                btn.addEventListener('click', () => {
                    const networkKey = btn.getAttribute('data-network');
                    const preferredServiceId = btn.getAttribute('data-service') || '';
                    if (!networkKey) return;
                    renderServiceOptions(networkKey, preferredServiceId, true);
                });
            });

            document.querySelectorAll('.data-phone-suggestion').forEach(btn => {
                btn.addEventListener('click', () => {
                    phoneInput.value = btn.getAttribute('data-phone') || '';
                    phoneInput.focus();
                    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });

            function normalizePhone(raw) {
                let p = (raw || '').toString().trim();
                p = p.replace(/\s+/g, '').replace(/[^0-9+]/g, '');
                if (p.startsWith('+234')) p = '0' + p.slice(4);
                if (p.startsWith('234')) p = '0' + p.slice(3);
                return p;
            }

            function detectNetworkFromPhone(phone) {
                const p = normalizePhone(phone);
                if (p.length < 4) return null;
                const prefix4 = p.slice(0, 4);
                const MTN = new Set(['0803','0806','0703','0706','0813','0816','0810','0814','0903','0906','0913','0916']);
                const AIRTEL = new Set(['0802','0808','0708','0701','0812','0902','0901','0904','0907','0912','0911']);
                const GLO = new Set(['0805','0807','0705','0811','0905','0915']);
                const ETISALAT = new Set(['0809','0817','0818','0909','0908']);
                if (MTN.has(prefix4)) return 'mtn';
                if (AIRTEL.has(prefix4)) return 'airtel';
                if (GLO.has(prefix4)) return 'glo';
                if (ETISALAT.has(prefix4)) return 'etisalat';
                return null;
            }

            function showNetworkBadge(netKey, badgeEl, nameEl, imgEl, txtEl) {
                if (!netKey || !netNames[netKey]) {
                    badgeEl.classList.add('hidden');
                    badgeEl.classList.remove('flex');
                    return;
                }

                badgeEl.classList.remove('hidden');
                badgeEl.classList.add('flex');
                nameEl.textContent = netNames[netKey];

                const logo = netLogos[netKey] || '';
                if (logo) {
                    imgEl.src = logo;
                    imgEl.classList.remove('hidden');
                    txtEl.classList.add('hidden');
                } else {
                    imgEl.classList.add('hidden');
                    txtEl.textContent = netNames[netKey];
                    txtEl.classList.remove('hidden');
                }
            }

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

            async function loadPlans(serviceId) {
                planSelect.innerHTML = '<option value="">Loading plans...</option>';
                planSelect.disabled = true;
                amountInput.value = '';
                payTotalText.textContent = '₦0';
                loader.classList.remove('hidden');

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
                        const price = getPlanPrice(p);
                        const label = getPlanLabel(p, planId);
                        const opt = document.createElement('option');
                        opt.value = planId;
                        opt.textContent = label;
                        opt.dataset.amount = String(price);
                        planSelect.appendChild(opt);
                    });

                    if (planSelect.options.length <= 1) {
                        planSelect.innerHTML = '<option value="">Plans loaded but missing plan IDs</option>';
                        planSelect.disabled = true;
                        return;
                    }

                    planSelect.disabled = false;
                } catch (e) {
                    planSelect.innerHTML = '<option value="">Failed to load plans</option>';
                    planSelect.disabled = true;
                } finally {
                    loader.classList.add('hidden');
                }
            }

            serviceSelect.addEventListener('change', () => {
                const serviceId = serviceSelect.value;
                if (!serviceId) {
                    showNetworkBadge(null, sBadge, sBadgeName, sBadgeImg, sBadgeTxt);
                    resetPlanSelection();
                    return;
                }

                const netKey = serviceToNetwork[serviceId] || null;
                showNetworkBadge(netKey, sBadge, sBadgeName, sBadgeImg, sBadgeTxt);
                loadPlans(serviceId);
            });

            planSelect.addEventListener('change', () => {
                const opt = planSelect.options[planSelect.selectedIndex];
                const base = Number(opt?.dataset?.amount || 0);
                if (!base || base <= 0) {
                    amountInput.value = '';
                    payTotalText.textContent = '₦0';
                    return;
                }
                amountInput.value = base;
                const totalPay = base + markupData;
                payTotalText.textContent = '₦' + Number(totalPay).toLocaleString();
            });

            phoneInput.addEventListener('input', () => {
                const netKey = detectNetworkFromPhone(phoneInput.value);
                showNetworkBadge(netKey, pBadge, pBadgeName, pBadgeImg, pBadgeTxt);
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
                    if (firstKey && Array.isArray(data.errors[firstKey]) && data.errors[firstKey][0]) {
                        return data.errors[firstKey][0];
                    }
                }
                return fallback;
            }

            async function submitData() {
                if (!form || form.dataset.submitting === '1') return;
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
                    try {
                        data = await res.json();
                    } catch (e) {}

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

                    if (ok) {
                        form.reset();
                        renderServiceOptions(null, '', false);
                        sBadge.classList.add('hidden');
                        sBadge.classList.remove('flex');
                        pBadge.classList.add('hidden');
                        pBadge.classList.remove('flex');
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
                const serviceId = serviceSelect.value;
                const planId = planSelect.value;
                const phone = normalizePhone(phoneInput.value);
                const baseAmount = Number(amountInput.value || 0);

                if (!serviceId) return notify('error', 'Please select a service.');
                if (!planId) return notify('error', 'Please select a plan.');
                if (!phone || phone.length < 8) return notify('error', 'Please enter a valid phone number.');
                if (!baseAmount || baseAmount <= 0) return notify('error', 'Amount is not set. Please select a valid plan.');

                const serviceLabel = serviceSelect.options[serviceSelect.selectedIndex]?.textContent || serviceId;
                const planLabel = planSelect.options[planSelect.selectedIndex]?.textContent || planId;
                const netKey = serviceToNetwork[serviceId] || detectNetworkFromPhone(phone) || null;
                const networkLabel = netKey ? (netNames[netKey] ?? netKey) : 'Auto';
                const totalPay = baseAmount + markupData;

                openConfirmModal('confirmData', {
                    service: 'Data',
                    network: networkLabel,
                    customer: phone,
                    amount: '₦' + Number(totalPay).toLocaleString(),
                    extra: `${serviceLabel} | ${planLabel}`,
                }, 'dataPurchaseForm');
            });
        })();
    </script>
</x-app-layout>
