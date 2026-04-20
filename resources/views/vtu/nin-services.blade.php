<x-app-layout>
    @php
        $verifyPrice = (float) setting('price_nin_verify', 180);
        $premiumCardBackground = asset('images/nin/premium-card-bg.png');
        $nimcLogo = asset('images/nin/nimc-logo.png');
        $coatOfArmsLogo = asset('images/nin/coat-of-arms.png');
        $internetExplorerLogo = asset('images/nin/internet-explorer-logo.png');
        $slipPrices = [
            'standard_slip' => (float) setting('price_nin_slip_standard', 180),
            'premium_slip' => (float) setting('price_nin_slip_premium', 180),
            'long_slip' => (float) setting('price_nin_slip_long', 180),
        ];
    @endphp

    <style>
        .nin-slip-action[disabled] {
            opacity: .55;
            cursor: not-allowed;
        }
    </style>

    <div class="mx-auto w-full max-w-5xl space-y-5 px-4 sm:px-0">
        <div class="rounded-3xl border border-gray-200 bg-white p-5 card-glow dark:border-white/10 dark:bg-white/5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">NIN Verification & Direct Slip Print</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-white/60">
                        Verify the NIN record once, then print Standard, Premium, or Long Slip directly from the verified result.
                    </p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-gray-200 bg-black/5 text-xl dark:border-white/10 dark:bg-white/10">
                    ID
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-white/10 dark:bg-white/5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-extrabold text-gray-900 dark:text-white">Verify Identity Record</div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-white/60">
                        Search by NIN, phone number, or demographic data. A successful result unlocks direct slip printing below.
                    </p>
                </div>
                <div id="verifyPriceBadge" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-500/15 dark:text-blue-200">
                    Verification fee
                </div>
            </div>

            <form id="ninServiceForm" class="space-y-4" method="POST">
                @csrf

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">Verification Type</label>
                    <select id="verification_type"
                            name="verification_type"
                            class="mt-1 w-full rounded-2xl border border-gray-300 bg-white px-4 py-3 text-gray-900 dark:border-white/10 dark:bg-black/30 dark:text-white">
                        <option value="by_nin">By NIN</option>
                        <option value="by_phone">By Phone Number</option>
                        <option value="by_demo">By Demographic Data</option>
                    </select>
                </div>

                <div id="verificationFields" class="grid grid-cols-1 gap-4 sm:grid-cols-2"></div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="button"
                            id="resetFormBtn"
                            class="w-full rounded-2xl border border-gray-300 px-4 py-3 font-bold text-gray-700 dark:border-white/20 dark:text-white sm:w-auto">
                        Reset
                    </button>
                    <button type="submit"
                            id="ninSubmitBtn"
                            class="w-full rounded-2xl bg-blue-700 px-4 py-3 font-extrabold text-white transition hover:bg-blue-800 sm:flex-1">
                        Verify NIN Record
                    </button>
                </div>
            </form>
        </div>

        <div id="ninResultCard" class="hidden rounded-3xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-white/10 dark:bg-white/5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-xl font-extrabold text-gray-900 dark:text-white">Verified NIN Result</h3>
                    <p id="ninResultMessage" class="mt-1 text-sm text-gray-600 dark:text-white/60"></p>
                </div>
                <span id="ninStatusBadge" class="rounded-full border px-3 py-1 text-xs font-semibold"></span>
            </div>

            <div id="profileSummaryWrap" class="mt-4 hidden rounded-3xl border border-gray-200 p-4 sm:p-5 dark:border-white/10">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[160px,1fr]">
                    <div class="flex items-center justify-center sm:justify-start">
                        <div class="overflow-hidden rounded-3xl border border-gray-200 bg-slate-50 dark:border-white/10 dark:bg-white/5">
                            <img id="ninFaceImage" alt="NIN Photo" class="hidden h-40 w-36 object-cover">
                            <div id="ninFaceFallback" class="flex h-40 w-36 items-center justify-center text-xs font-bold uppercase tracking-[0.2em] text-gray-400">
                                No Photo
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="text-2xl font-black text-gray-900 dark:text-white" id="ninFullName">-</div>
                        <div class="mt-2 flex flex-wrap gap-2 text-sm">
                            <span class="rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-blue-800 dark:border-blue-500/20 dark:bg-blue-500/15 dark:text-blue-200">
                                NIN: <span id="ninNumberText">-</span>
                            </span>
                            <span class="rounded-full border border-green-100 bg-green-50 px-3 py-1 text-green-800 dark:border-green-500/20 dark:bg-green-500/15 dark:text-green-200">
                                Tracking: <span id="ninTrackingText">-</span>
                            </span>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-gray-200 px-4 py-3 dark:border-white/10">
                                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">Date of Birth</div>
                                <div id="ninBirthText" class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">-</div>
                            </div>
                            <div class="rounded-2xl border border-gray-200 px-4 py-3 dark:border-white/10">
                                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">Gender</div>
                                <div id="ninGenderText" class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">-</div>
                            </div>
                            <div class="rounded-2xl border border-gray-200 px-4 py-3 dark:border-white/10">
                                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">Phone</div>
                                <div id="ninPhoneText" class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">-</div>
                            </div>
                            <div class="rounded-2xl border border-gray-200 px-4 py-3 dark:border-white/10">
                                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">Address</div>
                                <div id="ninAddressText" class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="directPrintWrap" class="mt-5 hidden rounded-3xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-extrabold text-gray-900 dark:text-white">Direct Slip Print</div>
                        <p id="directPrintNote" class="mt-1 text-sm text-gray-600 dark:text-white/60">
                            Verify a record first to unlock direct printing.
                        </p>
                    </div>
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700 dark:text-amber-200">
                        Print or Save as PDF
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <button type="button"
                            class="nin-slip-action rounded-2xl border border-gray-300 bg-white px-4 py-4 text-left transition hover:bg-gray-50 dark:border-white/10 dark:bg-black/20 dark:hover:bg-white/10"
                            data-slip-type="standard_slip"
                            disabled>
                        <div class="text-base font-extrabold text-gray-900 dark:text-white">Standard Slip</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-white/60">Classic printable NIN card layout.</div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-[0.16em] text-blue-700 dark:text-blue-200">
                            {!! '&#8358;' . number_format($slipPrices['standard_slip'], 2) !!}
                        </div>
                    </button>

                    <button type="button"
                            class="nin-slip-action rounded-2xl border border-gray-300 bg-white px-4 py-4 text-left transition hover:bg-gray-50 dark:border-white/10 dark:bg-black/20 dark:hover:bg-white/10"
                            data-slip-type="premium_slip"
                            disabled>
                        <div class="text-base font-extrabold text-gray-900 dark:text-white">Premium Slip</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-white/60">Digital green card style with issue date.</div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-[0.16em] text-blue-700 dark:text-blue-200">
                            {!! '&#8358;' . number_format($slipPrices['premium_slip'], 2) !!}
                        </div>
                    </button>

                    <button type="button"
                            class="nin-slip-action rounded-2xl border border-gray-300 bg-white px-4 py-4 text-left transition hover:bg-gray-50 dark:border-white/10 dark:bg-black/20 dark:hover:bg-white/10"
                            data-slip-type="long_slip"
                            disabled>
                        <div class="text-base font-extrabold text-gray-900 dark:text-white">Long Slip</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-white/60">Landscape NIMS table slip printout.</div>
                        <div class="mt-3 text-xs font-semibold uppercase tracking-[0.16em] text-blue-700 dark:text-blue-200">
                            {!! '&#8358;' . number_format($slipPrices['long_slip'], 2) !!}
                        </div>
                    </button>
                </div>
            </div>

            <div id="ninDetailsWrap" class="mt-4 space-y-4"></div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-white/80">Show Raw Provider Fields</summary>
                <div id="ninRawTableWrap" class="mt-3 overflow-x-auto"></div>
            </details>
        </div>

        <div class="rounded-3xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-extrabold text-gray-900 dark:text-white">NIN Slip Reports</h3>
                <button type="button"
                        id="refreshReportsBtn"
                        class="rounded-xl bg-orange-600 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-700">
                    Refresh
                </button>
            </div>
            <p id="reportsMessage" class="mt-2 text-xs text-gray-600 dark:text-white/60">
                Provider download reports appear here when available.
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4 text-left">NIN</th>
                            <th class="py-2 pr-4 text-left">Slip Type</th>
                            <th class="py-2 pr-4 text-left">Date</th>
                            <th class="py-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody id="slipReportsBody">
                        <tr>
                            <td colspan="4" class="py-3 text-gray-500 dark:text-white/50">No records loaded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const routes = {
                verify: @json(route('vtu.nin.search')),
                print: @json(route('vtu.nin.print')),
                reports: @json(route('vtu.nin.reports')),
            };

            const verifyPrice = @json($verifyPrice);
            const premiumCardBackground = @json($premiumCardBackground);
            const nimcLogo = @json($nimcLogo);
            const coatOfArmsLogo = @json($coatOfArmsLogo);
            const internetExplorerLogo = @json($internetExplorerLogo);
            const slipPrices = @json($slipPrices);

            const form = document.getElementById('ninServiceForm');
            const verificationType = document.getElementById('verification_type');
            const verificationFields = document.getElementById('verificationFields');
            const verifyPriceBadge = document.getElementById('verifyPriceBadge');
            const submitBtn = document.getElementById('ninSubmitBtn');
            const resetFormBtn = document.getElementById('resetFormBtn');
            const refreshReportsBtn = document.getElementById('refreshReportsBtn');

            const resultCard = document.getElementById('ninResultCard');
            const statusBadge = document.getElementById('ninStatusBadge');
            const resultMessage = document.getElementById('ninResultMessage');
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

            let verifiedState = null;

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
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">${escapeHtml(label)}</label>
                        <input type="${escapeHtml(type)}"
                               name="${escapeHtml(name)}"
                               placeholder="${escapeHtml(placeholder)}"
                               class="mt-1 w-full rounded-2xl border border-gray-300 bg-white px-4 py-3 text-gray-900 placeholder:text-gray-400 dark:border-white/10 dark:bg-black/30 dark:text-white dark:placeholder:text-white/40">
                    </div>
                `;
            }

            function sectionCard(title, rows) {
                const rowHtml = rows.map((row) => `
                    <div class="border-b border-gray-100 py-2 dark:border-white/10">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">${escapeHtml(row.label)}</div>
                        <div class="break-all text-sm font-medium text-gray-900 dark:text-white">${escapeHtml(normalizeValue(row.value))}</div>
                    </div>
                `).join('');

                return `
                    <div class="rounded-2xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="mb-2 font-bold text-gray-900 dark:text-white">${escapeHtml(title)}</div>
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

            function givenNames(normalized) {
                const parts = [
                    normalizeValue(normalized?.first_name),
                    normalizeValue(normalized?.middle_name),
                ].filter((value) => value !== '-');

                return parts.length > 0 ? parts.join(' ') : normalizeValue(normalized?.full_name);
            }

            function surname(normalized) {
                return normalizeValue(normalized?.last_name);
            }

            function renderVerificationFields() {
                const mode = verificationType.value;
                if (mode === 'by_nin') {
                    verificationFields.innerHTML = inputBlock('Enter NIN Number', 'nin', '11-digit NIN', 'text', true);
                    return;
                }

                if (mode === 'by_phone') {
                    verificationFields.innerHTML = inputBlock('Enter Phone Number', 'phone', 'Phone linked to NIN', 'text', true);
                    return;
                }

                verificationFields.innerHTML = `
                    ${inputBlock('First Name', 'firstname', 'Enter first name')}
                    ${inputBlock('Last Name', 'lastname', 'Enter last name')}
                    ${inputBlock('Date of Birth (dd-mm-yyyy)', 'dob', '16-02-1994')}
                    <div>
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">Gender</label>
                        <select name="gender"
                                class="mt-1 w-full rounded-2xl border border-gray-300 bg-white px-4 py-3 text-gray-900 dark:border-white/10 dark:bg-black/30 dark:text-white">
                            <option value="">Select gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="m">M</option>
                            <option value="f">F</option>
                        </select>
                    </div>
                `;
            }

            function renderVerifyPrice() {
                verifyPriceBadge.textContent = `Verification fee: ${formatSlipPrice(verifyPrice)}`;
            }

            function setResultStatus(ok) {
                resultCard.classList.remove('hidden');
                statusBadge.textContent = ok ? 'VERIFIED' : 'FAILED';
                statusBadge.className = ok
                    ? 'rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-800 dark:border-green-500/20 dark:bg-green-500/15 dark:text-green-200'
                    : 'rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-800 dark:border-red-500/20 dark:bg-red-500/15 dark:text-red-200';
            }

            function clearVerifiedState() {
                verifiedState = null;
                directPrintWrap.classList.add('hidden');
                directPrintNote.textContent = 'Verify a record first to unlock direct printing.';
                directPrintButtons.forEach((button) => {
                    button.disabled = true;
                });
            }

            function enableDirectPrint(orderId, normalized, payload) {
                verifiedState = {
                    orderId,
                    normalized: normalized || {},
                    payload: payload || {},
                };

                directPrintWrap.classList.remove('hidden');
                directPrintNote.textContent = `Verified NIN ${formatNin(normalized?.nin)} is ready. Choose Standard, Premium, or Long Slip to print directly.`;
                directPrintButtons.forEach((button) => {
                    button.disabled = false;
                });
            }

            function renderRawTable(data) {
                const skipKeys = new Set(['photo', 'image', 'signature']);
                const entries = Object.entries(data || {}).filter(([key]) => !skipKeys.has(String(key).toLowerCase()));

                if (entries.length === 0) {
                    ninRawTableWrap.innerHTML = '<div class="text-sm text-gray-500 dark:text-white/60">No extra fields returned.</div>';
                    return;
                }

                const rows = entries.map(([key, value]) => `
                    <tr class="border-b border-gray-100 dark:border-white/10">
                        <td class="py-2 pr-4 text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">${escapeHtml(key)}</td>
                        <td class="break-all py-2 text-sm text-gray-900 dark:text-white">${escapeHtml(normalizeValue(value))}</td>
                    </tr>
                `).join('');

                ninRawTableWrap.innerHTML = `<table class="w-full text-left"><tbody>${rows}</tbody></table>`;
            }

            function renderVerifyResult(ok, message, payload, normalized, orderId) {
                setResultStatus(ok);
                resultMessage.textContent = message || (ok ? 'Verification successful.' : 'Verification failed.');

                if (!ok) {
                    profileSummaryWrap.classList.add('hidden');
                    ninDetailsWrap.innerHTML = '';
                    renderRawTable(payload || {});
                    clearVerifiedState();
                    return;
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
                enableDirectPrint(orderId, normalized || {}, payload || {});
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

            function qrImageSource(normalized, providerData) {
                const candidates = ['qr_code', 'qrcode', 'barcode', 'bar_code', 'qr'];
                for (const key of candidates) {
                    const value = providerData?.[key];
                    const imageSrc = toImageSrc(value);
                    if (imageSrc) return imageSrc;
                    if (typeof value === 'string' && /^https?:\/\//i.test(value)) {
                        return value;
                    }
                }

                const qrPayload = [
                    `NIN:${digitsOnly(normalized?.nin)}`,
                    `NAME:${normalizeValue(normalized?.full_name)}`,
                    `DOB:${formatDisplayDate(normalized?.birthdate)}`,
                    `TRACKING:${normalizeValue(normalized?.tracking_id)}`,
                ].join('|');

                return `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(qrPayload)}`;
            }

            function slipDisclaimerHtml() {
                return `
                    <div class="nin-back-copy">
                        <div class="nin-back-tag">Trust, but verify</div>
                        <div class="nin-back-title">DISCLAIMER</div>
                        <p>Kindly ensure each time this ID is presented, that you verify the credentials using a Government-approved verification resource.</p>
                        <p>The details on the front of this NIN Slip must EXACTLY match the verification result.</p>
                        <div class="nin-back-caution">CAUTION!</div>
                        <p>If this NIN was not issued to the person on the front of this document, please DO NOT attempt to scan, photocopy or replicate the personal data contained herein.</p>
                        <p>You are only permitted to scan the barcode for the purpose of identity verification.</p>
                        <p>The FEDERAL GOVERNMENT OF NIGERIA assumes no responsibility if you accept any variance in the scan result or do not scan the 2D barcode.</p>
                    </div>
                `;
            }

            function buildCardBackPanel() {
                return `
                    <div class="nin-id-card nin-card-back">
                        ${slipDisclaimerHtml()}
                    </div>
                `;
            }

            function longSlipAddress(normalized) {
                const top = normalizeValue(
                    normalized?.address_line_1 ||
                    normalized?.address ||
                    normalized?.residence_address ||
                    normalized?.residence_town
                );

                const bottomParts = [
                    normalized?.residence_town,
                    normalized?.residence_lga,
                    normalized?.residence_state,
                    normalized?.state,
                ].map((value) => normalizeValue(value)).filter(Boolean);

                return {
                    top: top || '-',
                    bottom: bottomParts.join(', ') || normalizeValue(normalized?.state) || '-',
                };
            }

            function longSlipFooterIcon(kind) {
                const icons = {
                    email: `
                        <svg viewBox="0 0 64 64" aria-hidden="true">
                            <defs>
                                <linearGradient id="emailGrad" x1="0" x2="1" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#2d2b72"/>
                                    <stop offset="100%" stop-color="#5a46b8"/>
                                </linearGradient>
                            </defs>
                            <circle cx="32" cy="32" r="22" fill="url(#emailGrad)"/>
                            <path d="M18 23h28v18H18z" rx="3" fill="#f3f4ff"/>
                            <path d="M20 25l12 9 12-9" fill="none" stroke="#4637a3" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `,
                    phone: `
                        <svg viewBox="0 0 64 64" aria-hidden="true">
                            <rect x="10" y="10" width="44" height="44" rx="10" fill="#33b56f"/>
                            <path d="M41.5 39.5c-1.8 1.8-4 2.7-6.4 2.3-4.8-.9-12-8-12.8-12.8-.4-2.4.5-4.6 2.3-6.4l2.6-2.6c.8-.8 2.1-.9 3-.1l4.4 4c.9.8 1 2.2.2 3.1l-2.1 2.2c1.1 2 2.9 3.8 4.9 4.9l2.2-2.1c.9-.8 2.3-.7 3.1.2l4 4.4c.8.9.7 2.2-.1 3l-2.6 2.6z" fill="#fff"/>
                        </svg>
                    `,
                    card: `
                        <svg viewBox="0 0 64 64" aria-hidden="true">
                            <rect x="9" y="14" width="46" height="36" rx="5" fill="#f1f5f9" stroke="#94a3b8" stroke-width="2"/>
                            <rect x="14" y="20" width="14" height="16" rx="2" fill="#86c5da"/>
                            <path d="M19 26c0-2.4 1.9-4.3 4.3-4.3S27.6 23.6 27.6 26c0 2.3-1.9 4.2-4.3 4.2S19 28.3 19 26z" fill="#5b8aa0"/>
                            <path d="M16 36c1.8-3.5 5.2-5.6 7.4-5.6s5.6 2.1 7.4 5.6" fill="#5b8aa0"/>
                            <rect x="32" y="22" width="17" height="3" rx="1.5" fill="#64748b"/>
                            <rect x="32" y="29" width="14" height="3" rx="1.5" fill="#94a3b8"/>
                            <rect x="32" y="36" width="12" height="3" rx="1.5" fill="#94a3b8"/>
                        </svg>
                    `,
                };

                return `<span class="nin-footer-icon nin-footer-icon-${kind}">${icons[kind] || ''}</span>`;
            }

            function buildStandardSlip(normalized, providerData) {
                const photoSrc = toImageSrc(normalized?.photo || providerData?.photo || providerData?.image || '');
                const qrSrc = qrImageSource(normalized, providerData);
                const formattedNin = formatNin(normalized?.nin);
                const serialText = digitsOnly(normalized?.nin) || digitsOnly(normalized?.tracking_id) || formattedNin.replace(/\s+/g, '');

                return `
                    <div class="nin-sheet nin-sheet-portrait">
                        <div class="nin-id-card standard">
                            <div class="nin-card-watermark">
                                <img src="${escapeHtml(coatOfArmsLogo)}" alt="">
                            </div>
                            <img class="nin-card-badge standard-badge" src="${escapeHtml(coatOfArmsLogo)}" alt="">
                            <div class="nin-diagonal left">${escapeHtml(serialText)}</div>
                            <div class="nin-diagonal right">${escapeHtml(serialText)}</div>
                            <div class="nin-id-front-grid">
                                <div class="nin-front-photo standard-photo">
                                    ${photoSrc ? `<img src="${escapeHtml(photoSrc)}" alt="NIN Photo">` : '<div class="nin-photo-placeholder">NO PHOTO</div>'}
                                </div>

                                <div class="nin-front-content">
                                    <div class="nin-front-emblem"></div>
                                    <div class="nin-front-row">
                                        <div class="nin-front-label">Surname/Nom</div>
                                        <div class="nin-front-value">${escapeHtml(surname(normalized).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-front-row">
                                        <div class="nin-front-label">Given Names/Pr\u00e9noms</div>
                                        <div class="nin-front-value wide">${escapeHtml(givenNames(normalized).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-front-row">
                                        <div class="nin-front-label">Date of Birth</div>
                                        <div class="nin-front-value">${escapeHtml(formatDisplayDate(normalized?.birthdate))}</div>
                                    </div>
                                </div>

                                <div class="nin-front-qr-wrap">
                                    <div class="nin-qr-serial standard-qr-serial">${escapeHtml(serialText)}</div>
                                    <div class="nin-country-code standard-country">NGA</div>
                                    <div class="nin-qr-box">
                                        <img src="${escapeHtml(qrSrc)}" alt="QR Code" referrerpolicy="no-referrer">
                                    </div>
                                </div>
                            </div>

                            <div class="nin-number-title">National Identification Number (NIN)</div>
                            <div class="nin-number-line standard-number">${escapeHtml(formattedNin)}</div>
                            <div class="nin-front-note">Kindly ensure you scan the barcode to verify the credentials.</div>
                        </div>

                        ${buildCardBackPanel()}
                    </div>
                `;
            }

            function buildPremiumSlip(normalized, providerData, issuedAtLabel) {
                const photoSrc = toImageSrc(normalized?.photo || providerData?.photo || providerData?.image || '');
                const qrSrc = qrImageSource(normalized, providerData);
                const formattedNin = formatNin(normalized?.nin);
                const serialText = digitsOnly(normalized?.nin) || digitsOnly(normalized?.tracking_id) || formattedNin.replace(/\s+/g, '');

                return `
                    <div class="nin-sheet nin-sheet-portrait">
                        <div class="nin-id-card premium">
                            <div class="nin-card-watermark premium-watermark">
                                <img src="${escapeHtml(coatOfArmsLogo)}" alt="">
                            </div>
                            <img class="nin-card-badge premium-badge" src="${escapeHtml(coatOfArmsLogo)}" alt="">
                            <div class="nin-diagonal left">${escapeHtml(serialText)}</div>
                            <div class="nin-diagonal right">${escapeHtml(serialText)}</div>
                            <div class="nin-premium-heading">FEDERAL REPUBLIC OF NIGERIA</div>
                            <div class="nin-premium-subheading">DIGITAL NIN SLIP</div>

                            <div class="nin-premium-grid">
                                <div class="nin-front-photo premium-photo">
                                    ${photoSrc ? `<img src="${escapeHtml(photoSrc)}" alt="NIN Photo">` : '<div class="nin-photo-placeholder">NO PHOTO</div>'}
                                </div>

                                <div class="nin-front-content premium-content">
                                    <div class="nin-front-row">
                                        <div class="nin-front-label">Surname/Nom</div>
                                        <div class="nin-front-value">${escapeHtml(surname(normalized).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-front-row">
                                        <div class="nin-front-label">Given Names/Pr\u00e9noms</div>
                                        <div class="nin-front-value wide">${escapeHtml(givenNames(normalized).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-premium-info-row">
                                        <div>
                                            <div class="nin-front-label">Date of Birth</div>
                                            <div class="nin-front-value compact">${escapeHtml(formatDisplayDate(normalized?.birthdate))}</div>
                                        </div>
                                        <div>
                                            <div class="nin-front-label">Sex/Sexe</div>
                                            <div class="nin-front-value compact">${escapeHtml(normalizeValue(normalized?.gender).toUpperCase())}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="nin-premium-side">
                                    <div class="nin-qr-box premium-qr">
                                        <img src="${escapeHtml(qrSrc)}" alt="QR Code" referrerpolicy="no-referrer">
                                    </div>
                                    <div class="nin-country-code premium-country">NGA</div>
                                    <div class="nin-front-label issue-label">ISSUE DATE</div>
                                    <div class="nin-front-value compact">${escapeHtml(normalizeValue(issuedAtLabel).toUpperCase())}</div>
                                </div>
                            </div>

                            <div class="nin-number-title premium-title">National Identification Number (NIN)</div>
                            <div class="nin-number-line premium-number">${escapeHtml(formattedNin)}</div>
                        </div>

                        ${buildCardBackPanel()}
                    </div>
                `;
            }

            function buildLongSlip(normalized, providerData) {
                const photoSrc = toImageSrc(normalized?.photo || providerData?.photo || providerData?.image || '');
                const address = longSlipAddress(normalized);

                return `
                    <div class="nin-sheet nin-sheet-landscape">
                        <div class="nin-long-slip">
                            <div class="nin-long-head">
                                <div class="nin-long-logo left">
                                    <img src="${escapeHtml(coatOfArmsLogo)}" alt="Coat of Arms">
                                </div>
                                <div class="nin-long-title">
                                    <div class="main">National Identity Management System</div>
                                    <div class="sub">Federal Republic of Nigeria</div>
                                    <div class="mini">National Identification Number Slip (NINS)</div>
                                </div>
                                <div class="nin-long-logo right">
                                    <img src="${escapeHtml(nimcLogo)}" alt="NIMC">
                                </div>
                            </div>

                            <div class="nin-long-grid">
                                <div class="nin-long-col left">
                                    <div class="nin-long-row">
                                        <div class="label">Tracking ID:</div>
                                        <div class="value">${escapeHtml(normalizeValue(normalized?.tracking_id))}</div>
                                    </div>
                                    <div class="nin-long-row">
                                        <div class="label">NIN:</div>
                                        <div class="value">${escapeHtml(digitsOnly(normalized?.nin) || normalizeValue(normalized?.nin))}</div>
                                    </div>
                                </div>

                                <div class="nin-long-col middle">
                                    <div class="nin-long-row">
                                        <div class="label">Surname:</div>
                                        <div class="value">${escapeHtml(surname(normalized).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-long-row">
                                        <div class="label">First Name:</div>
                                        <div class="value">${escapeHtml(normalizeValue(normalized?.first_name).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-long-row">
                                        <div class="label">Middle Name:</div>
                                        <div class="value">${escapeHtml(normalizeValue(normalized?.middle_name).toUpperCase())}</div>
                                    </div>
                                    <div class="nin-long-row">
                                        <div class="label">Gender:</div>
                                        <div class="value">${escapeHtml(normalizeValue(normalized?.gender).toUpperCase())}</div>
                                    </div>
                                </div>

                                <div class="nin-long-col address">
                                    <div class="label">Address:</div>
                                    <div class="value multiline">
                                        <div class="address-top">${escapeHtml(address.top)}</div>
                                        <div class="address-bottom">${escapeHtml(address.bottom)}</div>
                                    </div>
                                </div>

                                <div class="nin-long-photo">
                                    ${photoSrc ? `<img src="${escapeHtml(photoSrc)}" alt="NIN Photo">` : '<div class="nin-photo-placeholder">NO PHOTO</div>'}
                                </div>
                            </div>

                            <div class="nin-long-note-row">
                                <div class="note-left">Note: The National Identification Number (NIN) is your identity.</div>
                                <div class="note-right">It is confidential and may only be released for legitimate transactions.</div>
                            </div>

                            <div class="nin-long-foot-note">
                                You will be notified when your National Identity Card is ready (for any enquiries please contact)
                            </div>

                            <div class="nin-long-footer">
                                <div>
                                    ${longSlipFooterIcon('email')}
                                    <strong>helpdesk@nimc.gov.ng</strong>
                                </div>
                                <div>
                                    <span class="nin-footer-icon nin-footer-icon-image"><img src="${escapeHtml(internetExplorerLogo)}" alt="Internet"></span>
                                    <strong>www.nimc.gov.ng</strong>
                                </div>
                                <div>
                                    ${longSlipFooterIcon('phone')}
                                    <strong>0700-CALL-NIMC</strong>
                                    <span>(0700-2255-646)</span>
                                </div>
                                <div>
                                    ${longSlipFooterIcon('card')}
                                    <strong>National Identity Management Commission</strong>
                                    <span>11, Sokode Crescent, Off Dalaba Street, Zone 5 Wuse, Abuja Nigeria</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            function buildPrintHtml(slipType, normalized, providerData, issuedAtLabel) {
                const safeIssuedAt = normalizeValue(issuedAtLabel).toUpperCase();
                const isLongSlip = slipType === 'long_slip';
                const bodyClass = isLongSlip ? 'nin-print-landscape' : 'nin-print-portrait';
                const pageRule = isLongSlip
                    ? '@page { size: A4 landscape; margin: 8mm; }'
                    : '@page { size: A4 portrait; margin: 8mm 0; }';
                const body = slipType === 'premium_slip'
                    ? buildPremiumSlip(normalized, providerData, safeIssuedAt)
                    : (isLongSlip
                        ? buildLongSlip(normalized, providerData)
                        : buildStandardSlip(normalized, providerData));

                return `
                    <!doctype html>
                    <html lang="en">
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <title>NIN Slip Print</title>
                        <style>
                            ${pageRule}
                            * { box-sizing: border-box; }
                            html, body {
                                margin: 0;
                                padding: 0;
                            }
                            body {
                                background: #e5e7eb;
                                color: #111827;
                                font-family: Arial, Helvetica, sans-serif;
                                -webkit-print-color-adjust: exact;
                                print-color-adjust: exact;
                            }
                            body.nin-print-portrait {
                                min-height: 297mm;
                            }
                            body.nin-print-landscape {
                                min-height: 210mm;
                            }
                            .nin-sheet {
                                width: 100%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                            }
                            .nin-sheet-portrait {
                                min-height: calc(297mm - 16mm);
                                flex-direction: column;
                                gap: 5.5mm;
                                padding: 6mm 0;
                            }
                            .nin-sheet-landscape {
                                min-height: calc(210mm - 16mm);
                                padding: 0;
                            }
                            .nin-id-card,
                            .nin-long-slip {
                                background: #fff;
                                color: #111;
                                box-shadow: 0 10px 30px rgba(15, 23, 42, 0.16);
                            }
                            .nin-id-card {
                                position: relative;
                                width: 85.6mm;
                                height: 54mm;
                                overflow: hidden;
                                border: 0.35mm solid #c9ccd1;
                            }
                            .nin-id-card::before {
                                content: '';
                                position: absolute;
                                inset: 0;
                                pointer-events: none;
                            }
                            .nin-id-card.standard {
                                padding: 4mm 4.4mm 3.3mm;
                                background: linear-gradient(180deg, #fafafa 0%, #ffffff 100%);
                            }
                            .nin-id-card.standard::before {
                                background: linear-gradient(180deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0.06));
                                opacity: 1;
                            }
                            .nin-id-card.standard::after {
                                content: '';
                                position: absolute;
                                inset: 0;
                                background: linear-gradient(180deg, rgba(17, 24, 39, 0.015), transparent 40%);
                                pointer-events: none;
                            }
                            .nin-id-card.premium {
                                padding: 3.9mm 4.2mm 3.2mm;
                                background: #eef3e2;
                            }
                            .nin-id-card.premium::before {
                                background: center/cover no-repeat url('${premiumCardBackground}');
                                opacity: 0.98;
                            }
                            .nin-id-card.premium::after {
                                content: '';
                                position: absolute;
                                inset: 0;
                                background: linear-gradient(180deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0.1));
                                pointer-events: none;
                            }
                            .nin-id-card > * {
                                position: relative;
                                z-index: 1;
                            }
                            .nin-card-watermark {
                                position: absolute;
                                inset: 0;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                opacity: 0.09;
                                pointer-events: none;
                            }
                            .nin-card-watermark img {
                                width: 40mm;
                                height: auto;
                                object-fit: contain;
                            }
                            .nin-card-watermark.premium-watermark {
                                opacity: 0.16;
                                transform: translateY(-1mm);
                            }
                            .nin-card-badge {
                                position: absolute;
                                top: 1.6mm;
                                left: 50%;
                                transform: translateX(-50%);
                                width: 12mm;
                                height: auto;
                                object-fit: contain;
                                pointer-events: none;
                            }
                            .premium-badge {
                                top: 1.8mm;
                                width: 11.5mm;
                            }
                            .nin-front-emblem {
                                display: none;
                            }
                            .nin-premium-heading,
                            .nin-premium-subheading {
                                font-weight: 800;
                                text-transform: uppercase;
                            }
                            .nin-premium-heading {
                                font-size: 2.55mm;
                                line-height: 1.05;
                                letter-spacing: 0.12mm;
                                color: #0f6f3b;
                            }
                            .nin-premium-subheading {
                                font-size: 2.4mm;
                                line-height: 1.05;
                                letter-spacing: 0.08mm;
                                color: #1b3e25;
                                margin-top: 0.15mm;
                            }
                            .nin-diagonal {
                                position: absolute;
                                color: rgba(31, 41, 55, 0.3);
                                font-size: 2.6mm;
                                letter-spacing: 0.05mm;
                                font-family: "Arial Narrow", Arial, sans-serif;
                                pointer-events: none;
                            }
                            .nin-diagonal.left {
                                left: 1.8mm;
                                bottom: 10.1mm;
                                transform: rotate(-29deg);
                            }
                            .nin-diagonal.right {
                                right: 1.1mm;
                                bottom: 9.7mm;
                                transform: rotate(28deg);
                            }
                            .nin-id-front-grid,
                            .nin-premium-grid {
                                display: grid;
                                align-items: start;
                            }
                            .nin-id-front-grid {
                                grid-template-columns: 17.2mm 1fr 22.6mm;
                                column-gap: 3.2mm;
                                margin-top: 4.8mm;
                            }
                            .nin-premium-grid {
                                grid-template-columns: 17.1mm 1fr 23.4mm;
                                column-gap: 2.8mm;
                                margin-top: 2.2mm;
                            }
                            .nin-front-photo {
                                overflow: hidden;
                                background: rgba(255, 255, 255, 0.72);
                            }
                            .nin-front-photo.standard-photo {
                                width: 16.8mm;
                                height: 20.6mm;
                                border: 0.25mm solid rgba(17, 24, 39, 0.28);
                            }
                            .nin-front-photo.premium-photo {
                                width: 16.8mm;
                                height: 20.3mm;
                                border: 0.25mm solid rgba(17, 24, 39, 0.16);
                            }
                            .nin-front-photo img,
                            .nin-long-photo img {
                                display: block;
                                width: 100%;
                                height: 100%;
                                object-fit: cover;
                            }
                            .nin-front-content {
                                position: relative;
                                min-width: 0;
                            }
                            .nin-id-front-grid .nin-front-content {
                                padding-top: 1.4mm;
                            }
                            .premium-content {
                                padding-top: 1.1mm;
                            }
                            .nin-front-row {
                                margin-bottom: 1.15mm;
                            }
                            .nin-front-row:last-child {
                                margin-bottom: 0;
                            }
                            .nin-front-label {
                                font-size: 1.95mm;
                                font-weight: 700;
                                line-height: 1.1;
                                color: rgba(17, 24, 39, 0.52);
                                text-transform: none;
                            }
                            .nin-front-value {
                                margin-top: 0.32mm;
                                font-size: 3.2mm;
                                font-weight: 800;
                                line-height: 1.08;
                                letter-spacing: 0.1mm;
                                color: #111827;
                                word-break: break-word;
                            }
                            .nin-front-value.wide {
                                letter-spacing: 0.19mm;
                            }
                            .nin-front-value.compact {
                                font-size: 3mm;
                            }
                            .nin-premium-info-row {
                                display: grid;
                                grid-template-columns: 1fr 1fr;
                                gap: 2mm;
                                margin-top: 0.2mm;
                            }
                            .nin-premium-side {
                                text-align: center;
                            }
                            .nin-front-qr-wrap {
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                                justify-content: flex-start;
                                gap: 0.7mm;
                            }
                            .nin-qr-serial {
                                color: rgba(17, 24, 39, 0.62);
                                font-size: 2.35mm;
                                line-height: 1;
                                letter-spacing: 0.08mm;
                                font-family: "Arial Narrow", Arial, sans-serif;
                            }
                            .standard-qr-serial {
                                transform: rotate(180deg);
                                margin-bottom: 0.1mm;
                            }
                            .nin-country-code {
                                font-weight: 800;
                                letter-spacing: 0.18mm;
                                color: #111827;
                            }
                            .nin-country-code.premium-country {
                                font-size: 5.8mm;
                                margin-top: 0.2mm;
                                line-height: 1;
                            }
                            .nin-country-code.standard-country {
                                font-size: 5.7mm;
                                margin-bottom: 0.4mm;
                                line-height: 1;
                            }
                            .issue-label {
                                margin-top: 0.6mm;
                            }
                            .nin-qr-box {
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                width: 20.1mm;
                                height: 20.1mm;
                                padding: 0.55mm;
                                background: rgba(255, 255, 255, 0.92);
                                border: 0.22mm solid rgba(17, 24, 39, 0.18);
                            }
                            .nin-qr-box img {
                                display: block;
                                width: 100%;
                                height: 100%;
                                object-fit: contain;
                            }
                            .premium-qr {
                                width: 21.6mm;
                                height: 21.6mm;
                            }
                            .nin-number-title {
                                margin-top: 1.7mm;
                                font-size: 2.45mm;
                                text-align: center;
                                font-weight: 700;
                                line-height: 1.15;
                            }
                            .nin-number-title.premium-title {
                                margin-top: 1.9mm;
                            }
                            .nin-number-line {
                                text-align: center;
                                font-weight: 800;
                                line-height: 1;
                                color: #111;
                                font-family: "Arial Black", Arial, Helvetica, sans-serif;
                            }
                            .nin-number-line.standard-number {
                                margin-top: 0.7mm;
                                font-size: 7.2mm;
                                letter-spacing: 0.74mm;
                            }
                            .nin-number-line.premium-number {
                                margin-top: 0.65mm;
                                font-size: 6.95mm;
                                letter-spacing: 0.7mm;
                            }
                            .nin-front-note {
                                margin-top: 0.8mm;
                                font-size: 1.75mm;
                                text-align: center;
                                color: rgba(17, 24, 39, 0.66);
                                font-style: italic;
                            }
                            .nin-card-back {
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                background: #d9d9d9;
                                border: 0.45mm solid #222;
                                transform: rotate(180deg);
                                padding: 3.8mm;
                            }
                            .nin-back-copy {
                                position: relative;
                                width: 100%;
                                border: 0.4mm solid #262626;
                                padding: 3.1mm 4mm 3.1mm 7.4mm;
                                text-align: center;
                                background: rgba(255, 255, 255, 0.28);
                            }
                            .nin-back-copy::before {
                                content: '';
                                position: absolute;
                                left: 4.6mm;
                                top: 0;
                                bottom: 0;
                                width: 0.32mm;
                                background: #262626;
                            }
                            .nin-back-tag {
                                font-size: 2.9mm;
                                font-family: Georgia, "Times New Roman", serif;
                                font-style: italic;
                                margin-bottom: 1.2mm;
                            }
                            .nin-back-title {
                                font-size: 6.9mm;
                                font-weight: 900;
                                line-height: 1;
                                margin-bottom: 1.2mm;
                                letter-spacing: 0.14mm;
                            }
                            .nin-back-copy p {
                                margin: 0 0 1.4mm;
                                font-size: 2.05mm;
                                line-height: 1.34;
                            }
                            .nin-back-copy p:last-child {
                                margin-bottom: 0;
                            }
                            .nin-back-caution {
                                font-size: 5.2mm;
                                font-weight: 900;
                                line-height: 1;
                                margin: 1.5mm 0 1.1mm;
                            }
                            .nin-long-slip {
                                width: 258mm;
                                min-height: 116.5mm;
                                border: 0.5mm solid #222;
                                background: #efefef;
                                overflow: hidden;
                            }
                            .nin-long-head {
                                display: grid;
                                grid-template-columns: 26mm 1fr 26mm;
                                align-items: stretch;
                                border-bottom: 0.35mm solid #222;
                                min-height: 23mm;
                            }
                            .nin-long-logo {
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                padding: 1.2mm;
                            }
                            .nin-long-logo img {
                                max-width: 100%;
                                max-height: 17mm;
                                object-fit: contain;
                            }
                            .nin-long-logo.left {
                                border-right: 0.35mm solid #222;
                            }
                            .nin-long-logo.right {
                                border-left: 0.35mm solid #222;
                            }
                            .nin-long-title {
                                text-align: center;
                                padding: 1.6mm 4mm 1.3mm;
                            }
                            .nin-long-title .main {
                                font-size: 7.6mm;
                                font-weight: 900;
                                line-height: 1.02;
                            }
                            .nin-long-title .sub {
                                margin-top: 0.8mm;
                                font-size: 4.4mm;
                                font-weight: 800;
                            }
                            .nin-long-title .mini {
                                margin-top: 0.7mm;
                                font-size: 3.85mm;
                                font-weight: 700;
                            }
                            .nin-long-grid {
                                display: grid;
                                grid-template-columns: 68mm 71mm 74mm 45mm;
                                min-height: 53mm;
                            }
                            .nin-long-col,
                            .nin-long-photo {
                                border-right: 0.35mm solid #222;
                            }
                            .nin-long-row,
                            .nin-long-col.address {
                                display: grid;
                                grid-template-columns: 18.5mm 1fr;
                                min-height: 13.2mm;
                                border-bottom: 0.35mm solid #222;
                            }
                            .nin-long-col.address {
                                grid-template-columns: 17.5mm 1fr;
                            }
                            .nin-long-row .label,
                            .nin-long-row .value,
                            .nin-long-col.address .label,
                            .nin-long-col.address .value {
                                padding: 2.2mm 2mm;
                                font-size: 3.25mm;
                                line-height: 1.18;
                            }
                            .nin-long-row .label,
                            .nin-long-col.address .label {
                                font-weight: 800;
                                border-right: 0.35mm solid #222;
                            }
                            .nin-long-row .value,
                            .nin-long-col.address .value {
                                font-weight: 700;
                            }
                            .nin-long-col.address .value.multiline {
                                display: flex;
                                flex-direction: column;
                                justify-content: space-between;
                                line-height: 1.2;
                            }
                            .nin-long-col.address .address-top {
                                font-size: 3.18mm;
                            }
                            .nin-long-col.address .address-bottom {
                                font-size: 3.05mm;
                            }
                            .nin-long-photo {
                                display: flex;
                                align-items: stretch;
                                justify-content: center;
                                min-height: 53mm;
                                background: #ddd;
                            }
                            .nin-long-note-row {
                                display: grid;
                                grid-template-columns: 1fr 1fr;
                                border-top: 0.35mm solid #222;
                                border-bottom: 0.35mm solid #222;
                            }
                            .nin-long-note-row > div {
                                padding: 2.6mm 3mm;
                                font-size: 3mm;
                                line-height: 1.18;
                            }
                            .nin-long-foot-note {
                                padding: 2.6mm 3mm;
                                font-size: 3mm;
                                line-height: 1.18;
                                border-bottom: 0.35mm solid #222;
                            }
                            .nin-long-footer {
                                display: grid;
                                grid-template-columns: 42mm 42mm 46mm 1fr;
                            }
                            .nin-long-footer > div {
                                min-height: 24mm;
                                padding: 2.2mm 2.2mm 1.8mm;
                                border-right: 0.35mm solid #222;
                                font-size: 2.8mm;
                                text-align: center;
                                line-height: 1.16;
                                font-weight: 700;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                flex-direction: column;
                                gap: 0.6mm;
                            }
                            .nin-long-footer > div:last-child {
                                border-right: 0;
                            }
                            .nin-long-footer strong {
                                font-size: 2.95mm;
                                line-height: 1.15;
                            }
                            .nin-long-footer span {
                                font-size: 2.35mm;
                                font-weight: 600;
                            }
                            .nin-footer-icon {
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                width: 9.8mm;
                                height: 9.8mm;
                            }
                            .nin-footer-icon img,
                            .nin-footer-icon svg {
                                width: 100%;
                                height: 100%;
                                object-fit: contain;
                                display: block;
                            }
                            .nin-footer-icon-image {
                                width: 10.2mm;
                                height: 10.2mm;
                            }
                            .nin-photo-placeholder {
                                width: 100%;
                                height: 100%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                background: rgba(255, 255, 255, 0.7);
                                color: rgba(17, 24, 39, 0.45);
                                font-size: 2.6mm;
                                font-weight: 700;
                                letter-spacing: 0.2mm;
                            }
                            @media print {
                                body {
                                    background: #fff;
                                }
                                .nin-id-card,
                                .nin-long-slip {
                                    box-shadow: none;
                                }
                            }
                        </style>
                    </head>
                    <body class="${bodyClass}">
                        ${body}
                        <script>
                            window.addEventListener('load', function () {
                                setTimeout(function () { window.print(); }, 350);
                            });
                        <\/script>
                    </body>
                    </html>
                `;
            }

            async function loadReports() {
                slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-gray-500 dark:text-white/50">Loading...</td></tr>';

                try {
                    const response = await fetch(routes.reports, { headers: { Accept: 'application/json' } });
                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data && data.ok === true;

                    if (!ok) {
                        const message = getErrorMessage(response, data, 'Unable to load reports.');
                        reportsMessage.textContent = message;
                        slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-gray-500 dark:text-white/50">No report data available.</td></tr>';
                        return;
                    }

                    const rows = Array.isArray(data.data) ? data.data : [];
                    reportsMessage.textContent = rows.length > 0
                        ? `Total records: ${rows.length}`
                        : 'No reports returned yet.';

                    if (rows.length === 0) {
                        slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-gray-500 dark:text-white/50">No report data available.</td></tr>';
                        return;
                    }

                    slipReportsBody.innerHTML = rows.map((row) => {
                        const nin = normalizeValue(row.nin || row.NIN);
                        const type = normalizeValue(row.type || row.slip_type);
                        const date = normalizeValue(row.date || row.created_at);
                        const href = row.slip_path || row.url || row.download_url || '';
                        const action = href
                            ? `<a href="${escapeHtml(href)}" target="_blank" class="font-semibold text-blue-600 underline dark:text-blue-300">Download</a>`
                            : '-';

                        return `
                            <tr class="border-b border-gray-100 dark:border-white/10">
                                <td class="py-2 pr-4">${escapeHtml(nin)}</td>
                                <td class="py-2 pr-4">${escapeHtml(type)}</td>
                                <td class="py-2 pr-4">${escapeHtml(date)}</td>
                                <td class="py-2">${action}</td>
                            </tr>
                        `;
                    }).join('');
                } catch (error) {
                    reportsMessage.textContent = 'Network error while loading reports.';
                    slipReportsBody.innerHTML = '<tr><td colspan="4" class="py-3 text-gray-500 dark:text-white/50">No report data available.</td></tr>';
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

                    const normalized = data?.data?.normalized || verifiedState.normalized || {};
                    const providerData = data?.data?.provider_data || verifiedState.payload || {};
                    const issuedAtLabel = data?.data?.issued_at_label || '';
                    const html = buildPrintHtml(slipType, normalized, providerData, issuedAtLabel);

                    popup.document.open();
                    popup.document.write(html);
                    popup.document.close();

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

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (form.dataset.submitting === '1') return;

                form.dataset.submitting = '1';
                submitBtn.disabled = true;
                clearVerifiedState();

                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Verifying NIN record...');
                }

                try {
                    const response = await fetch(routes.verify, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: new FormData(form),
                    });

                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data && data.ok === true;
                    const message = ok
                        ? (data.message || 'Verification successful.')
                        : getErrorMessage(response, data, 'Verification failed. Please check your details.');

                    renderVerifyResult(ok, message, data?.data || {}, data?.normalized || {}, data?.order_id || null);

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
                }
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

            directPrintButtons.forEach((button) => {
                button.addEventListener('click', function () {
                    printVerifiedSlip(button.getAttribute('data-slip-type'));
                });
            });

            verificationType.addEventListener('change', renderVerificationFields);
            refreshReportsBtn.addEventListener('click', loadReports);

            renderVerificationFields();
            renderVerifyPrice();
            clearVerifiedState();
            loadReports();
        })();
    </script>
</x-app-layout>
