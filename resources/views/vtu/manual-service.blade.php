<x-app-layout>
    @php
        $slug = $definition['slug'];
        $payableKobo = (int) round(((float) $priceNaira) * 100);
        $canAfford = $walletBalanceKobo >= $payableKobo;
        $priceLabel = number_format($payableKobo / 100, 2);

        // Some services rewrite or reprint the identity record itself, so the terms
        // stand in front of the form rather than in a footnote under it.
        $consent = app(\App\Services\ManualFulfilmentService::class)->consent($slug);
        $prefill = (array) ($prefill ?? []);

        // Several services are really a list of different jobs behind one select,
        // each with its own rate. The provider names that rate the moment the
        // option is chosen, so the same numbers travel to the browser and update
        // the price line and the deduction warning without another page load.
        $tierPriceLabels = [];
        foreach ((array) ($tierPrices ?? []) as $option => $naira) {
            $tierPriceLabels[$option] = number_format((float) $naira, 2);
        }
        $tierAmounts = [];
        foreach ((array) ($tierPrices ?? []) as $option => $naira) {
            $tierAmounts[$option] = (int) round(((float) $naira) * 100);
        }

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

        // The provider writes a label as an instruction ("Enter the NIN Number")
        // and its dropdowns open on the same instruction, so the blank option
        // reuses the label rather than inventing a third phrasing. A plain input
        // stays empty unless it has an example worth showing.
        $placeholderText = function (array $field): string {
            if (!empty($field['placeholder'])) {
                return (string) $field['placeholder'];
            }
            if (($field['type'] ?? 'text') !== 'select') {
                return '';
            }
            if (preg_match('/^(Select|Choose)\b/i', $field['label'])) {
                return $field['label'];
            }

            return 'Select '.strtolower($field['label']);
        };

        $costLine = str_replace(
            '{{price}}',
            $priceLabel,
            (string) ($definition['cost_text'] ?? '* This service will cost you ₦{{price}}'),
        );

        // The same line, with the amount left as a marker the browser fills in
        // when the customer changes the option that decides the job.
        $costTemplate = str_replace(
            '{{price}}',
            '__PRICE__',
            (string) ($definition['cost_text'] ?? '* This service will cost you ₦{{price}}'),
        );

        // Before an option is chosen the page cannot claim one price, because the
        // job behind the select is not known yet. The provider only ever states a
        // figure once the selection is made, so the unanswered state names the
        // whole span instead of quoting its cheapest corner.
        if (count(array_unique(array_values($tierAmounts))) > 1) {
            $costLine = str_replace(
                '{{price}}',
                number_format(min($tierAmounts) / 100, 2).' – ₦'.number_format(max($tierAmounts) / 100, 2),
                (string) ($definition['cost_text'] ?? '* This service will cost you ₦{{price}}'),
            );
        }

        // A paused page still quotes, because the price is the one thing the
        // customer can act on while the form is closed. It must not tell them to
        // pick an option that is not there, so the invitation is only added when
        // the form is actually open.
        $pausedCostLine = $costLine;
        if (count(array_unique(array_values($tierAmounts))) > 1) {
            $costLine .= ' — choose what you need done and the price for exactly that appears here.';
        }

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
            $columns[] = ['key' => 'field:'.$field['name'], 'label' => $field['column'] ?? $field['label']];
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
        <section class="service-form-card" @if($paused) data-service-paused @elseif($consent['clauses'] !== []) data-consent-gate @endif>
            <header class="service-form-head">
                <span class="service-form-icon" aria-hidden="true">{{ $definition['icon'] ?? '▣' }}</span>
                <h2 class="service-form-title">{{ $definition['title'] }}</h2>
            </header>

            @if($paused)
                {{-- The provider's own paused page keeps the price and the customer's
                     earlier jobs on screen and removes only the form: the work is
                     being done by hand, so the page still has to answer "what will
                     this cost" and "did my last one finish". --}}
                <x-service-outage-notice :down="[$slug => $definition['title']]" :message="$pausedNotice" />

                <p class="service-form-cost mt-3">{{ $pausedCostLine }}</p>

                @foreach($definition['notices'] ?? [] as $notice)
                    <p class="service-form-hint">{{ $notice }}</p>
                @endforeach

                <p class="service-form-hint">
                    Nothing is charged while this service is paused, and no request can be sent.
                    <a href="{{ route('identity.index') }}" class="underline">Browse the other identity services</a>
                    in the meantime.
                </p>
            @else

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

            @if($consent['clauses'] !== [])
                <section data-consent-terms class="service-form-alert">
                    <h3 class="text-base font-extrabold">{{ $consent['title'] }}</h3>
                    @if($consent['intro'] !== '')
                        <p class="mt-1 text-sm text-slate-600">{{ $consent['intro'] }}</p>
                    @endif
                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-700">
                        @foreach($consent['clauses'] as $clause)
                            <li>{{ $clause }}</li>
                        @endforeach
                    </ol>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" data-consent-agree class="btn-primary">
                            I agree, show me the form
                        </button>
                        <a href="{{ route('identity.index') }}" class="reference-quiet-button">
                            Not agreed
                        </a>
                    </div>
                </section>
            @endif

            <form method="POST" action="{{ route('vtu.manual.submit', $slug) }}" class="service-form-body"
                  @if($consent['clauses'] !== []) data-consent-form @endif>
                @csrf

                @foreach($definition['fields'] as $field)
                    @php $meta = $inputMeta($field); $ph = $placeholderText($field); @endphp
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
                                <option value="">{{ $ph }}</option>
                                @foreach($field['options'] as $value => $label)
                                    <option value="{{ $value }}" @selected((string) old($field['name'], $prefill[$field['name']] ?? '') === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif($field['type'] === 'textarea')
                            <textarea id="field-{{ $field['name'] }}"
                                      name="{{ $field['name'] }}"
                                      rows="3"
                                      @if($ph !== '') placeholder="{{ $ph }}" @endif
                                      @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                                      @if($field['required']) required @endif
                                      class="input-field">{{ old($field['name'], $prefill[$field['name']] ?? '') }}</textarea>
                        @else
                            <input id="field-{{ $field['name'] }}"
                                   type="{{ str_contains(implode('|', $field['rules']), 'email') ? 'email' : 'text' }}"
                                   name="{{ $field['name'] }}"
                                   value="{{ old($field['name'], $prefill[$field['name']] ?? '') }}"
                                   @if($ph !== '') placeholder="{{ $ph }}" @endif
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
                     once the job itself has been chosen, rather than as a tile.
                     The number on that line is always the admin's own setting. --}}
                <p class="service-form-cost"
                   id="serviceCostLine"
                   data-cost-template="{{ $costTemplate }}">{{ $costLine }}</p>

                @foreach($definition['notices'] ?? [] as $notice)
                    <p class="service-form-hint">{{ $notice }}</p>
                @endforeach

                <div class="service-form-alert service-form-alert-warning"
                     @if($tierAmounts !== []) data-tier-field="{{ $tierField }}" data-tier-amounts="{{ json_encode($tierAmounts) }}" @endif
                     data-balance="{{ $walletBalanceKobo }}">
                    &#8358;<span id="servicePayableAmount">{{ $priceLabel }}</span> is deducted from your wallet the moment you press {{ $definition['cta'] ?? 'Submit' }}.
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
                @elseif($tierAmounts !== [])
                    {{-- The wallet may cover the cheapest job on this page but not
                         the one just picked, so the shortfall is announced with the
                         number that is actually about to be charged. --}}
                    <div class="service-form-alert service-form-alert-danger" id="serviceTierShortfall" hidden>
                        Your wallet balance is &#8358;{{ number_format($walletBalanceKobo / 100, 2) }}, which is not enough for this option.
                        <a href="{{ route('wallet.fund') }}" class="underline">Fund your wallet</a> to continue.
                    </div>
                @endif

                @if($consent['clauses'] !== [])
                    {{-- The gate is only the front of the terms; this tick is the
                         record the server actually checks, and it is what proves on
                         the order page that the customer read them. --}}
                    <label class="service-form-field flex items-start gap-3 text-sm text-slate-700">
                        <input type="checkbox" name="consent_agreed" value="1" required
                               class="mt-1 h-4 w-4 rounded border-slate-400 text-blue-700">
                        <span>
                            I agree to the terms above and authorise BelovedSubP to carry out this request
                            for {{ $definition['title'] }}.
                        </span>
                    </label>
                @endif

                <div class="service-form-actions">
                    <a href="{{ route('identity.index') }}" class="reference-quiet-button">Previous</a>
                    <button type="submit" class="btn-primary" @disabled(!$canAfford)>{{ $definition['cta'] ?? 'Submit' }}</button>
                </div>
            </form>
            @endif
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

    @if(!$paused && $tierAmounts !== [])
        <script>
            (function () {
                const picker = document.getElementById('field-{{ $tierField }}');
                const costLine = document.getElementById('serviceCostLine');
                const amounts = JSON.parse(
                    document.querySelector('.service-form-alert-warning[data-tier-amounts]').dataset.tierAmounts,
                );
                const payable = document.getElementById('servicePayableAmount');
                const shortfall = document.getElementById('serviceTierShortfall');
                const submit = document.querySelector('.service-form-actions button[type=submit]');
                const balance = Number(document.querySelector('.service-form-alert-warning[data-tier-amounts]').dataset.balance || 0);
                const template = costLine.dataset.costTemplate;

                if (!picker || !amounts || typeof template !== 'string') {
                    return;
                }

                const naira = (kobo) => (kobo / 100).toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                function applySelection() {
                    const kobo = amounts[String(picker.value || '')];
                    if (kobo === undefined) {
                        return;
                    }

                    costLine.textContent = template.replace('__PRICE__', naira(kobo));
                    payable.textContent = naira(kobo);

                    const affordable = balance >= kobo;
                    if (shortfall) {
                        shortfall.hidden = affordable;
                    }
                    if (submit) {
                        submit.disabled = !affordable;
                    }
                }

                picker.addEventListener('change', applySelection);
                applySelection();
            }());
        </script>
    @endif
</x-app-layout>
