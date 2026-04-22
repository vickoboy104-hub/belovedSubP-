<x-app-layout>
    @php
        $priceNoRecord = (float) setting('price_nin_validation_no_record', 1000);
        $priceUpdateRecord = (float) setting('price_nin_validation_update_record', 1500);
        $validationMarkup = (float) setting('markup_nin_validation', 0);
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <section class="app-section p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="app-kicker">NIN Validation</div>
                    <h1 class="app-page-title mt-2 text-[1.7rem] sm:text-[2.2rem]">Submit Validation Request</h1>
                    <p class="mt-2 text-sm text-slate-500">Confirm the request first, then your wallet is charged once the validation request is sent.</p>
                </div>
                <div class="app-icon-ring shrink-0">
                    <svg viewBox="0 0 24 24" class="h-8 w-8 text-slate-700" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 12l2 2 4-4"></path>
                        <path d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7l7-4z"></path>
                    </svg>
                </div>
            </div>
        </section>

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-700">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="mt-2 list-disc pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
            <section class="app-form-shell space-y-4">
                <form id="ninValidationForm" method="POST" action="{{ route('vtu.nin-validation.submit') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-bold text-slate-700">Validation Category</label>
                        <select id="validation_type" name="validation_type" required class="input-field mt-2">
                            <option value="">Select validation category</option>
                            <option value="no_record" @selected(old('validation_type') === 'no_record')>No Record Found</option>
                            <option value="update_record" @selected(old('validation_type') === 'update_record')>Update Record</option>
                        </select>
                        <div id="validationPrice" class="mt-2 text-sm font-semibold text-rose-600"></div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700">NIN Number</label>
                        <input id="ninValidationNumber"
                               type="text"
                               name="nin"
                               value="{{ old('nin') }}"
                               required
                               maxlength="11"
                               inputmode="numeric"
                               class="input-field mt-2"
                               placeholder="Enter 11-digit NIN">
                    </div>

                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">
                        Validation requests are not refundable once successfully submitted to the provider.
                        @if($validationMarkup > 0)
                            Additional service charge: &#8358;{{ number_format($validationMarkup, 2) }}.
                        @endif
                    </div>

                    <button type="submit" class="btn-primary w-full justify-center">Continue</button>
                </form>
            </section>

            <section class="app-section p-5 sm:p-6">
                <h2 class="text-lg font-extrabold text-slate-900">Validation Reports</h2>
                <p class="mt-1 text-sm text-slate-500">Your recent requests and provider updates appear here.</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-4 text-left">NIN</th>
                                <th class="py-2 pr-4 text-left">Type</th>
                                <th class="py-2 pr-4 text-left">Status</th>
                                <th class="py-2 pr-4 text-left">Response</th>
                                <th class="py-2 text-left">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($reports as $report)
                                @php
                                    $meta = is_array($report->meta) ? $report->meta : [];
                                    $validationType = str_replace('_', ' ', (string) ($meta['validation_type'] ?? ''));
                                    $providerMessage = (string) ($meta['message'] ?? '');
                                @endphp
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-4">{{ $report->customer_ref }}</td>
                                    <td class="py-2 pr-4 capitalize">{{ $validationType ?: '-' }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="rounded-full px-2 py-1 text-xs font-bold {{ $report->status === 'success' ? 'bg-emerald-50 text-emerald-700' : ($report->status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') }}">
                                            {{ strtoupper($report->status) }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $providerMessage ?: '-' }}</td>
                                    <td class="py-2">{{ optional($report->created_at)->format('d M Y, h:ia') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-3 text-slate-500">No validation reports yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <form id="ninValidationBridgeForm" class="hidden"></form>
    <x-confirm-modal id="confirmNinValidation" title="Confirm NIN Validation" confirmText="Submit & Debit Wallet" />

    <script>
        (function () {
            const prices = {
                no_record: Number(@json($priceNoRecord)) + Number(@json($validationMarkup)),
                update_record: Number(@json($priceUpdateRecord)) + Number(@json($validationMarkup)),
            };

            const form = document.getElementById('ninValidationForm');
            const bridgeForm = document.getElementById('ninValidationBridgeForm');
            const typeSelect = document.getElementById('validation_type');
            const ninInput = document.getElementById('ninValidationNumber');
            const priceEl = document.getElementById('validationPrice');

            function updatePrice() {
                const key = typeSelect.value;
                if (!key || !prices[key]) {
                    priceEl.textContent = '';
                    return;
                }

                priceEl.textContent = 'This request costs ' + '\u20A6' + prices[key].toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }

            function validationLabel(value) {
                if (value === 'update_record') return 'Update Record';
                if (value === 'no_record') return 'No Record Found';
                return value || '-';
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                const type = typeSelect.value;
                const nin = (ninInput.value || '').trim();
                const amount = Number(prices[type] || 0);

                if (!type || !nin) {
                    return;
                }

                openConfirmModal('confirmNinValidation', {
                    service: 'NIN Validation',
                    category: validationLabel(type),
                    nin: nin,
                    amount: '\u20A6' + amount.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    }),
                    __debitAmount: amount,
                }, 'ninValidationBridgeForm');
            });

            bridgeForm.addEventListener('submit', function (event) {
                event.preventDefault();

                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Submitting validation request...');
                }

                form.submit();
            });

            typeSelect.addEventListener('change', updatePrice);
            updatePrice();
        })();
    </script>
</x-app-layout>
