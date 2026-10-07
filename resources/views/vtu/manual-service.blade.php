<x-app-layout>
    @php
        $slug = $definition['slug'];
        $payableKobo = (int) round(((float) $priceNaira) * 100);
        $canAfford = $walletBalanceKobo >= $payableKobo;
        $priceLabel = number_format($payableKobo / 100, 2);

        // Field hints drive the input, so an 11-digit NIN behaves like one.
        $inputMeta = function (array $field): array {
            $rules = implode('|', $field['rules']);
            $maxlength = null;
            if (preg_match('/\bmax:(\d+)\b/', $rules, $m)) {
                $maxlength = (int) $m[1];
            }
            if (preg_match('/\bsize:(\d+)\b/', $rules, $m)) {
                $maxlength = (int) $m[1];
            }

            return [
                'maxlength' => $maxlength,
                'numeric' => str_contains($rules, 'digits:') || str_contains($rules, 'numeric') || str_contains($rules, 'integer'),
            ];
        };

        // The provider prints the history of that same job under its form, so the
        // customer can see whether an earlier tracking ID already went through
        // without leaving the page they are filling.
        $columns = [
            ['key' => 'reference', 'label' => 'Request'],
            ['key' => 'amount', 'label' => 'Amount'],
            ['key' => 'result', 'label' => 'Response'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'date', 'label' => 'Date'],
        ];

        foreach ($definition['fields'] as $field) {
            if ($field['name'] === 'notes') {
                continue;
            }
            $columns[] = ['key' => 'field:'.$field['name'], 'label' => $field['label']];
        }

        $rows = [];
        foreach ($history as $o) {
            $meta = (array) ($o->meta ?? []);
            $submitted = (array) ($meta['submitted'] ?? []);
            $hasResult = trim((string) ($meta['result_text'] ?? '')) !== ''
                || trim((string) ($meta['result_file'] ?? '')) !== '';

            $cells = [
                'reference' => (string) ($o->customer_ref ?? ''),
                'amount' => '₦'.number_format(((int) $o->amount) / 100, 2),
                'result' => $hasResult ? 'Ready' : '',
                'status' => match ((string) ($o->status ?? 'pending')) {
                    'success' => ['value' => 'Completed', 'tone' => 'success'],
                    'failed' => ['value' => 'Failed', 'tone' => 'danger'],
                    default => ['value' => 'Pending', 'tone' => 'warning'],
                },
                'date' => optional($o->created_at)->format('Y-m-d, h:i:s A') ?? '',
            ];

            foreach ($definition['fields'] as $field) {
                if ($field['name'] === 'notes') {
                    continue;
                }
                $value = (string) ($submitted[$field['name']] ?? '');
                if ($value !== '' && isset($field['options']) && is_array($field['options'])) {
                    $value = (string) ($field['options'][$value] ?? $value);
                }
                $cells['field:'.$field['name']] = $value;
            }

            $rows[] = [
                'id' => (int) $o->id,
                'cells' => $cells,
                'action' => ['label' => 'View receipt', 'href' => route('vtu.receipt', $o->id)],
            ];
        }
    @endphp

    <x-page-hero class="reference-shared-banner"
                 :title="$definition['title']"
                 :subtitle="$definition['summary']">
        <a href="{{ route('identity.index') }}" class="reference-hero-action">All identity requests</a>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-3xl space-y-5">
        <section class="service-form-card">
            <header class="service-form-head">
                <span class="service-form-icon" aria-hidden="true">{{ $definition['icon'] ?? '▣' }}</span>
                <h2 class="service-form-title">{{ $definition['title'] }}</h2>
            </header>

            @if($errors->any())
                <div class="service-form-alert service-form-alert-danger">
                    <div class="font-bold">Please fix these errors:</div>
                    <ul class="mt-2 list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('vtu.manual.submit', $slug) }}" class="service-form-body">
                @csrf

                @foreach($definition['fields'] as $field)
                    @php $meta = $inputMeta($field); @endphp
                    <div class="service-form-field">
                        <label for="field-{{ $field['name'] }}" class="service-form-label">
                            {{ $field['label'] }}
                            @if($field['required'])<span class="service-form-required" aria-hidden="true">*</span>@endif
                        </label>

                        @if($field['type'] === 'select')
                            <select id="field-{{ $field['name'] }}"
                                    name="{{ $field['name'] }}"
                                    @if($field['required']) required @endif
                                    class="input-field">
                                <option value="">Select {{ strtolower($field['label']) }}</option>
                                @foreach($field['options'] as $value => $label)
                                    <option value="{{ $value }}" @selected((string) old($field['name']) === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif($field['type'] === 'textarea')
                            <textarea id="field-{{ $field['name'] }}"
                                      name="{{ $field['name'] }}"
                                      rows="3"
                                      @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                                      @if($field['required']) required @endif
                                      class="input-field">{{ old($field['name']) }}</textarea>
                        @else
                            <input id="field-{{ $field['name'] }}"
                                   type="{{ str_contains(implode('|', $field['rules']), 'email') ? 'email' : 'text' }}"
                                   name="{{ $field['name'] }}"
                                   value="{{ old($field['name']) }}"
                                   @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                                   @if($meta['numeric']) inputmode="numeric" @endif
                                   @if($field['required']) required @endif
                                   class="input-field">
                        @endif

                        @if($field['hint'])
                            <p class="service-form-hint">{{ $field['hint'] }}</p>
                        @endif
                    </div>
                @endforeach

                {{-- The provider states the price as a red line under the fields,
                     once the job itself has been chosen, rather than as a tile. --}}
                <p class="service-form-cost">* This service will cost you &#8358;{{ $priceLabel }}</p>

                <div class="service-form-alert service-form-alert-warning">
                    &#8358;{{ $priceLabel }} is deducted from your wallet the moment you press Submit.
                    We take {{ $turnaroundLabel }} to complete it, so the result should be ready by
                    {{ $expectedBy->format('d M Y, h:i A') }}. It appears on your receipt page and stays
                    there, so keep the receipt after the work is done. If we cannot complete the job, the
                    full amount is returned to your wallet.
                </div>

                @if(!$canAfford)
                    <div class="service-form-alert service-form-alert-danger">
                        Your wallet balance is &#8358;{{ number_format($walletBalanceKobo / 100, 2) }}, which is not enough.
                        <a href="{{ route('wallet.fund') }}" class="underline">Fund your wallet</a> to continue.
                    </div>
                @endif

                <div class="service-form-actions">
                    <a href="{{ route('identity.index') }}" class="reference-quiet-button">Previous</a>
                    <button type="submit" class="btn-primary" @disabled(!$canAfford)>Submit</button>
                </div>
            </form>
        </section>

        @if($rows !== [])
            <section class="app-section p-4 sm:p-6">
                <h2 class="reference-section-title">{{ $definition['title'] }} history</h2>
                <x-records-table
                    :columns="$columns"
                    :rows="$rows"
                    :compact="['reference', 'status', 'date']"
                    :total="$historyTotal"
                    :searchable="false"
                    empty-text="You have not ordered this service yet."
                />
            </section>
        @endif
    </div>
</x-app-layout>
