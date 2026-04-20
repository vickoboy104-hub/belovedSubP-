<x-app-layout>
    <style>
        @media print {
            header, aside, nav, .no-print { display: none !important; }
            body, .nin-print-wrap { background: #fff !important; color: #000 !important; }
        }
    </style>
    <div class="max-w-5xl mx-auto w-full px-4 sm:px-0 space-y-5 nin-print-wrap">
        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">&#128179; NIN Verification & Slip Print</h2>
                    <p class="text-sm text-gray-600 dark:text-white/60 mt-1">
                        Verify by NIN, phone number, or demographic data. Slip print works only when print endpoints are configured.
                    </p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-xl">
                    &#128221;
                </div>
            </div>
        </div>

        <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <form id="ninServiceForm" class="space-y-4" method="POST">
                @csrf

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">What Did You Want To Do?</label>
                    <select id="service_type" name="service_type"
                            class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                        <option value="verify">Verify To See Full NIN Details Only</option>
                        <option value="print">Print NIN Slip</option>
                    </select>
                </div>

                <div id="slipTypeWrap" class="hidden space-y-2">
                    <div>
                        <label class="text-sm font-bold text-gray-700 dark:text-white/80">Choose Slip Type</label>
                        <select id="slip_type" name="slip_type"
                                class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                            <option value="">--Choose Slip Type--</option>
                            <option value="long_slip">NIN Slip (Long Slip)</option>
                            <option value="standard_slip">Standard NIN Slip</option>
                            <option value="premium_slip">Premium NIN Slip</option>
                            <option value="vnin_slip">Vnin NIN Slip Sample</option>
                        </select>
                    </div>
                    <div class="text-xs rounded-xl px-3 py-2 border border-red-200 text-red-700 bg-red-50 dark:bg-red-500/10 dark:border-red-500/20 dark:text-red-200">
                        Note: By proceeding, you agree to print or save the available ID as PDF or photocopy only.
                    </div>
                    <div id="slipPriceBadge" class="hidden inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-200">
                        Price: N180
                    </div>
                </div>

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">Verification Type</label>
                    <select id="verification_type" name="verification_type"
                            class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                        <option value="by_nin">By NIN</option>
                        <option value="by_phone">By Phone Number</option>
                        <option value="by_demo">By Demographic Data</option>
                    </select>
                </div>

                <div id="verificationFields" class="grid grid-cols-1 sm:grid-cols-2 gap-4"></div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="button" id="resetFormBtn"
                            class="sm:w-auto w-full px-4 py-3 rounded-2xl border border-gray-300 dark:border-white/20 text-gray-700 dark:text-white font-bold">
                        Reset
                    </button>
                    <button type="submit" id="ninSubmitBtn"
                            class="sm:flex-1 w-full px-4 py-3 rounded-2xl bg-blue-700 hover:bg-blue-800 text-white font-extrabold transition">
                        Submit Request
                    </button>
                </div>
            </form>
        </div>

        <div id="ninResultCard" class="hidden rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-xl font-extrabold">Verification Result</h3>
                <div class="flex items-center gap-2 no-print">
                    <span id="ninStatusBadge" class="px-3 py-1 rounded-full text-xs font-semibold border"></span>
                    <button type="button" onclick="window.print()"
                            class="px-3 py-2 rounded-xl text-xs font-bold border border-gray-300 dark:border-white/10 bg-black/5 dark:bg-white/10 hover:bg-black/10 dark:hover:bg-white/15">
                        Print / Save PDF
                    </button>
                </div>
            </div>
            <p id="ninResultMessage" class="mt-2 text-sm text-gray-600 dark:text-white/60"></p>

            <div id="profileSummaryWrap" class="mt-4 hidden rounded-2xl border border-gray-200 dark:border-white/10 p-4 sm:p-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
                    <div class="sm:col-span-1 flex items-center justify-center sm:justify-start">
                        <img id="ninFaceImage" alt="NIN Photo" class="hidden w-36 h-36 object-cover rounded-2xl border border-gray-200 dark:border-white/10">
                    </div>
                    <div class="sm:col-span-2">
                        <div id="ninFullName" class="text-2xl font-black text-gray-900 dark:text-white">-</div>
                        <div class="mt-2 flex flex-wrap gap-2 text-sm">
                            <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-800 dark:bg-blue-500/15 dark:text-blue-200 border border-blue-100 dark:border-blue-500/20">
                                NIN: <span id="ninNumberText">-</span>
                            </span>
                            <span class="px-3 py-1 rounded-full bg-green-50 text-black dark:bg-green-500/15 dark:text-green-200 border border-green-100 dark:border-green-500/20">
                                Tracking: <span id="ninTrackingText">-</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div id="ninDetailsWrap" class="mt-4 space-y-4"></div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-white/80">Show Raw Provider Fields</summary>
                <div id="ninRawTableWrap" class="mt-3 overflow-x-auto"></div>
            </details>
        </div>

        <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-extrabold">NIN Slip Reports</h3>
                <button type="button" id="refreshReportsBtn"
                        class="px-3 py-2 rounded-xl text-sm bg-orange-600 hover:bg-orange-700 text-white font-semibold">
                    Refresh
                </button>
            </div>
            <p id="reportsMessage" class="text-xs mt-2 text-gray-600 dark:text-white/60">
                If no reports appear, configure the NIN reports endpoint in Admin Settings.
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="text-left py-2 pr-4">NIN</th>
                            <th class="text-left py-2 pr-4">Slip Type</th>
                            <th class="text-left py-2 pr-4">Date</th>
                            <th class="text-left py-2">Action</th>
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

            const slipPrices = {
                long_slip: 'N180',
                standard_slip: 'N180',
                premium_slip: 'N180',
                vnin_slip: 'N180',
            };

            const form = document.getElementById('ninServiceForm');
            const serviceType = document.getElementById('service_type');
            const slipTypeWrap = document.getElementById('slipTypeWrap');
            const slipType = document.getElementById('slip_type');
            const slipPriceBadge = document.getElementById('slipPriceBadge');
            const verificationType = document.getElementById('verification_type');
            const verificationFields = document.getElementById('verificationFields');
            const submitBtn = document.getElementById('ninSubmitBtn');
            const resetFormBtn = document.getElementById('resetFormBtn');
            const refreshReportsBtn = document.getElementById('refreshReportsBtn');

            const resultCard = document.getElementById('ninResultCard');
            const statusBadge = document.getElementById('ninStatusBadge');
            const resultMessage = document.getElementById('ninResultMessage');
            const profileSummaryWrap = document.getElementById('profileSummaryWrap');
            const ninFaceImage = document.getElementById('ninFaceImage');
            const ninFullName = document.getElementById('ninFullName');
            const ninNumberText = document.getElementById('ninNumberText');
            const ninTrackingText = document.getElementById('ninTrackingText');
            const ninDetailsWrap = document.getElementById('ninDetailsWrap');
            const ninRawTableWrap = document.getElementById('ninRawTableWrap');
            const slipReportsBody = document.getElementById('slipReportsBody');
            const reportsMessage = document.getElementById('reportsMessage');

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

            function normalizeValue(value) {
                if (value === null || value === undefined) return '-';
                const text = String(value).trim();
                return text === '' ? '-' : text;
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
                        <input type="${escapeHtml(type)}" name="${escapeHtml(name)}" placeholder="${escapeHtml(placeholder)}"
                               class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-white/40">
                    </div>
                `;
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
                        <select name="gender" class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                            <option value="">Select gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="m">M</option>
                            <option value="f">F</option>
                        </select>
                    </div>
                `;
            }

            function renderServiceMode() {
                const isPrint = serviceType.value === 'print';
                slipTypeWrap.classList.toggle('hidden', !isPrint);
                submitBtn.textContent = isPrint ? 'Print Slip' : 'Verify Full Details';

                if (!isPrint) {
                    slipType.value = '';
                    slipPriceBadge.classList.add('hidden');
                }
            }

            function renderSlipPrice() {
                const value = slipType.value;
                if (!value) {
                    slipPriceBadge.classList.add('hidden');
                    slipPriceBadge.textContent = '';
                    return;
                }

                const price = slipPrices[value] || 'N180';
                slipPriceBadge.textContent = `Price: ${price}`;
                slipPriceBadge.classList.remove('hidden');
            }

            function setResultStatus(ok) {
                resultCard.classList.remove('hidden');
                statusBadge.textContent = ok ? 'SUCCESS' : 'FAILED';
                statusBadge.className = ok
                    ? 'px-3 py-1 rounded-full text-xs font-semibold border bg-green-50 text-green-800 border-green-200 dark:bg-green-500/15 dark:text-green-200 dark:border-green-500/20'
                    : 'px-3 py-1 rounded-full text-xs font-semibold border bg-red-50 text-red-800 border-red-200 dark:bg-red-500/15 dark:text-red-200 dark:border-red-500/20';
            }

            function sectionCard(title, rows) {
                const rowHtml = rows.map((row) => `
                    <div class="py-2 border-b border-gray-100 dark:border-white/10">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-white/50">${escapeHtml(row.label)}</div>
                        <div class="text-sm font-medium break-all text-gray-900 dark:text-white">${escapeHtml(normalizeValue(row.value))}</div>
                    </div>
                `).join('');

                return `
                    <div class="rounded-2xl border border-gray-200 dark:border-white/10 p-4">
                        <div class="font-bold text-gray-900 dark:text-white mb-2">${escapeHtml(title)}</div>
                        ${rowHtml}
                    </div>
                `;
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
                        <td class="py-2 pr-4 text-xs text-gray-500 dark:text-white/50 uppercase tracking-wide">${escapeHtml(key)}</td>
                        <td class="py-2 text-sm text-gray-900 dark:text-white break-all">${escapeHtml(normalizeValue(value))}</td>
                    </tr>
                `).join('');

                ninRawTableWrap.innerHTML = `<table class="w-full text-left"><tbody>${rows}</tbody></table>`;
            }

            function renderVerifyResult(ok, message, payload, normalized) {
                setResultStatus(ok);
                resultMessage.textContent = message || (ok ? 'Verification successful.' : 'Verification failed.');

                if (!ok) {
                    profileSummaryWrap.classList.add('hidden');
                    ninDetailsWrap.innerHTML = '';
                    renderRawTable(payload || {});
                    return;
                }

                const faceSrc = toImageSrc(normalized?.photo || payload?.photo || payload?.image || '');
                const signSrc = toImageSrc(normalized?.signature || payload?.signature || '');
                ninFullName.textContent = normalizeValue(normalized?.full_name);
                ninNumberText.textContent = normalizeValue(normalized?.nin);
                ninTrackingText.textContent = normalizeValue(normalized?.tracking_id);

                if (faceSrc) {
                    ninFaceImage.src = faceSrc;
                    ninFaceImage.classList.remove('hidden');
                } else {
                    ninFaceImage.src = '';
                    ninFaceImage.classList.add('hidden');
                }
                profileSummaryWrap.classList.remove('hidden');

                const personalRows = [
                    { label: 'First Name', value: normalized?.first_name },
                    { label: 'Middle Name', value: normalized?.middle_name },
                    { label: 'Last Name', value: normalized?.last_name },
                    { label: 'Gender', value: normalized?.gender },
                    { label: 'Birthdate', value: normalized?.birthdate },
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

                let mediaHtml = '';
                if (signSrc) {
                    mediaHtml = `
                        <div class="rounded-2xl border border-gray-200 dark:border-white/10 p-4">
                            <div class="font-bold text-gray-900 dark:text-white mb-2">Signature</div>
                            <img src="${escapeHtml(signSrc)}" alt="Signature" class="max-h-24 rounded-xl border border-gray-200 dark:border-white/10 bg-white p-1">
                        </div>
                    `;
                }

                ninDetailsWrap.innerHTML = `
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        ${sectionCard('Personal Details', personalRows)}
                        ${sectionCard('Location Details', locationRows)}
                    </div>
                    ${mediaHtml}
                `;

                renderRawTable(payload || {});
            }

            function renderPrintResult(ok, message, data) {
                setResultStatus(ok);
                resultMessage.textContent = message || (ok ? 'Slip request completed.' : 'Slip request failed.');
                profileSummaryWrap.classList.add('hidden');

                const possibleUrl = data?.url || data?.slip_url || data?.download_url || data?.link || '';
                let html = '';

                if (ok && possibleUrl) {
                    html += `
                        <div class="rounded-2xl border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 p-4 text-sm">
                            Slip is ready. <a href="${escapeHtml(possibleUrl)}" target="_blank" class="underline font-semibold">Download Slip</a>
                        </div>
                    `;
                } else if (ok) {
                    html += `
                        <div class="rounded-2xl border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 p-4 text-sm">
                            Print request was submitted successfully. If no URL returned, check reports below.
                        </div>
                    `;
                } else {
                    html += `
                        <div class="rounded-2xl border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 p-4 text-sm">
                            Print slip API may not be enabled yet. Add the provider print endpoint in Admin Settings.
                        </div>
                    `;
                }

                ninDetailsWrap.innerHTML = html;
                renderRawTable(data || {});
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
                            ? `<a href="${escapeHtml(href)}" target="_blank" class="underline text-blue-600 dark:text-blue-300 font-semibold">Download</a>`
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

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (form.dataset.submitting === '1') return;

                form.dataset.submitting = '1';
                submitBtn.disabled = true;
                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Processing request...');
                }

                const isPrint = serviceType.value === 'print';
                const endpoint = isPrint ? routes.print : routes.verify;

                try {
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: new FormData(form),
                    });

                    const data = await response.json().catch(() => ({}));
                    const ok = response.ok && data && data.ok === true;
                    const message = ok
                        ? (data.message || 'Request successful.')
                        : getErrorMessage(response, data, 'Request failed. Please check your entries.');

                    if (isPrint) {
                        renderPrintResult(ok, message, data?.data || data?.raw || {});
                        if (!ok) {
                            notify('error', message);
                        } else {
                            notify('success', message);
                            await loadReports();
                        }
                    } else {
                        renderVerifyResult(ok, message, data?.data || {}, data?.normalized || {});
                        if (!ok) {
                            notify('error', message);
                        }
                    }
                } catch (error) {
                    const message = 'Network error. Please try again.';
                    if (isPrint) {
                        renderPrintResult(false, message, {});
                    } else {
                        renderVerifyResult(false, message, {}, {});
                    }
                    notify('error', message);
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
                renderServiceMode();
                renderVerificationFields();
                renderSlipPrice();
            });

            serviceType.addEventListener('change', renderServiceMode);
            verificationType.addEventListener('change', renderVerificationFields);
            slipType.addEventListener('change', renderSlipPrice);
            refreshReportsBtn.addEventListener('click', loadReports);

            renderServiceMode();
            renderVerificationFields();
            renderSlipPrice();
            loadReports();
        })();
    </script>
</x-app-layout>
