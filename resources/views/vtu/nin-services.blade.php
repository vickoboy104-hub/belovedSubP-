<x-app-layout>
    @php
        $verifyPrice = identity_price('price_nin_verify');
        $printMarkup = (float) setting('markup_nin_print', 0);
        $slipPrices = [
            'standard_slip' => identity_price('price_nin_slip_standard'),
            'premium_slip' => identity_price('price_nin_slip_premium'),
            'long_slip' => identity_price('price_nin_slip_long'),
        ];

        // The slip is drawn by this site from the record it stores, so printing
        // never depends on the provider having a print endpoint. Only the
        // provider's own download report list does.
        $ninProvider = app(\App\Services\NinApi::class);
        $slipReportsAvailable = $ninProvider->supportsSlipReports();

        // A verification can be answered by the provider on the spot, or taken
        // off it and worked from the manual queue. The owner switches between
        // the two in Admin > Settings; the wording here must follow that choice.
        $manualServices = app(\App\Services\ManualFulfilmentService::class);
        $verifyManual = identity_verify_mode('nin') === 'manual';
        $verifyTurnaround = $manualServices->turnaroundLabel('nin_verify');
        $heroSubtitle = $verifyManual
            ? 'Send us the record you need checked and our team posts the result to your receipt.'
            : 'Verify the record once, then print a Standard, Premium or Long slip from the result.';
    @endphp

    <style>
        .nin-slip-action[disabled] {
            opacity: .55;
            cursor: not-allowed;
        }
    </style>

    <x-page-hero class="reference-shared-banner" title="Verify NIN" subtitle="{{ $heroSubtitle }}" />

    <div class="reference-flow-page mx-auto w-full max-w-5xl space-y-5 px-4 sm:px-0">
        <div class="app-section p-5 sm:p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-extrabold text-slate-900">Identity record search</div>
                    <p class="mt-1 text-sm text-slate-600">
                        @if($verifyManual)
                            Search by NIN, phone number, or demographic data. Our team runs the check and the result
                            appears on your receipt {{ strtolower($verifyTurnaround) }} after payment.
                        @else
                            Search by NIN, phone number, or demographic data. A successful result unlocks direct slip printing below.
                        @endif
                    </p>
                </div>
                <div id="verifyPriceBadge" class="app-choice-chip">
                    Verification fee
                </div>
            </div>

            <form id="ninServiceForm" class="space-y-4" method="POST">
                @csrf

                <div>
                    <label class="text-sm font-bold text-slate-700">Verification Type</label>
                    <select id="verification_type"
                            name="verification_type"
                            class="input-field mt-1 w-full">
                        <option value="by_nin">By NIN</option>
                        <option value="by_phone">By Phone Number</option>
                        <option value="by_demo">By Demographic Data</option>
                    </select>
                </div>

                <div id="verificationFields" class="grid grid-cols-1 gap-4 sm:grid-cols-2"></div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="button"
                            id="resetFormBtn"
                            class="btn-outline w-full sm:w-auto justify-center">
                        Reset
                    </button>
                    <button type="button"
                            id="ninSubmitBtn"
                            class="btn-primary w-full sm:flex-1 justify-center">
                        Verify NIN Record
                    </button>
                </div>
            </form>
        </div>

        <div id="ninResultCard" class="app-section hidden p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 id="ninResultTitle" class="text-xl font-extrabold text-slate-900">Verified NIN Result</h3>
                    <p id="ninResultMessage" class="mt-1 text-sm text-slate-600"></p>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-400">
                        <span id="ninResultSourceHint"></span>
                        <button type="button"
                                id="ninRefreshLiveBtn"
                                class="hidden font-medium text-slate-400 transition hover:text-slate-600">
                            refresh live
                        </button>
                    </div>
                </div>
                <span id="ninStatusBadge" class="rounded-full border px-3 py-1 text-xs font-semibold"></span>
            </div>

            <p id="ninQueuedReceipt" class="mt-3 hidden">
                <a id="ninQueuedReceiptAnchor" href="#" class="btn-outline gap-2">
                    Open receipt and track this request
                </a>
            </p>

            <div id="profileSummaryWrap" class="mt-4 hidden rounded-2xl border border-slate-200 p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[160px,1fr]">
                    <div class="flex items-center justify-center sm:justify-start">
                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                            <img id="ninFaceImage" alt="NIN Photo" class="hidden h-40 w-36 object-cover">
                            <div id="ninFaceFallback" class="flex h-40 w-36 items-center justify-center text-xs font-bold uppercase tracking-[0.2em] text-slate-400">
                                No Photo
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="text-2xl font-black text-slate-900" id="ninFullName">-</div>
                        <div class="mt-2 flex flex-wrap gap-2 text-sm">
                            <span class="app-choice-chip">
                                NIN: <span id="ninNumberText">-</span>
                            </span>
                            <span class="app-choice-chip">
                                Tracking: <span id="ninTrackingText">-</span>
                            </span>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="app-record-label">Date of Birth</div>
                                <div id="ninBirthText" class="app-record-value">-</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="app-record-label">Gender</div>
                                <div id="ninGenderText" class="app-record-value">-</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="app-record-label">Phone</div>
                                <div id="ninPhoneText" class="app-record-value">-</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="app-record-label">Address</div>
                                <div id="ninAddressText" class="app-record-value">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="directPrintWrap" class="mt-5 hidden rounded-3xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-extrabold text-slate-900">Direct Slip Print</div>
                        <p id="directPrintNote" class="mt-1 text-sm text-slate-600">
                            Verify a record first to unlock direct printing.
                        </p>
                    </div>
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">
                        Print or Save as PDF
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <button type="button"
                            class="nin-slip-action rounded-2xl border border-slate-300 bg-white px-4 py-4 text-left transition hover:bg-slate-50"
                            data-slip-type="standard_slip"
                            disabled>
                        <div class="text-base font-extrabold text-slate-900">Standard Slip</div>
                        <div class="mt-1 text-sm text-slate-600">Classic printable NIN card layout.</div>
                        <div class="amount-fit mt-3 text-base font-extrabold text-slate-900">
                            {!! '&#8358;' . number_format($slipPrices['standard_slip'], 2) !!}
                        </div>
                    </button>

                    <button type="button"
                            class="nin-slip-action rounded-2xl border border-slate-300 bg-white px-4 py-4 text-left transition hover:bg-slate-50"
                            data-slip-type="premium_slip"
                            disabled>
                        <div class="text-base font-extrabold text-slate-900">Premium Slip</div>
                        <div class="mt-1 text-sm text-slate-600">Digital green card style with issue date.</div>
                        <div class="amount-fit mt-3 text-base font-extrabold text-slate-900">
                            {!! '&#8358;' . number_format($slipPrices['premium_slip'], 2) !!}
                        </div>
                    </button>

                    <button type="button"
                            class="nin-slip-action rounded-2xl border border-slate-300 bg-white px-4 py-4 text-left transition hover:bg-slate-50"
                            data-slip-type="long_slip"
                            disabled>
                        <div class="text-base font-extrabold text-slate-900">Long Slip</div>
                        <div class="mt-1 text-sm text-slate-600">Landscape NIMS table slip printout.</div>
                        <div class="amount-fit mt-3 text-base font-extrabold text-slate-900">
                            {!! '&#8358;' . number_format($slipPrices['long_slip'], 2) !!}
                        </div>
                    </button>
                </div>
            </div>

            <div id="ninDetailsWrap" class="mt-4 space-y-4"></div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-semibold text-slate-700">Show Raw Provider Fields</summary>
                <div id="ninRawTableWrap" class="mt-3 overflow-x-auto"></div>
            </details>
        </div>

        @if($slipReportsAvailable)
        <div class="app-section p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-extrabold text-slate-900">NIN Slip Reports</h3>
                <button type="button"
                        id="refreshReportsBtn"
                        class="btn-primary px-3 py-2 text-sm">
                    Refresh
                </button>
            </div>
            <p id="reportsMessage" class="mt-2 text-xs text-slate-600">
                Provider download reports appear here when available.
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="py-2 pr-4 text-left">NIN</th>
                            <th class="py-2 pr-4 text-left">Slip Type</th>
                            <th class="py-2 pr-4 text-left">Date</th>
                            <th class="py-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody id="slipReportsBody">
                        <tr>
                            <td colspan="4" class="py-3 text-slate-500">No records loaded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    <form id="ninVerifyBridgeForm" class="hidden"></form>
    <form id="ninPrintBridgeForm" class="hidden"></form>

    <x-confirm-modal id="confirmNinVerify" title="Confirm NIN Verification" confirmText="Verify & Debit Wallet" />
    <x-confirm-modal id="confirmNinPrint" title="Confirm NIN Slip Print" confirmText="Print & Debit Wallet" />

    <script>
        (function () {
            const routes = {
                verify: @json(route('vtu.nin.search')),
                print: @json(route('vtu.nin.print')),
                reports: @json(route('vtu.nin.reports')),
            };

            const slipIdleNote = 'Verify a record first to unlock direct printing.';
            const slipReadyNote = (nin) => `Verified NIN ${nin} is ready. Choose Standard, Premium, or Long Slip to print directly.`;

            const verifyPrice = @json($verifyPrice);
            const printMarkup = Number(@json($printMarkup));
            const slipPrices = @json($slipPrices);

            const form = document.getElementById('ninServiceForm');
            const verifyBridgeForm = document.getElementById('ninVerifyBridgeForm');
            const printBridgeForm = document.getElementById('ninPrintBridgeForm');
            const verificationType = document.getElementById('verification_type');
            const verificationFields = document.getElementById('verificationFields');
            const verifyPriceBadge = document.getElementById('verifyPriceBadge');
            const submitBtn = document.getElementById('ninSubmitBtn');
            const resetFormBtn = document.getElementById('resetFormBtn');
            const refreshReportsBtn = document.getElementById('refreshReportsBtn');

            const resultCard = document.getElementById('ninResultCard');
            const resultTitle = document.getElementById('ninResultTitle');
            const statusBadge = document.getElementById('ninStatusBadge');
            const resultMessage = document.getElementById('ninResultMessage');
            const resultSourceHint = document.getElementById('ninResultSourceHint');
            const refreshLiveBtn = document.getElementById('ninRefreshLiveBtn');
            const profileSummaryWrap = document.getElementById('profileSummaryWrap');
            const ninFaceImage = document.getElementById('ninFaceImage');
            const ninFaceFallback = document.getElementById('ninFaceFallback');
            const ninFullName = document.getElementById('ninFullName');
            const ninNumberText = document.getElementById('ninNumberText');
            const ninTrackingText = document.getElementById('ninTrackingText');
            const ninBirthText = document.getElementById('ninBirthText');
            const ninGenderText = document.getElementById('ninGenderText');
            const ninPhoneText = document.getElementById('ninPhoneText');
            const ninAddressText = document.getElementById('ninAddressText');
            const ninDetailsWrap = document.getElementById('ninDetailsWrap');
            const ninRawTableWrap = document.getElementById('ninRawTableWrap');
            const directPrintWrap = document.getElementById('directPrintWrap');
            const directPrintNote = document.getElementById('directPrintNote');
            const directPrintButtons = Array.from(document.querySelectorAll('[data-slip-type]'));
            const slipReportsBody = document.getElementById('slipReportsBody');
            const reportsMessage = document.getElementById('reportsMessage');
            const queuedReceiptWrap = document.getElementById('ninQueuedReceipt');
            const queuedReceiptAnchor = document.getElementById('ninQueuedReceiptAnchor');

            let verifiedState = null;
            let pendingVerifyLookup = null;
            let pendingSlipType = null;

            function notify(type, message) {
                if (typeof window.showFlashToast === 'function') {
                    window.showFlashToast(type, message);
                    return;
                }
                alert(message);
            }

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function digitsOnly(value) {
                return String(value ?? '').replace(/\D/g, '');
            }

            function normalizeValue(value) {
                if (value === null || value === undefined) return '-';
                const text = String(value).trim();
                return text === '' ? '-' : text;
            }

            function formatNaira(amount) {
                return '\u20A6' + Number(amount || 0).toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }

            function formatSlipPrice(amount) {
                return formatNaira(amount).replace('.00', '');
            }

            function formatNin(value) {
                const digits = digitsOnly(value);
                if (digits.length === 11) {
                    return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7, 11)}`;
                }
                if (digits.length > 0) {
                    return digits.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
                }
                return normalizeValue(value);
            }

            function formatDisplayDate(value) {
                const text = String(value ?? '').trim();
                if (!text || text === '-') return '-';

                const isoMatch = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
                if (isoMatch) {
                    const date = new Date(`${isoMatch[1]}-${isoMatch[2]}-${isoMatch[3]}T00:00:00`);
                    if (!Number.isNaN(date.getTime())) {
                        return date.toLocaleDateString('en-GB', {
                            day: '2-digit',
                            month: 'short',
                            year: 'numeric',
                        }).toUpperCase();
                    }
                }

                const dmyMatch = text.match(/^(\d{2})[-/](\d{2})[-/](\d{4})$/);
                if (dmyMatch) {
                    const date = new Date(`${dmyMatch[3]}-${dmyMatch[2]}-${dmyMatch[1]}T00:00:00`);
                    if (!Number.isNaN(date.getTime())) {
                        return date.toLocaleDateString('en-GB', {
                            day: '2-digit',
                            month: 'short',
                            year: 'numeric',
                        }).toUpperCase();
                    }
                }

                const guess = new Date(text);
                if (!Number.isNaN(guess.getTime())) {
                    return guess.toLocaleDateString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                    }).toUpperCase();
                }

                return text.toUpperCase();
            }

            function looksLikeBase64(text) {
                if (!text || typeof text !== 'string') return false;
                if (text.startsWith('data:image/')) return true;
                if (text.startsWith('/9j/') || text.startsWith('iVBOR') || text.startsWith('R0lGOD')) return true;
                return text.length > 800 && /^[A-Za-z0-9+/=\r\n]+$/.test(text);
            }

            function toImageSrc(raw) {
                if (!raw || typeof raw !== 'string') return '';
                const clean = raw.replace(/\s+/g, '');
                if (clean.startsWith('data:image/')) return clean;
                if (clean.startsWith('http://') || clean.startsWith('https://')) return clean;
                if (clean.startsWith('/9j/')) return `data:image/jpeg;base64,${clean}`;
                if (clean.startsWith('iVBOR')) return `data:image/png;base64,${clean}`;
                if (clean.startsWith('R0lGOD')) return `data:image/gif;base64,${clean}`;
                if (looksLikeBase64(clean)) return `data:image/jpeg;base64,${clean}`;
                return '';
            }

            function inputBlock(label, name, placeholder, type = 'text', span2 = false) {
                return `
                    <div class="${span2 ? 'sm:col-span-2' : ''}">
                        <label class="text-sm font-bold text-slate-700">${escapeHtml(label)}</label>
                        <input type="${escapeHtml(type)}"
                               name="${escapeHtml(name)}"
                               placeholder="${escapeHtml(placeholder)}"
                               class="input-field mt-1 w-full">
                    </div>
                `;
            }

            function phoneInputBlock(label, name, placeholder, span2 = false) {
                const safeName = escapeHtml(name);

                return `
                    <div class="${span2 ? 'sm:col-span-2' : ''}">
                        <label class="text-sm font-bold text-slate-700">${escapeHtml(label)}</label>
                        <div class="contact-picker-row mt-1">
                            <input type="tel"
                                   name="${safeName}"
                                   inputmode="tel"
                                   autocomplete="tel-national"
                                   data-contact-picker-input
                                   placeholder="${escapeHtml(placeholder)}"
                                   class="input-field w-full">
                            <button type="button" class="contact-picker-btn" data-contact-picker-button data-contact-picker-target="#ninServiceForm input[name='${safeName}']" aria-label="Pick phone contact">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path>
                                    <path d="M17 21v-8H7v8"></path>
                                    <path d="M7 3v5h8"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                `;
            }

            function sectionCard(title, rows) {
                const rowHtml = rows.map((row) => `
                    <div class="border-b border-slate-100 py-2">
                        <div class="text-xs uppercase tracking-wide text-slate-500">${escapeHtml(row.label)}</div>
                        <div class="break-all text-sm font-medium text-slate-900">${escapeHtml(normalizeValue(row.value))}</div>
                    </div>
                `).join('');

                return `
                    <div class="rounded-2xl border border-slate-200 p-4">
                        <div class="mb-2 font-bold text-slate-900">${escapeHtml(title)}</div>
                        ${rowHtml}
                    </div>
                `;
            }

            function combineAddress(normalized) {
                const parts = [
                    normalized?.address_line_1,
                    normalized?.residence_town,
                    normalized?.residence_lga,
                    normalized?.residence_state,
                ].map((value) => normalizeValue(value)).filter((value) => value !== '-');

                return parts.length > 0 ? parts.join(', ') : '-';
            }

            function renderVerificationFields() {
                const mode = verificationType.value;
                if (mode === 'by_nin') {
                    verificationFields.innerHTML = inputBlock('Enter NIN Number', 'nin', '11-digit NIN', 'text', true);
                    if (typeof window.initContactPickerButtons === 'function') {
                        window.initContactPickerButtons(verificationFields);
                    }
                    return;
                }

                if (mode === 'by_phone') {
                    verificationFields.innerHTML = phoneInputBlock('Enter Phone Number', 'phone', 'Phone linked to NIN', true);
                    if (typeof window.initContactPickerButtons === 'function') {
                        window.initContactPickerButtons(verificationFields);
                    }
                    return;
                }

                verificationFields.innerHTML = `
                    ${inputBlock('First Name', 'firstname', 'Enter first name')}
                    ${inputBlock('Last Name', 'lastname', 'Enter last name')}
                    ${inputBlock('Date of Birth (dd-mm-yyyy)', 'dob', '16-02-1994')}
                    <div>
                        <label class="text-sm font-bold text-slate-700">Gender</label>
                        <select name="gender"
                                class="input-field mt-1 w-full">
                            <option value="">Select gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="m">M</option>
                            <option value="f">F</option>
                        </select>
                    </div>
                `;

                if (typeof window.initContactPickerButtons === 'function') {
                    window.initContactPickerButtons(verificationFields);
                }
            }

            function renderVerifyPrice() {
                verifyPriceBadge.textContent = `Verification fee: ${formatSlipPrice(verifyPrice)}`;
            }

            function lookupPayloadFromFormData(formData) {
                const mode = String(formData.get('verification_type') || formData.get('search_type') || 'by_nin');
                if (mode === 'by_phone') {
                    return {
                        verification_type: 'by_phone',
                        phone: String(formData.get('phone') || '').trim(),
                    };
                }

                if (mode === 'by_demo') {
                    return {
                        verification_type: 'by_demo',
                        firstname: String(formData.get('firstname') || '').trim(),
                        lastname: String(formData.get('lastname') || '').trim(),
                        dob: String(formData.get('dob') || '').trim(),
                        gender: String(formData.get('gender') || '').trim(),
                    };
                }

                return {
                    verification_type: 'by_nin',
                    nin: String(formData.get('nin') || '').trim(),
                };
            }

            function createLookupFormData(lookup, forceRefresh = false) {
                const formData = new FormData();
                const csrfToken = form.querySelector('input[name="_token"]')?.value || '';

                if (csrfToken) {
                    formData.append('_token', csrfToken);
                }

                formData.append('verification_type', lookup?.verification_type || 'by_nin');

                if ((lookup?.verification_type || 'by_nin') === 'by_phone') {
                    formData.append('phone', lookup?.phone || '');
                } else if (lookup?.verification_type === 'by_demo') {
                    formData.append('firstname', lookup?.firstname || '');
                    formData.append('lastname', lookup?.lastname || '');
                    formData.append('dob', lookup?.dob || '');
                    formData.append('gender', lookup?.gender || '');
                } else {
                    formData.append('nin', lookup?.nin || '');
                }

                if (forceRefresh) {
                    formData.append('force_refresh', '1');
                }

                return formData;
            }

            function buildVerifyConfirmationData(lookup) {
                const mode = lookup?.verification_type || 'by_nin';
                const rows = {
                    service: 'NIN Verification',
                    mode: mode === 'by_phone' ? 'By Phone Number' : (mode === 'by_demo' ? 'By Demographic Data' : 'By NIN'),
                    amount: formatNaira(verifyPrice),
                    __debitAmount: verifyPrice,
                };

                if (mode === 'by_phone') {
                    rows.phone = lookup?.phone || '-';
                } else if (mode === 'by_demo') {
                    rows.name = [lookup?.firstname || '', lookup?.lastname || ''].filter(Boolean).join(' ') || '-';
                    rows.dob = lookup?.dob || '-';
                    rows.gender = lookup?.gender || '-';
                } else {
                    rows.nin = lookup?.nin || '-';
                }

                return rows;
            }

            function buildPrintConfirmationData(slipType) {
                const normalized = verifiedState?.normalized || {};
                const slipLabelMap = {
                    standard_slip: 'Standard Slip',
                    premium_slip: 'Premium Slip',
                    long_slip: 'Long Slip',
                };
                const amount = Number(slipPrices?.[slipType] || 0) + printMarkup;

                return {
                    service: 'NIN Slip Print',
                    slip: slipLabelMap[slipType] || slipType,
                    nin: formatNin(normalized?.nin || '-'),
                    amount: formatNaira(amount),
                    __debitAmount: amount,
                };
            }

            function setResultStatus(state) {
                const looks = {
                    verified: ['VERIFIED', 'rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800'],
                    // A queued verification has been paid for but has no answer
                    // yet, so calling it FAILED would send the customer away
                    // while their money is already spent.
                    pending: ['IN PROGRESS', 'rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800'],
                    failed: ['FAILED', 'rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-800'],
                };
                const [label, tone] = looks[state] || looks.failed;

                resultCard.classList.remove('hidden');
                statusBadge.textContent = label;
                statusBadge.className = tone;
            }

            function showQueuedReceipt(url) {
                // Only ever a link this app generated, never whatever a provider said.
                const isInternal = typeof url === 'string'
                    && (url.startsWith('/') || url.startsWith(window.location.origin));

                if (!isInternal) {
                    queuedReceiptWrap.classList.add('hidden');
                    queuedReceiptAnchor.removeAttribute('href');

                    return;
                }

                queuedReceiptAnchor.href = url;
                queuedReceiptWrap.classList.remove('hidden');
            }

            function clearVerifiedState() {
                verifiedState = null;
                directPrintWrap.classList.add('hidden');
                directPrintNote.textContent = slipIdleNote;
                resultSourceHint.textContent = '';
                refreshLiveBtn.classList.add('hidden');
                refreshLiveBtn.disabled = false;
                directPrintButtons.forEach((button) => {
                    button.disabled = true;
                });
            }

            function enableDirectPrint(orderId, normalized, payload, meta = {}) {
                verifiedState = {
                    orderId,
                    normalized: normalized || {},
                    payload: payload || {},
                    lookup: meta.lookup || null,
                    cacheHit: meta.cacheHit === true,
                };

                directPrintWrap.classList.remove('hidden');
                directPrintNote.textContent = slipReadyNote(formatNin(normalized?.nin));
                directPrintButtons.forEach((button) => {
                    button.disabled = false;
                });

                // The verification is only half the job the customer paid for, so
                // bring the slip choices under their eyes instead of leaving them
                // to find the panel below the record.
                window.setTimeout(() => {
                    directPrintWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 450);
            }

            function renderRawTable(data) {
                const skipKeys = new Set(['photo', 'image', 'signature']);
                const entries = Object.entries(data || {}).filter(([key]) => !skipKeys.has(String(key).toLowerCase()));

                if (entries.length === 0) {
                    ninRawTableWrap.innerHTML = '<div class="text-sm text-slate-500">No extra fields returned.</div>';
                    return;
                }

                const rows = entries.map(([key, value]) => `
                    <tr class="border-b border-slate-100">
                        <td class="py-2 pr-4 text-xs uppercase tracking-wide text-slate-500">${escapeHtml(key)}</td>
                        <td class="break-all py-2 text-sm text-slate-900">${escapeHtml(normalizeValue(value))}</td>
                    </tr>
                `).join('');

                ninRawTableWrap.innerHTML = `<table class="w-full text-left"><tbody>${rows}</tbody></table>`;
            }

            function renderVerifyResult(ok, message, payload, normalized, orderId, meta = {}) {
                if (meta.queued) {
                    setResultStatus('pending');
                    resultTitle.textContent = 'Verification in progress';
                    resultMessage.textContent = message || 'Request received.';
                    resultSourceHint.textContent = 'Your result will appear on the receipt page and in your notifications.';
                    refreshLiveBtn.classList.add('hidden');
                    profileSummaryWrap.classList.add('hidden');
                    ninDetailsWrap.innerHTML = '';
                    ninRawTableWrap.innerHTML = '';
                    clearVerifiedState();
                    showQueuedReceipt(meta.receiptUrl);

                    return;
                }

                showQueuedReceipt('');
                resultTitle.textContent = 'Verified NIN Result';
                setResultStatus(ok ? 'verified' : 'failed');
                resultMessage.textContent = message || (ok ? 'Verification successful.' : 'Verification failed.');

                if (!ok) {
                    resultSourceHint.textContent = '';
                    refreshLiveBtn.classList.add('hidden');
                    profileSummaryWrap.classList.add('hidden');
                    ninDetailsWrap.innerHTML = '';
                    renderRawTable(payload || {});
                    clearVerifiedState();
                    return;
                }

                if (meta.cacheHit) {
                    resultSourceHint.textContent = meta.cachedAtLabel
                        ? `Saved record from ${meta.cachedAtLabel}.`
                        : 'Saved record shown.';
                } else if (meta.forceRefresh) {
                    resultSourceHint.textContent = 'Live provider record refreshed and saved.';
                } else {
                    resultSourceHint.textContent = 'Live provider record saved for faster reuse next time.';
                }

                if (meta.lookup) {
                    refreshLiveBtn.classList.remove('hidden');
                } else {
                    refreshLiveBtn.classList.add('hidden');
                }

                const faceSrc = toImageSrc(normalized?.photo || payload?.photo || payload?.image || '');
                ninFullName.textContent = normalizeValue(normalized?.full_name);
                ninNumberText.textContent = formatNin(normalized?.nin);
                ninTrackingText.textContent = normalizeValue(normalized?.tracking_id);
                ninBirthText.textContent = formatDisplayDate(normalized?.birthdate);
                ninGenderText.textContent = normalizeValue(normalized?.gender).toUpperCase();
                ninPhoneText.textContent = normalizeValue(normalized?.phone_number);
                ninAddressText.textContent = combineAddress(normalized);

                if (faceSrc) {
                    ninFaceImage.src = faceSrc;
                    ninFaceImage.classList.remove('hidden');
                    ninFaceFallback.classList.add('hidden');
                } else {
                    ninFaceImage.src = '';
                    ninFaceImage.classList.add('hidden');
                    ninFaceFallback.classList.remove('hidden');
                }

                profileSummaryWrap.classList.remove('hidden');

                const personalRows = [
                    { label: 'First Name', value: normalized?.first_name },
                    { label: 'Middle Name', value: normalized?.middle_name },
                    { label: 'Surname', value: normalized?.last_name },
                    { label: 'Gender', value: normalized?.gender },
                    { label: 'Birthdate', value: formatDisplayDate(normalized?.birthdate) },
                    { label: 'Phone Number', value: normalized?.phone_number },
                ];

                const locationRows = [
                    { label: 'State of Origin', value: normalized?.state },
                    { label: 'LGA of Origin', value: normalized?.lga },
                    { label: 'Residence State', value: normalized?.residence_state },
                    { label: 'Residence LGA', value: normalized?.residence_lga },
                    { label: 'Residence Town', value: normalized?.residence_town },
                    { label: 'Residence Address', value: normalized?.address_line_1 },
                ];

                ninDetailsWrap.innerHTML = `
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        ${sectionCard('Personal Details', personalRows)}
                        ${sectionCard('Location Details', locationRows)}
                    </div>
                `;

                renderRawTable(payload || {});
                enableDirectPrint(orderId, normalized || {}, payload || {}, meta);
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
                return fallback;
            }

            async function loadReports() {
                if (!slipReportsBody) return;

                slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-slate-500">Loading...</td></tr>';

                try {
                    const response = await fetch(routes.reports, { headers: { Accept: 'application/json' } });
                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data && data.ok === true;

                    if (!ok) {
                        const message = getErrorMessage(response, data, 'Unable to load reports.');
                        reportsMessage.textContent = message;
                        slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-slate-500">No report data available.</td></tr>';
                        return;
                    }

                    const rows = Array.isArray(data.data) ? data.data : [];
                    reportsMessage.textContent = rows.length > 0
                        ? `Total records: ${rows.length}`
                        : 'No reports returned yet.';

                    if (rows.length === 0) {
                        slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-slate-500">No report data available.</td></tr>';
                        return;
                    }

                    slipReportsBody.innerHTML = rows.map((row) => {
                        const nin = normalizeValue(row.nin || row.NIN);
                        const type = normalizeValue(row.type || row.slip_type);
                        const date = normalizeValue(row.date || row.created_at);
                        const href = row.slip_path || row.url || row.download_url || '';
                        const action = href
                            ? `<a href="${escapeHtml(href)}" target="_blank" class="font-semibold text-blue-600 underline">Download</a>`
                            : '-';

                        return `
                            <tr class="border-b border-slate-100">
                                <td class="py-2 pr-4">${escapeHtml(nin)}</td>
                                <td class="py-2 pr-4">${escapeHtml(type)}</td>
                                <td class="py-2 pr-4">${escapeHtml(date)}</td>
                                <td class="py-2">${action}</td>
                            </tr>
                        `;
                    }).join('');
                } catch (error) {
                    reportsMessage.textContent = 'Network error while loading reports.';
                    slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-slate-500">No report data available.</td></tr>';
                }
            }

            async function printVerifiedSlip(slipType) {
                if (!verifiedState?.orderId) {
                    notify('error', 'Verify a NIN record first before printing.');
                    return;
                }

                const popup = window.open('', '_blank');
                if (!popup) {
                    notify('error', 'Allow popups to print the slip.');
                    return;
                }

                popup.document.open();
                popup.document.write('<!doctype html><title>Preparing Slip</title><body style="font-family:Arial,sans-serif;padding:24px;">Preparing slip...</body>');
                popup.document.close();

                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Preparing NIN slip...');
                }

                directPrintButtons.forEach((button) => {
                    button.disabled = true;
                });

                try {
                    const formData = new FormData();
                    formData.append('_token', form.querySelector('input[name="_token"]').value);
                    formData.append('slip_type', slipType);
                    formData.append('verification_order_id', String(verifiedState.orderId));

                    const response = await fetch(routes.print, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: formData,
                    });

                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data && data.ok === true;
                    const message = ok
                        ? (data.message || 'Slip generated successfully.')
                        : getErrorMessage(response, data, 'Unable to generate the slip right now.');

                    if (!ok) {
                        popup.close();
                        notify('error', message);
                        return;
                    }

                    if (typeof window.updateWalletBalance === 'function' && Number.isFinite(Number(data.balance_kobo))) {
                        window.updateWalletBalance(Number(data.balance_kobo));
                    }

                    const slipUrl = data?.data?.slip_url;
                    if (!slipUrl) {
                        popup.close();
                        notify('error', 'The slip could not be prepared. Please try again.');
                        return;
                    }

                    popup.location.href = slipUrl;

                    notify('success', message);
                    await loadReports();
                } catch (error) {
                    popup.close();
                    notify('error', 'Network error. Please try again.');
                } finally {
                    if (typeof window.hideGlobalLoader === 'function') {
                        window.hideGlobalLoader();
                    }

                    if (verifiedState?.orderId) {
                        directPrintButtons.forEach((button) => {
                            button.disabled = false;
                        });
                    }
                }
            }

            async function submitVerificationLookup(lookup, options = {}) {
                if (form.dataset.submitting === '1') return;

                form.dataset.submitting = '1';
                submitBtn.disabled = true;
                refreshLiveBtn.disabled = true;
                clearVerifiedState();

                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader(options.loaderText || 'Verifying NIN record...');
                }

                try {
                    const response = await fetch(routes.verify, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: createLookupFormData(lookup, options.forceRefresh === true),
                    });

                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data && data.ok === true;
                    const message = ok
                        ? (data.message || 'Verification successful.')
                        : getErrorMessage(response, data, 'Verification failed. Please check your details.');

                    renderVerifyResult(ok, message, data?.data || {}, data?.normalized || {}, data?.order_id || null, {
                        cacheHit: data?.cache_hit === true,
                        cachedAtLabel: data?.cached_at_label || '',
                        forceRefresh: data?.force_refresh === true,
                        queued: data?.queued === true,
                        receiptUrl: data?.receipt_url || '',
                        lookup,
                    });

                    if (!ok) {
                        notify('error', message);
                    } else if (typeof window.updateWalletBalance === 'function' && Number.isFinite(Number(data.balance_kobo))) {
                        window.updateWalletBalance(Number(data.balance_kobo));
                    }
                } catch (error) {
                    renderVerifyResult(false, 'Network error. Please try again.', {}, {}, null);
                    notify('error', 'Network error. Please try again.');
                } finally {
                    if (typeof window.hideGlobalLoader === 'function') {
                        window.hideGlobalLoader();
                    }
                    form.dataset.submitting = '0';
                    submitBtn.disabled = false;
                    refreshLiveBtn.disabled = false;
                }
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const lookup = lookupPayloadFromFormData(new FormData(form));
                pendingVerifyLookup = lookup;
                openConfirmModal('confirmNinVerify', buildVerifyConfirmationData(lookup), 'ninVerifyBridgeForm');
            });

            verifyBridgeForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (!pendingVerifyLookup) return;
                await submitVerificationLookup(pendingVerifyLookup, { loaderText: 'Verifying NIN record...' });
            });

            resetFormBtn.addEventListener('click', function () {
                form.reset();
                renderVerificationFields();
                renderVerifyPrice();
                resultCard.classList.add('hidden');
                profileSummaryWrap.classList.add('hidden');
                ninDetailsWrap.innerHTML = '';
                ninRawTableWrap.innerHTML = '';
                clearVerifiedState();
            });

            refreshLiveBtn.addEventListener('click', async function () {
                if (!verifiedState?.lookup) return;
                await submitVerificationLookup(verifiedState.lookup, {
                    forceRefresh: true,
                    loaderText: 'Refreshing NIN record...',
                });
            });

            submitBtn.addEventListener('click', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.dispatchEvent(new Event('submit', { cancelable: true }));
            });

            directPrintButtons.forEach((button) => {
                button.addEventListener('click', function () {
                    const slipType = button.getAttribute('data-slip-type');
                    if (!slipType) return;
                    pendingSlipType = slipType;
                    openConfirmModal('confirmNinPrint', buildPrintConfirmationData(slipType), 'ninPrintBridgeForm');
                });
            });

            printBridgeForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (!pendingSlipType) return;
                await printVerifiedSlip(pendingSlipType);
            });

            verificationType.addEventListener('change', renderVerificationFields);
            refreshReportsBtn?.addEventListener('click', loadReports);

            renderVerificationFields();
            renderVerifyPrice();
            clearVerifiedState();
            loadReports();
        })();
    </script>
</x-app-layout>
