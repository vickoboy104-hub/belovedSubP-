<x-app-layout>
    @php
        $slug = $definition['slug'];
        $fields = \App\Support\ServiceForm\Schema::fields($definition);
        $notes = (array) ($definition['notes'] ?? []);

        // Some services rewrite or reprint the identity record itself, so the terms
        // stand in front of the form rather than in a footnote under it.
        $consent = app(\App\Services\ManualFulfilmentService::class)->consent($slug);
        $prefill = (array) ($prefill ?? []);

        // A paused page still quotes, because the price is the one thing the
        // customer can act on while the form is closed. With several rates on the
        // page and no way to choose one, the honest figure is the span - the same
        // span the form itself quotes before a choice.
        $span = \App\Support\ServiceForm\Schema::priceSpan((array) ($branchPrices ?? []));
        $baseLabel = number_format(((int) ($basePriceKobo ?? 0)) / 100, 2);
        $pausedCostLine = str_replace(
            '{{price}}',
            $span === null
                ? $baseLabel
                : number_format($span[0] / 100, 2).' – ₦'.number_format($span[1] / 100, 2),
            (string) ($definition['cost_text'] ?? '* This service will cost you ₦{{price}}'),
        );

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

        foreach ($fields as $field) {
            if ($field['name'] === 'notes' || ($field['type'] ?? '') === 'file') {
                continue;
            }
            $columns[] = ['key' => 'field:'.$field['name'], 'label' => $field['column'] ?? $field['label']];
        }

        $rows = [];
        foreach ($history as $o) {
            $meta = (array) ($o->meta ?? []);
            $submitted = (array) ($meta['submitted'] ?? []);
            $customer = $o->customerStatus();
            $hasResult = trim((string) ($meta['result_text'] ?? '')) !== ''
                || trim((string) ($meta['result_file'] ?? '')) !== '';

            $cells = [
                'reference' => (string) ($o->customer_ref ?? ''),
                'amount' => '₦'.number_format(((int) $o->amount) / 100, 2),
                'result' => $hasResult ? 'Ready' : '',
                // This table's own word for an answered request, but the waiting
                // half of it has to say whether anybody has started.
                'status' => [
                    'value' => $customer['key'] === 'success' ? 'Completed' : $customer['label'],
                    'tone' => $customer['tone'],
                ],
                'date' => optional($o->created_at)->format('Y-m-d, h:i:s A') ?? '',
            ];

            foreach ($fields as $field) {
                if ($field['name'] === 'notes' || ($field['type'] ?? '') === 'file') {
                    continue;
                }
                $cells['field:'.$field['name']] = \App\Support\ServiceForm\Schema::labelFor(
                    $definition,
                    (string) $field['name'],
                    (string) ($submitted[$field['name']] ?? ''),
                );
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

                @foreach($notes as $note)
                    <p class="service-form-hint">{{ $note['text'] }}</p>
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

            {{-- The questions, the branches and the rates all come from the service
                 definition, so a service that gains a second dropdown needs the
                 definition edited in Admin and not this page touched. --}}
            <x-service-form :definition="$definition"
                            :action="route('vtu.manual.submit', $slug)"
                            :input="$prefill"
                            :branch-prices="$branchPrices"
                            :base-price-kobo="$basePriceKobo"
                            :balance-kobo="$walletBalanceKobo"
                            :turnaround-label="$turnaroundLabel"
                            :expected-by="$expectedBy"
                            :consent="$consent" />
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
</x-app-layout>
