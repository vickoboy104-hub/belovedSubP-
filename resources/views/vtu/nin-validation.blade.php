<x-app-layout>
    @php
        $priceNoRecord = identity_price('price_nin_validation_no_record');
        $priceUpdateRecord = identity_price('price_nin_validation_update_record');
        $validationMarkup = (float) setting('markup_nin_validation', 0);
    @endphp

    <x-page-hero class="reference-shared-banner" title="NIN Validation" subtitle="Confirm the request first, then your wallet is charged once the validation request is sent.">
        <a href="{{ route('vtu.nin') }}" class="reference-hero-action">All NIN Services</a>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
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
            <section class="app-form-shell space-y-4" @if($paused) data-service-paused @endif>
                @if($paused)
                    {{-- The reports and the price stay on the page while the job is
                         resting: they are the two things a customer can still act on,
                         and only the request itself is closed. --}}
                    <x-service-outage-notice :down="['nin_validation' => 'NIN Validation']" :message="$pausedNotice" />

                    <p class="text-sm leading-6 text-slate-700">
                        No record found &#8358;{{ number_format($priceNoRecord + $validationMarkup, 2) }}
                        &bull; Update record &#8358;{{ number_format($priceUpdateRecord + $validationMarkup, 2) }}.
                        Nothing is charged while the service is paused.
                    </p>
                @else
                <form id="ninValidationForm" method="POST" action="{{ route('vtu.nin-validation.submit') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-bold text-slate-700">Validation Category</label>
                        <select id="validation_type" name="validation_type" required class="input-field mt-2">
                            <option value="">--Select Validation Category--</option>
                            <option value="no_record" @selected(old('validation_type') === 'no_record')>No Record Found</option>
                            <option value="update_record" @selected(old('validation_type') === 'update_record')>Update Record (Name / Phone / Address, not date of birth)</option>
                        </select>
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
                               placeholder="Enter NIN e.g 74227342856">
                    </div>

                    <x-price-lock id="validationPrice" label="Price for this validation" />

                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">
                        Once the request has been sent it cannot be cancelled. If the provider fails the
                        job, the amount is returned to your wallet.
                        @if($validationMarkup > 0)
                            Additional service charge: &#8358;{{ number_format($validationMarkup, 2) }}.
                        @endif
                    </div>

                    <button type="submit" class="btn-primary w-full justify-center">Continue</button>
                </form>
                @endif
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
                                <th class="py-2 pr-4 text-left">Date</th>
                                <th class="py-2 text-left">Next step</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($reports as $report)
                                @php
                                    $meta = is_array($report->meta) ? $report->meta : [];
                                    $validationType = str_replace('_', ' ', (string) ($meta['validation_type'] ?? ''));
                                    $providerMessage = (string) ($meta['message'] ?? '');
                                    $ninRef = (string) $report->customer_ref;
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
                                    <td class="py-2 pr-4">{{ optional($report->created_at)->format('d M Y, h:ia') }}</td>
                                    <td class="py-2">
                                        {{-- A failed validation is not a dead end: the
                                             record it could not find is exactly what a
                                             correction starts from, so the number goes
                                             with the customer instead of being typed again. --}}
                                        @if($report->status === 'failed' && preg_match('/^\d{11}$/', $ninRef))
                                            <a href="{{ route('vtu.manual.form', ['service' => 'nin_modification', 'nin' => $ninRef]) }}"
                                               class="text-xs font-bold text-blue-700 underline">
                                                Correct this record
                                            </a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-3 text-slate-500">No validation reports yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    @if(!$paused)
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

            function updatePrice() {
                const key = typeSelect.value;
                if (!key || !prices[key]) {
                    window.setPriceLock('validationPrice', null);
                    return;
                }

                window.setPriceLock('validationPrice', prices[key],
                    'Charged to your wallet when the request is sent.');
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
    @endif
</x-app-layout>
