<x-app-layout>
    @php
        $netNames = [
            'mtn' => 'MTN',
            'airtel' => 'Airtel',
            'glo' => 'Glo',
            'etisalat' => '9mobile',
        ];

        $netLogos = [
            'mtn' => '/networks/mtn.png',
            'airtel' => '/networks/Airtel.png',
            'glo' => '/networks/glo.png',
            'etisalat' => '/networks/9mobile.png',
        ];
    @endphp

    <div class="mx-auto max-w-4xl space-y-8">
        <section>
            <h1 class="app-page-title text-[2.1rem] sm:text-[2.6rem]">Buy Airtime</h1>
            <div class="app-divider mt-4"></div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($netNames as $key => $label)
                <button type="button" class="net-card app-service-card text-left" data-net="{{ $key }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="app-service-title">{{ $label }} Airtime</div>
                            <div class="mt-2 text-sm text-slate-500">Quick recharge for {{ $label }} numbers</div>
                        </div>
                        <div class="app-icon-ring h-16 w-16">
                            <img src="{{ $netLogos[$key] }}" alt="{{ $label }}" class="h-10 w-10 object-contain" onerror="this.style.display='none';">
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        <section class="app-form-shell">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-3xl font-extrabold text-slate-900">Airtime Purchase</div>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Select a network, enter the phone number and choose the amount you want to send.</p>
                </div>
                <div class="app-icon-ring">
                    <svg viewBox="0 0 24 24" class="h-9 w-9 text-slate-700" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 7h14"></path>
                        <path d="M8 4h8"></path>
                        <rect x="4" y="7" width="16" height="13" rx="2"></rect>
                    </svg>
                </div>
            </div>

            <form id="airtimePurchaseForm" method="POST" action="{{ route('vtu.airtime.buy') }}" class="mt-8 space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-bold text-slate-700">Network</label>
                    <select id="network" name="service_id" required class="input-field mt-2">
                        <option value="">Select Network</option>
                        @foreach($netNames as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                    <div class="contact-picker-row mt-2">
                        <input id="phone" type="tel" name="phone" required placeholder="Enter Phone Number" list="airtimePhoneSuggestionList" class="input-field" inputmode="tel" autocomplete="tel-national" data-contact-picker-input>
                        <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#phone" aria-label="Pick phone contact">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                <path d="M17 21v-8H7v8"></path>
                                <path d="M7 3v5h8"></path>
                            </svg>
                        </button>
                    </div>
                    @if(!empty($phoneSuggestions ?? []))
                        <datalist id="airtimePhoneSuggestionList">
                            @foreach(($phoneSuggestions ?? []) as $suggestion)
                                <option value="{{ $suggestion['phone'] }}">{{ $suggestion['label'] }}</option>
                            @endforeach
                        </datalist>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach(($phoneSuggestions ?? []) as $suggestion)
                                <button type="button"
                                        class="airtime-phone-suggestion rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                        data-phone="{{ $suggestion['phone'] }}">
                                    {{ $suggestion['phone'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-3 flex items-center gap-2">
                        <div id="airtimePhoneBadge" class="hidden items-center gap-2 rounded-2xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <div class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full bg-white">
                                <img id="airtimePhoneBadgeImg" src="" alt="Detected" class="hidden h-full w-full object-cover">
                                <span id="airtimePhoneBadgeTxt" class="hidden text-xs font-bold text-slate-700"></span>
                            </div>
                            <div>Phone detected: <span id="airtimePhoneBadgeName" class="font-extrabold text-slate-900">--</span></div>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700">Amount</label>
                    <input id="amount" type="number" name="amount" required min="50" step="1" placeholder="e.g. 100" class="input-field mt-2">
                </div>

                <button type="button" id="airtimeActionBtn" class="btn-primary w-full justify-center py-4 text-base">
                    Continue
                </button>
            </form>
        </section>

        <div id="confirmAirtime_overlay" data-wallet-kobo="{{ (int) (auth()->user()?->wallet->balance ?? 0) }}" class="fixed inset-0 z-[2147483647] hidden items-center justify-center p-4" style="isolation:isolate;">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-lg overflow-hidden rounded-[30px] border border-slate-200 bg-white shadow-[0_22px_55px_rgba(18,31,56,0.24)]">
                <div class="p-6 sm:p-7">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-2xl font-extrabold text-slate-900">Confirm Airtime Purchase</div>
                            <div class="mt-1 text-sm text-slate-600">Please review the details before proceeding.</div>
                        </div>
                        <button type="button" id="confirmAirtime_close" class="flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-700 transition hover:bg-slate-100">&times;</button>
                    </div>
                    <div class="mt-4 space-y-0" id="confirmAirtime_rows"></div>
                    <div id="confirmAirtime_balance" class="mt-3 hidden rounded-[20px] border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap text-[11px] text-slate-500 sm:text-xs">
                            <span class="shrink-0 font-bold uppercase tracking-[0.18em] text-slate-400">Wallet</span>
                            <span class="shrink-0 rounded-full bg-white px-2 py-1">Bal <span id="confirmAirtime_currentBalance" class="font-bold text-slate-900">&#8358;0.00</span></span>
                            <span class="shrink-0 rounded-full bg-white px-2 py-1">Debit <span id="confirmAirtime_deductBalance" class="font-bold text-slate-900">&#8358;0.00</span></span>
                            <span class="shrink-0 rounded-full bg-white px-2 py-1">Left <span id="confirmAirtime_remainingBalance" class="font-bold text-slate-900">&#8358;0.00</span></span>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <button type="button" id="confirmAirtime_cancel" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-extrabold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                        <button type="button" id="confirmAirtime_ok" class="rounded-2xl bg-[#d8b07a] px-4 py-3 font-extrabold text-slate-900 transition hover:bg-[#c99c60]">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const netNames = @json($netNames);
            const netLogos = @json($netLogos);

            const networkSelect = document.getElementById('network');
            const phoneInput = document.getElementById('phone');
            const amountInput = document.getElementById('amount');
            const actionBtn = document.getElementById('airtimeActionBtn');
            const form = document.getElementById('airtimePurchaseForm');

            const phoneBadge = document.getElementById('airtimePhoneBadge');
            const phoneBadgeName = document.getElementById('airtimePhoneBadgeName');
            const phoneBadgeImg = document.getElementById('airtimePhoneBadgeImg');
            const phoneBadgeTxt = document.getElementById('airtimePhoneBadgeTxt');

            const overlay = document.getElementById('confirmAirtime_overlay');
            const rowsBox = document.getElementById('confirmAirtime_rows');
            const balanceWrap = document.getElementById('confirmAirtime_balance');
            const currentBalanceEl = document.getElementById('confirmAirtime_currentBalance');
            const deductBalanceEl = document.getElementById('confirmAirtime_deductBalance');
            const remainingBalanceEl = document.getElementById('confirmAirtime_remainingBalance');
            const btnClose = document.getElementById('confirmAirtime_close');
            const btnCancel = document.getElementById('confirmAirtime_cancel');
            const btnOk = document.getElementById('confirmAirtime_ok');
            let manualNetwork = false;

            function esc(s) {
                return String(s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

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

            function showNetworkBadge(netKey) {
                if (!netKey || !netNames[netKey]) {
                    phoneBadge.classList.add('hidden');
                    phoneBadge.classList.remove('flex');
                    return;
                }

                phoneBadge.classList.remove('hidden');
                phoneBadge.classList.add('flex');
                phoneBadgeName.textContent = netNames[netKey];

                const logo = netLogos[netKey] || '';
                if (logo) {
                    phoneBadgeImg.src = logo;
                    phoneBadgeImg.classList.remove('hidden');
                    phoneBadgeTxt.classList.add('hidden');
                } else {
                    phoneBadgeImg.classList.add('hidden');
                    phoneBadgeTxt.textContent = netNames[netKey];
                    phoneBadgeTxt.classList.remove('hidden');
                }
            }

            function showModal(summary) {
                rowsBox.innerHTML = Object.entries(summary).map(([k, v]) => `
                    <div class="flex items-center justify-between gap-3 rounded-none border border-slate-200 bg-slate-50 px-4 py-1.5 first:rounded-t-2xl last:rounded-b-2xl">
                        <div class="text-sm text-slate-500">${esc(k)}</div>
                        <div class="text-right text-sm font-extrabold text-slate-900">${esc(v)}</div>
                    </div>
                `).join('');

                const walletBalance = Number(document.getElementById('walletBalance')?.dataset?.walletKobo || overlay?.dataset?.walletKobo || 0);
                const debitKobo = Math.round(Number(amountInput.value || 0) * 100);

                if (Number.isFinite(debitKobo) && balanceWrap && currentBalanceEl && deductBalanceEl && remainingBalanceEl) {
                    const remaining = walletBalance - debitKobo;
                    currentBalanceEl.textContent = '\u20A6' + (walletBalance / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    deductBalanceEl.textContent = '\u20A6' + (debitKobo / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    remainingBalanceEl.textContent = '\u20A6' + (remaining / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    remainingBalanceEl.className = 'font-bold ' + (remaining < 0 ? 'text-rose-700' : 'text-slate-900');
                    balanceWrap.classList.remove('hidden');
                } else if (balanceWrap) {
                    balanceWrap.classList.add('hidden');
                }

                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }

            function hideModal() {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }

            btnClose.addEventListener('click', hideModal);
            btnCancel.addEventListener('click', hideModal);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) hideModal(); });

            document.querySelectorAll('.net-card').forEach(btn => {
                btn.addEventListener('click', () => {
                    const key = btn.getAttribute('data-net');
                    networkSelect.value = key;
                    manualNetwork = true;
                    document.querySelectorAll('.net-card').forEach(card => {
                        card.setAttribute('aria-pressed', card.getAttribute('data-net') === key ? 'true' : 'false');
                    });
                });
            });

            document.querySelectorAll('.airtime-phone-suggestion').forEach(btn => {
                btn.addEventListener('click', () => {
                    phoneInput.value = btn.getAttribute('data-phone') || '';
                    phoneInput.focus();
                    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });

            networkSelect.addEventListener('change', () => {
                manualNetwork = !!networkSelect.value;
            });

            phoneInput.addEventListener('input', () => {
                const netKey = detectNetworkFromPhone(phoneInput.value);
                showNetworkBadge(netKey);
                if (netKey && !manualNetwork) {
                    networkSelect.value = netKey;
                }
            });

            actionBtn.addEventListener('click', () => {
                const netKey = networkSelect.value;
                const phone = (phoneInput.value || '').trim();
                const amount = amountInput.value;

                if (!netKey) return notify('error', 'Please select a network.');
                if (!phone || phone.length < 8) return notify('error', 'Please enter a valid phone number.');
                if (!amount || Number(amount) < 50) return notify('error', 'Please enter a valid amount (min \u20A650).');

                showModal({
                    service: 'Airtime',
                    network: netNames[netKey] ?? netKey,
                    phone: phone,
                    amount: '\u20A6' + Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                });
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

            async function submitAirtime() {
                if (!form || form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                actionBtn.disabled = true;
                btnOk.disabled = true;

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
                    const message = ok ? (data.message || 'Airtime purchase successful.') : getErrorMessage(res, data, 'Transaction failed. Please try again.');

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
                        manualNetwork = false;
                        showNetworkBadge(null);
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
                    btnOk.disabled = false;
                }
            }

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                submitAirtime();
            });

            btnOk.addEventListener('click', () => {
                hideModal();
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        })();
    </script>
</x-app-layout>

