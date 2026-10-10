<x-app-layout>
    @php
        $priceVerify = identity_price('price_bvn_verify');
        $priceRetrievePhone = identity_price('price_bvn_retrieve_phone');
        $priceRetrieveBms = identity_price('price_bvn_retrieve_bms');
        $markup = (float) setting('markup_bvn', 0);
        $manualServices = app(\App\Services\ManualFulfilmentService::class);
        $retrieveTurnaround = $manualServices->turnaroundLabel('bvn_retrieve');
        $verifyManual = identity_verify_mode('bvn') === 'manual';
        $verifyTurnaround = $manualServices->turnaroundLabel('bvn_verify');
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

    <x-page-hero class="reference-shared-banner" title="BVN Services"
                 subtitle="{{ $verifyManual
                     ? 'Submit a BVN check and our team posts the result to your receipt, or follow an existing retrieval request.'
                     : 'Verify a BVN instantly, submit a retrieval request, and print the result once it succeeds.' }}">
        <a href="{{ route('identity.index') }}" class="reference-hero-action">All identity requests</a>
    </x-page-hero>

    <div class="reference-flow-page max-w-5xl mx-auto w-full px-4 sm:px-0 space-y-5 bvn-print-wrap">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="app-section p-5 sm:p-6">
                <h3 class="text-lg font-extrabold">{{ $verifyManual ? 'BVN Verification' : 'Instant BVN Verification' }}</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Charge: ₦{{ number_format($priceVerify + $markup, 2) }} per verification.
                    @if($verifyManual)
                        A member of our team runs this check and the result appears on your receipt
                        {{ strtolower($verifyTurnaround) }} after payment.
                    @endif
                </p>
                <form id="bvnVerifyForm" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Enter the BVN Number</label>
                        <input type="text" name="bvn" maxlength="11" inputmode="numeric" required
                               class="input-field mt-1"
                               placeholder="Enter BVN">
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">
                        BelovedSubP is not affiliated with NIBSS. By submitting this check you authorise
                        BelovedSubP and the agents it works with to read the record your BVN points to and
                        return your own details to you on this site.
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
                    Lost your BVN? Submit the phone number or the BMS ticket it is linked to and our team
                    runs the retrieval {{ strtolower($retrieveTurnaround) }} after payment.
                </p>
                <form id="bvnRetrieveForm" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-bold text-slate-700">Choose Category</label>
                        <select id="retrieve_type" name="retrieve_type" required
                                class="input-field mt-1">
                            <option value="">--Choose Retrieval Type--</option>
                            <option value="phone">Using Phone Number</option>
                            <option value="bms">Using BMS Ticket</option>
                        </select>
                    </div>
                    <div id="retrieveFields" class="grid grid-cols-1 gap-3"></div>
                    <x-price-lock id="retrievePrice" label="Retrieval price" />
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

        <section class="app-section p-5 sm:p-6 no-print">
            <h3 class="text-lg font-extrabold">BVN Requests</h3>
            <p class="mt-1 text-sm text-slate-500">Every BVN check and retrieval you have submitted, newest first.</p>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="py-2 pr-4 text-left">BVN</th>
                            <th class="py-2 pr-4 text-left">Type</th>
                            <th class="py-2 pr-4 text-left">Status</th>
                            <th class="py-2 pr-4 text-left">Response</th>
                            <th class="py-2 pr-4 text-left">Date</th>
                            <th class="py-2 text-left">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports as $report)
                            @php
                                $meta = is_array($report->meta) ? $report->meta : [];
                                $serviceType = ($meta['service_type'] ?? '') === 'retrieve' ? 'Retrieve' : 'Verify';
                                $providerMessage = (string) ($meta['message'] ?? '');
                            @endphp
                            <tr class="border-b border-slate-100">
                                <td class="py-2 pr-4">{{ $report->customer_ref }}</td>
                                <td class="py-2 pr-4">{{ $serviceType }}</td>
                                <td class="py-2 pr-4">
                                    <span class="rounded-full px-2 py-1 text-xs font-bold {{ $report->status === 'success' ? 'bg-emerald-50 text-emerald-700' : ($report->status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') }}">
                                        {{ strtoupper($report->status) }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4">{{ $providerMessage ?: '-' }}</td>
                                <td class="py-2 pr-4">{{ optional($report->created_at)->format('d M Y, h:ia') }}</td>
                                <td class="py-2">
                                    <a href="{{ route('vtu.receipt', $report->id) }}" class="text-xs font-bold text-blue-700 underline">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-3 text-slate-500">No BVN requests yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        (function () {
            const verifyRoute = @json(route('vtu.bvn.verify'));
            const retrieveRoute = @json(route('vtu.bvn.retrieve'));
            const retrievePrices = {
                phone: Number(@json($priceRetrievePhone)) + Number(@json($markup)),
                bms: Number(@json($priceRetrieveBms)) + Number(@json($markup)),
            };
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

            function fullNameOf(data) {
                const first = data.first_name || data.firstname || data.firs_tname || '';
                const middle = data.middle_name || data.middlename || '';
                const last = data.last_name || data.lastname || '';
                return [first, middle, last].filter(Boolean).join(' ');
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

                nameEl.textContent = fullNameOf(data) || '-';
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
                            <label class="block text-sm font-bold text-slate-700">Enter BMS Ticket</label>
                            <input type="text" name="bms_no" required inputmode="numeric"
                                   class="input-field mt-1"
                                   placeholder="Enter BMS ticket number">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700">Enter Full Ticket ID</label>
                            <input type="text" name="ticket_id" required inputmode="numeric"
                                   class="input-field mt-1"
                                   placeholder="Enter Ticket ID e.g 91465122240202030407">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700">Agent Code (Optional)</label>
                            <input type="text" name="agent_code" inputmode="numeric"
                                   class="input-field mt-1"
                                   placeholder="Enter Agent Code e.g 91033441">
                        </div>
                    `;
                    return;
                }

                retrieveFields.innerHTML = '';
            }

            function renderRetrievePrice() {
                if (retrieveType.value === 'phone') {
                    window.setPriceLock('retrievePrice', retrievePrices.phone,
                        'Charged to your wallet when the request is submitted.');
                    return;
                }

                if (retrieveType.value === 'bms') {
                    window.setPriceLock('retrievePrice', retrievePrices.bms,
                        'Charged to your wallet when the request is submitted.');
                    return;
                }

                window.setPriceLock('retrievePrice', null);
            }

            async function submitForm(form, endpoint, labels) {
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
                    const queued = ok && data?.queued === true;
                    const message = data?.message || (ok ? 'Request completed.' : 'Request failed.');
                    const payload = data?.normalized || data?.data || {};
                    const receiptUrl = data?.receipt_url || '';

                    renderResult(ok ? (queued ? 'pending' : 'success') : 'failed', message, payload);
                    showReceipt(receiptUrl);

                    const balanceKobo = Number(data?.balance_kobo ?? NaN);
                    if (Number.isFinite(balanceKobo) && typeof window.updateWalletBalance === 'function') {
                        window.updateWalletBalance(balanceKobo);
                    }

                    // Naming the customer in the popup is the proof that this is
                    // their record, not just a green tick on a page.
                    const identity = ok && !queued ? fullNameOf(payload) : '';
                    showAppDialog({
                        tone: ok ? (queued ? 'info' : 'success') : 'error',
                        title: ok ? (queued ? 'Request received' : labels.done) : labels.failed,
                        message: [message, identity && ('Record: ' + identity)].filter(Boolean).join(' '),
                        actions: receiptUrl
                            ? [
                                { label: 'View receipt', variant: 'primary', href: receiptUrl },
                                { label: 'Close', variant: 'muted' },
                            ]
                            : [{ label: 'Okay', variant: 'warm' }],
                    });
                } catch (error) {
                    renderResult('failed', 'Network error. Please try again.', {});
                    showAppDialog({ tone: 'error', title: labels.failed, message: 'Network error. Please try again.' });
                } finally {
                    form.dataset.submitting = '0';
                    if (typeof window.hideGlobalLoader === 'function') {
                        window.hideGlobalLoader();
                    }
                }
            }

            verifyForm.addEventListener('submit', function (event) {
                event.preventDefault();
                submitForm(verifyForm, verifyRoute, { done: 'BVN verified', failed: 'Not verified' });
            });

            retrieveForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!retrieveType.value) {
                    showAppDialog({ tone: 'info', title: 'Pick a retrieve type', message: 'Choose which BVN record you want to retrieve.' });
                    return;
                }
                submitForm(retrieveForm, retrieveRoute, { done: 'BVN retrieved', failed: 'Not retrieved' });
            });

            retrieveType.addEventListener('change', function () {
                renderRetrieveFields();
                renderRetrievePrice();
            });
            renderRetrieveFields();
            renderRetrievePrice();
        })();
    </script>
</x-app-layout>
