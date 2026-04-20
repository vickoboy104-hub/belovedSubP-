<x-app-layout>
    @php
        $priceNoRecord = (float) setting('price_nin_validation_no_record', 1000);
        $priceUpdateRecord = (float) setting('price_nin_validation_update_record', 1500);
    @endphp

    <div class="max-w-5xl mx-auto w-full px-4 sm:px-0 space-y-5">
        <div class="rounded-3xl p-5 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">&#128706; NIN Validation</h2>
                    <p class="text-sm text-gray-600 dark:text-white/60 mt-1">
                        Submit NIN validation request. Status updates will appear below.
                    </p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-xl">
                    &#9989;
                </div>
            </div>
        </div>

        @if(session('success'))
            <div class="rounded-2xl p-4 border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-200">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="rounded-2xl p-4 border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-200">
                {{ session('error') }}
            </div>
        @endif
        @if($errors->any())
            <div class="rounded-2xl p-4 border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-200">
                <div class="font-bold">Please fix these errors:</div>
                <ul class="list-disc ml-5 mt-2 text-sm">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <form method="POST" action="{{ route('vtu.nin-validation.submit') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">Validation Category</label>
                    <select id="validation_type" name="validation_type" required
                            class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white">
                        <option value="">-- Select Validation Category --</option>
                        <option value="no_record" @selected(old('validation_type') === 'no_record')>No Record Found</option>
                        <option value="update_record" @selected(old('validation_type') === 'update_record')>Update Record (Name/Phone/Address except DOB)</option>
                    </select>
                    <div id="validationPrice" class="mt-2 text-sm text-red-600 dark:text-red-300 font-semibold"></div>
                </div>

                <div>
                    <label class="text-sm font-bold text-gray-700 dark:text-white/80">NIN Number</label>
                    <input type="text" name="nin" value="{{ old('nin') }}" required maxlength="11"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white dark:bg-black/30 border border-gray-300 dark:border-white/10 text-gray-900 dark:text-white"
                           placeholder="Enter 11-digit NIN">
                </div>

                <div class="text-sm text-red-600 dark:text-red-300">Note: This service is not refundable and cannot be cancelled when sent.</div>

                <button class="px-6 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                    Submit NIN Validation
                </button>
            </form>
        </div>

        <div class="rounded-3xl p-5 sm:p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h3 class="text-lg font-extrabold">Validation Reports</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="text-left py-2 pr-4">NIN</th>
                            <th class="text-left py-2 pr-4">Type</th>
                            <th class="text-left py-2 pr-4">Status</th>
                            <th class="text-left py-2 pr-4">Response</th>
                            <th class="text-left py-2">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports as $report)
                            @php
                                $meta = is_array($report->meta) ? $report->meta : [];
                                $validationType = str_replace('_', ' ', (string) ($meta['validation_type'] ?? ''));
                                $providerMessage = (string) ($meta['message'] ?? '');
                            @endphp
                            <tr class="border-b border-gray-100 dark:border-white/10">
                                <td class="py-2 pr-4">{{ $report->customer_ref }}</td>
                                <td class="py-2 pr-4 capitalize">{{ $validationType ?: '-' }}</td>
                                <td class="py-2 pr-4 uppercase">{{ $report->status }}</td>
                                <td class="py-2 pr-4">{{ $providerMessage ?: '-' }}</td>
                                <td class="py-2">{{ optional($report->created_at)->format('d M Y, h:ia') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-3 text-gray-500 dark:text-white/50">No validation reports yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const prices = {
                no_record: @json(number_format($priceNoRecord, 2)),
                update_record: @json(number_format($priceUpdateRecord, 2)),
            };
            const typeSelect = document.getElementById('validation_type');
            const priceEl = document.getElementById('validationPrice');

            function updatePrice() {
                const key = typeSelect.value;
                if (!key || !prices[key]) {
                    priceEl.textContent = '';
                    return;
                }
                priceEl.textContent = `This service will cost N${prices[key]}`;
            }

            typeSelect.addEventListener('change', updatePrice);
            updatePrice();
        })();
    </script>
</x-app-layout>
