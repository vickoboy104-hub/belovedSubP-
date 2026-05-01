<x-app-layout>
    @php
        $priceVerify = (float) setting('price_bvn_verify', 100);
        $priceRetrievePhone = (float) setting('price_bvn_retrieve_phone', 2500);
        $priceRetrieveBms = (float) setting('price_bvn_retrieve_bms', 1000);
        $markup = (float) setting('markup_bvn', 0);
    @endphp

    <style>
        @media print {
            header, aside, nav, .no-print { display: none !important; }
            body, .bvn-print-wrap {
                background: #fff !important;
                color: #000 !important;
            }
        }
    </style>

    <div class="legacy-themed-page max-w-5xl mx-auto w-full px-4 sm:px-0 space-y-5 bvn-print-wrap">
        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">&#128274; BVN Services</h2>
                    <p class="text-sm text-gray-600 dark:text-white/60 mt-1">
                        Verify BVN instantly, submit retrieve workflow, and print your result after success.
                    </p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-xl">
                    &#129534;
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
                <h3 class="text-lg font-extrabold">Instant BVN Verification</h3>
                <p class="text-xs text-gray-600 dark:text-white/60 mt-1">
                    Charge: N{{ number_format($priceVerify + $markup, 2) }} per verification.
                </p>
                <form id="bvnVerifyForm" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">Enter BVN</label>
                        <input type="text" name="bvn" maxlength="11" required
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                               placeholder="11-digit BVN">
                    </div>
                    <button type="submit"
                            class="w-full px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                        Verify / Check BVN Details
                    </button>
                </form>
            </div>

            <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
                <h3 class="text-lg font-extrabold">BVN Retrieval Workflow</h3>
                <p class="text-xs text-gray-600 dark:text-white/60 mt-1">
                    Phone retrieval: N{{ number_format($priceRetrievePhone + $markup, 2) }} | BMS retrieval: N{{ number_format($priceRetrieveBms + $markup, 2) }}
                </p>
                <form id="bvnRetrieveForm" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">Retrieve Type</label>
                        <select id="retrieve_type" name="retrieve_type" required
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                            <option value="">Choose type</option>
                            <option value="phone">Using Phone Number</option>
                            <option value="bms">Using BMS Ticket</option>
                        </select>
                    </div>
                    <div id="retrieveFields" class="grid grid-cols-1 gap-3"></div>
                    <button type="submit"
                            class="w-full px-4 py-3 rounded-2xl bg-blue-700 hover:bg-blue-800 text-white font-extrabold transition">
                        Submit Retrieve Request
                    </button>
                </form>
            </div>
        </div>

        <div id="bvnResultCard" class="hidden rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-extrabold">BVN Result</h3>
                    <p id="bvnResultMessage" class="text-sm mt-1 text-gray-600 dark:text-white/60"></p>
                </div>
                <div class="flex items-center gap-2 no-print">
                    <span id="bvnStatusBadge" class="px-3 py-1 rounded-full text-xs font-semibold border"></span>
                    <button type="button" onclick="window.print()"
                            class="px-3 py-2 rounded-xl bg-black/10 dark:bg-white/10 hover:bg-black/15 dark:hover:bg-white/15 border border-black/10 dark:border-white/10 text-xs font-bold">
                        Print / Save PDF
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
                <div class="sm:col-span-1">
                    <img id="bvnFaceImage" class="hidden w-40 h-40 object-cover rounded-2xl border border-gray-200 dark:border-white/10" alt="BVN photo">
                </div>
                <div class="sm:col-span-2">
                    <div class="text-2xl font-black" id="bvnName">-</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm">
                        <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-900 border border-blue-200">BVN: <span id="bvnNumber">-</span></span>
                        <span class="px-3 py-1 rounded-full bg-green-50 text-green-900 border border-green-200">NIN: <span id="bvnNin">-</span></span>
                    </div>
                </div>
            </div>

            <div id="bvnDetails" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
        </div>
    </div>

    <script>
        (function () {
            const verifyRoute = @json(route('vtu.bvn.verify'));
            const retrieveRoute = @json(route('vtu.bvn.retrieve'));
            const verifyForm = document.getElementById('bvnVerifyForm');
            const retrieveForm = document.getElementById('bvnRetrieveForm');
            const retrieveType = document.getElementById('retrieve_type');
            const retrieveFields = document.getElementById('retrieveFields');
            const resultCard = document.getElementById('bvnResultCard');
            const resultMessage = document.getElementById('bvnResultMessage');
            const statusBadge = document.getElementById('bvnStatusBadge');
            const nameEl = document.getElementById('bvnName');
            const bvnNoEl = document.getElementById('bvnNumber');
            const ninEl = document.getElementById('bvnNin');
            const detailsWrap = document.getElementById('bvnDetails');
            const faceImg = document.getElementById('bvnFaceImage');

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                    return;
                }
                alert(message);
            }

            function esc(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function val(value) {
                const text = String(value ?? '').trim();
                return text === '' ? '-' : text;
            }

            function imageSrc(raw) {
                const text = String(raw ?? '').trim().replace(/\s+/g, '');
                if (!text) return '';
                if (text.startsWith('data:image/')) return text;
                if (text.startsWith('/9j/')) return `data:image/jpeg;base64,${text}`;
                if (text.startsWith('iVBOR')) return `data:image/png;base64,${text}`;
                return '';
            }

            function setStatus(ok) {
                statusBadge.textContent = ok ? 'SUCCESS' : 'FAILED';
                statusBadge.className = ok
                    ? 'px-3 py-1 rounded-full text-xs font-semibold border bg-green-50 text-green-800 border-green-200'
                    : 'px-3 py-1 rounded-full text-xs font-semibold border bg-red-50 text-red-800 border-red-200';
            }

            function detailsRow(label, value) {
                return `
                    <div class="rounded-2xl p-3 border border-gray-200 dark:border-white/10">
                        <div class="text-xs text-gray-500 dark:text-white/50 uppercase">${esc(label)}</div>
                        <div class="font-semibold break-all">${esc(val(value))}</div>
                    </div>
                `;
            }

            function renderResult(ok, message, data) {
                resultCard.classList.remove('hidden');
                setStatus(ok);
                resultMessage.textContent = message || (ok ? 'Request completed.' : 'Request failed.');

                if (!ok) {
                    nameEl.textContent = '-';
                    bvnNoEl.textContent = '-';
                    ninEl.textContent = '-';
                    detailsWrap.innerHTML = '';
                    faceImg.src = '';
                    faceImg.classList.add('hidden');
                    return;
                }

                const first = data.first_name || data.firstname || data.firs_tname || '';
                const middle = data.middle_name || data.middlename || '';
                const last = data.last_name || data.lastname || '';
                nameEl.textContent = [first, middle, last].filter(Boolean).join(' ') || '-';
                bvnNoEl.textContent = val(data.bvn || data.BVN);
                ninEl.textContent = val(data.nin || data.NIN);

                const photo = imageSrc(data.image || data.photo || '');
                if (photo) {
                    faceImg.src = photo;
                    faceImg.classList.remove('hidden');
                } else {
                    faceImg.src = '';
                    faceImg.classList.add('hidden');
                }

                detailsWrap.innerHTML = [
                    detailsRow('Phone Number', data.phone_number || data.phone),
                    detailsRow('Alternate Number', data.alt_number),
                    detailsRow('Email', data.email),
                    detailsRow('Gender', data.gender),
                    detailsRow('Date of Birth', data.date_of_birth || data.birthdate || data.dob),
                    detailsRow('State', data.State || data.state),
                    detailsRow('LGA', data.lga),
                    detailsRow('Residence', data.residence || data.address),
                ].join('');
            }

            function renderRetrieveFields() {
                if (retrieveType.value === 'phone') {
                    retrieveFields.innerHTML = `
                        <div>
                            <label class="text-sm font-bold text-gray-700 dark:text-white/80">Phone Number</label>
                            <div class="contact-picker-row mt-1">
                                <input type="tel" name="phone" required inputmode="tel" autocomplete="tel-national" data-contact-picker-input
                                       class="w-full px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                                       placeholder="Phone registered with BVN">
                                <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#bvnRetrieveForm input[name='phone']" aria-label="Pick phone contact">
                                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                        <path d="M17 21v-8H7v8"></path>
                                        <path d="M7 3v5h8"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    `;
                    if (typeof window.initContactPickerButtons === 'function') {
                        window.initContactPickerButtons(retrieveFields);
                    }
                    return;
                }

                if (retrieveType.value === 'bms') {
                    retrieveFields.innerHTML = `
                        <div>
                            <label class="text-sm font-bold text-gray-700 dark:text-white/80">BMS Ticket</label>
                            <input type="text" name="bms_no" required
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                                   placeholder="Enter BMS ticket">
                        </div>
                        <div>
                            <label class="text-sm font-bold text-gray-700 dark:text-white/80">Ticket ID</label>
                            <input type="text" name="ticket_id" required
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                                   placeholder="Enter full ticket ID">
                        </div>
                        <div>
                            <label class="text-sm font-bold text-gray-700 dark:text-white/80">Agent Code (Optional)</label>
                            <input type="text" name="agent_code"
                                   class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                                   placeholder="Agent code">
                        </div>
                    `;
                    if (typeof window.initContactPickerButtons === 'function') {
                        window.initContactPickerButtons(retrieveFields);
                    }
                    return;
                }

                retrieveFields.innerHTML = '';
                if (typeof window.initContactPickerButtons === 'function') {
                    window.initContactPickerButtons(retrieveFields);
                }
            }

            async function submitForm(form, endpoint) {
                if (form.dataset.submitting === '1') return;
                form.dataset.submitting = '1';
                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Processing request...');
                }

                try {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: new FormData(form),
                    });
                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data?.ok === true;
                    renderResult(ok, data?.message || 'Request completed.', data?.normalized || data?.data || {});
                    notify(ok ? 'success' : 'error', data?.message || 'Request failed.');

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }
                } catch (error) {
                    renderResult(false, 'Network error. Please try again.', {});
                    notify('error', 'Network error. Please try again.');
                } finally {
                    form.dataset.submitting = '0';
                    if (typeof window.hideGlobalLoader === 'function') {
                        window.hideGlobalLoader();
                    }
                }
            }

            verifyForm.addEventListener('submit', function (event) {
                event.preventDefault();
                submitForm(verifyForm, verifyRoute);
            });

            retrieveForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!retrieveType.value) {
                    notify('error', 'Please choose retrieve type.');
                    return;
                }
                submitForm(retrieveForm, retrieveRoute);
            });

            retrieveType.addEventListener('change', renderRetrieveFields);
            renderRetrieveFields();
        })();
    </script>
</x-app-layout>
