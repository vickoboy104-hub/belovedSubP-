<x-app-layout>
    @php
        $priceVerify = identity_price('price_bvn_verify');
        $priceRetrievePhone = identity_price('price_bvn_retrieve_phone');
        $priceRetrieveBms = identity_price('price_bvn_retrieve_bms');
        $markup = (float) setting('markup_bvn', 0);
        $retrieveTurnaround = app(\App\Services\ManualFulfilmentService::class)->turnaroundLabel('bvn_retrieve');
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

    <x-page-hero class="reference-shared-banner" title="BVN Services" subtitle="Verify a BVN instantly, submit a retrieval request, and print the result once it succeeds." />

    <div class="reference-flow-page max-w-5xl mx-auto w-full px-4 sm:px-0 space-y-5 bvn-print-wrap">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="app-section p-5 sm:p-6">
                <h3 class="text-lg font-extrabold">Instant BVN Verification</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Charge: N{{ number_format($priceVerify + $markup, 2) }} per verification.
                </p>
                <form id="bvnVerifyForm" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Enter BVN</label>
                        <input type="text" name="bvn" maxlength="11" required
                               class="input-field mt-1"
                               placeholder="11-digit BVN">
                    </div>
                    <button type="submit"
                            class="btn-primary w-full justify-center">
                        Verify / Check BVN Details
                    </button>
                </form>
            </div>

            <div class="app-section p-5 sm:p-6">
                <h3 class="text-lg font-extrabold">BVN Retrieval Workflow</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Phone retrieval: N{{ number_format($priceRetrievePhone + $markup, 2) }} | BMS retrieval: N{{ number_format($priceRetrieveBms + $markup, 2) }}.
                    Retrieval is completed by our team {{ strtolower($retrieveTurnaround) }} after payment.
                </p>
                <form id="bvnRetrieveForm" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Retrieve Type</label>
                        <select id="retrieve_type" name="retrieve_type" required
                                class="input-field mt-1">
                            <option value="">Choose type</option>
                            <option value="phone">Using Phone Number</option>
                            <option value="bms">Using BMS Ticket</option>
                        </select>
                    </div>
                    <div id="retrieveFields" class="grid grid-cols-1 gap-3"></div>
                    <button type="submit"
                            class="btn-primary w-full justify-center">
                        Submit Retrieve Request
                    </button>
                </form>
            </div>
        </div>

        <div id="bvnResultCard" class="app-section hidden p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-extrabold">BVN Result</h3>
                    <p id="bvnResultMessage" class="mt-1 text-sm text-slate-600"></p>
                </div>
                <div class="flex items-center gap-2 no-print">
                    <span id="bvnStatusBadge" class="px-3 py-1 rounded-full text-xs font-semibold border"></span>
                    <button type="button" onclick="window.print()" class="reference-quiet-button">
                        Print / Save PDF
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
                <div class="sm:col-span-1">
                    <img id="bvnFaceImage" class="hidden w-40 h-40 object-cover rounded-2xl border border-slate-200" alt="BVN photo">
                </div>
                <div class="sm:col-span-2">
                    <div class="text-2xl font-black" id="bvnName">-</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-sm">
                        <span class="app-choice-chip">BVN: <span id="bvnNumber">-</span></span>
                        <span class="app-choice-chip">NIN: <span id="bvnNin">-</span></span>
                    </div>
                </div>
            </div>

            <div id="bvnDetails" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3"></div>

            <p id="bvnReceiptLink" class="hidden mt-4">
                <a id="bvnReceiptAnchor" href="#"
                   class="btn-outline gap-2">
                    Open receipt and track this request
                </a>
            </p>
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

            function setStatus(state) {
                const looks = {
                    success: ['SUCCESS', 'bg-emerald-50 text-emerald-800 border-emerald-200'],
                    // A retrieval can be accepted and still have no answer yet,
                    // and the wallet has already been charged for it. Saying
                    // FAILED there would be a lie the customer could act on.
                    pending: ['IN PROGRESS', 'bg-amber-50 text-amber-800 border-amber-200'],
                    failed: ['FAILED', 'bg-rose-50 text-rose-800 border-rose-200'],
                };
                const [label, tone] = looks[state] || looks.failed;

                statusBadge.textContent = label;
                statusBadge.className = 'px-3 py-1 rounded-full text-xs font-semibold border ' + tone;
            }

            function detailsRow(label, value) {
                return `
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="app-record-label">${esc(label)}</div>
                        <div class="app-record-value break-all">${esc(val(value))}</div>
                    </div>
                `;
            }

            function clearResultFields() {
                nameEl.textContent = '-';
                bvnNoEl.textContent = '-';
                ninEl.textContent = '-';
                detailsWrap.innerHTML = '';
                faceImg.src = '';
                faceImg.classList.add('hidden');
            }

            function showReceipt(url) {
                const anchor = document.getElementById('bvnReceiptAnchor');
                const wrapper = document.getElementById('bvnReceiptLink');

                // Only ever a link this app generated, never whatever a provider said.
                if (typeof url === 'string' && (url.startsWith('/') || url.startsWith(window.location.origin))) {
                    anchor.href = url;
                    wrapper.classList.remove('hidden');
                    return;
                }

                wrapper.classList.add('hidden');
                anchor.removeAttribute('href');
            }

            function renderResult(state, message, data) {
                resultCard.classList.remove('hidden');
                setStatus(state);
                resultMessage.textContent = message || (state === 'success'
                    ? 'Request completed.'
                    : (state === 'pending' ? 'Request received.' : 'Request failed.'));

                if (state !== 'success') {
                    clearResultFields();
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
                            <label class="block text-sm font-bold text-slate-700">Phone Number</label>
                            <div class="contact-picker-row mt-1">
                                <input type="tel" name="phone" required inputmode="tel" autocomplete="tel-national" data-contact-picker-input
                                       class="input-field w-full"
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
                            <label class="block text-sm font-bold text-slate-700">BMS Ticket</label>
                            <input type="text" name="bms_no" required
                                   class="input-field mt-1"
                                   placeholder="Enter BMS ticket">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700">Ticket ID</label>
                            <input type="text" name="ticket_id" required
                                   class="input-field mt-1"
                                   placeholder="Enter full ticket ID">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700">Agent Code (Optional)</label>
                            <input type="text" name="agent_code"
                                   class="input-field mt-1"
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
                    const message = data?.message || (ok ? 'Request completed.' : 'Request failed.');

                    renderResult(ok ? (data?.queued ? 'pending' : 'success') : 'failed',
                        message,
                        data?.normalized || data?.data || {});
                    showReceipt(data?.receipt_url);
                    notify(ok ? 'success' : 'error', message);

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }
                } catch (error) {
                    renderResult('failed', 'Network error. Please try again.', {});
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
