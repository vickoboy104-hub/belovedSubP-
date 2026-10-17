@props([
    'definition',
    'action',
    'input' => [],
    'branchPrices' => [],
    'basePriceKobo' => 0,
    'balanceKobo' => 0,
    'costText' => null,
    'cta' => null,
    'turnaroundLabel' => '',
    'expectedBy' => null,
    'consent' => ['clauses' => []],
    'backUrl' => null,
])

{{--
    One form, any service. The shape comes from the stored definition, so a
    question that only exists after a particular answer, a dropdown whose menu
    depends on the question above it, and the rate that answer selects are all
    data the owner edits in Admin rather than markup written page by page.

    The server renders the branch the customer is already standing on - the
    fields they cannot see are absent and disabled, the dropdowns hold only the
    options their own answer allows, and the quoted figure is the price of that
    branch. The browser then moves the same branch without a page load, using
    the map at the foot of this form. What it shows is for the customer's eye
    only: the money is taken from the definition on the server, never from
    anything this page sends back.
--}}
@php
    $schema = \App\Support\ServiceForm\Schema::normalize($definition);
    $fields = \App\Support\ServiceForm\Schema::fields($schema);
    $notes = (array) ($schema['notes'] ?? []);

    // The answers the page is standing on: what the customer just submitted if
    // the form came back with errors, otherwise what the page was handed. The
    // branch is drawn from these, so a rejected submission returns to the same
    // question they were answering rather than to the top of the form.
    $answers = [];
    foreach ($fields as $field) {
        $answers[$field['name']] = trim((string) old($field['name'], $input[$field['name']] ?? ''));
    }

    // The deepest priced answer wins, which is the same walk the server makes
    // when it decides what to charge, so this line and the debit agree. Only an
    // answer on the branch the customer is standing on counts.
    $chosenKobo = null;
    foreach ($fields as $field) {
        $value = $answers[$field['name']] ?? '';
        if ($value !== '' && \App\Support\ServiceForm\Schema::isVisible($schema, $field, $answers)
            && isset($branchPrices[$field['name']][$value])) {
            $chosenKobo = (int) $branchPrices[$field['name']][$value];
        }
    }

    $config = \App\Support\ServiceForm\Schema::clientConfig($schema, $branchPrices, (int) $basePriceKobo);

    // Before a choice is made the page quotes the lowest rate any of its
    // choices carries. Its own base rate is the price of the whole service and
    // on a tiered service nothing on this page will charge it.
    $payableKobo = $chosenKobo ?? (int) $config['floorPrice'];
    $canAfford = $balanceKobo >= $payableKobo;
    $priceLabel = number_format($payableKobo / 100, 2);

    $costTemplate = str_replace(
        '{{price}}',
        '__PRICE__',
        (string) ($costText ?? $schema['cost_text'] ?? '* This service will cost you ₦{{price}}'),
    );

    // Until the job behind the choices is known the page cannot claim one
    // figure, so it names the whole span instead of its cheapest corner. The
    // span is worked out once, here, and handed to the browser in the same map.
    $span = $config['span'];
    $rangeText = $span === null
        ? null
        : number_format($span[0] / 100, 2).' – ₦'.number_format($span[1] / 100, 2);

    // A figure of its own belongs to a chosen branch. The span and the
    // invitation belong to the page before one, and never to both at once.
    $standing = $chosenKobo !== null || $rangeText === null;
    $costLine = str_replace(
        '{{price}}',
        $standing ? $priceLabel : $rangeText,
        (string) ($costText ?? $schema['cost_text'] ?? '* This service will cost you ₦{{price}}'),
    ).($standing ? '' : ' '.\App\Support\ServiceForm\Schema::INVITATION);

    // A document question is usually behind a choice, and the browser opens that
    // choice without asking the server again. So the encoding is decided by what
    // this form can ever show, not by the branch it happens to be standing on -
    // otherwise the file the customer picks simply never leaves their device.
    $hasFile = collect($fields)->contains(
        fn (array $field): bool => ($field['type'] ?? '') === 'file',
    );

    // Field hints drive the input, so an 11-digit NIN behaves like one.
    $inputMeta = function (array $field): array {
        $rules = implode('|', (array) $field['rules']);
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
            'email' => str_contains($rules, 'email'),
        ];
    };

    // The provider writes a label as an instruction ("Enter the NIN Number") and
    // its dropdowns open on the same instruction, so the blank option reuses the
    // label rather than inventing a third phrasing.
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
@endphp

<form method="POST"
      action="{{ $action }}"
      class="service-form-body"
      data-service-form
      @if($hasFile) enctype="multipart/form-data" @endif
      @if(($consent['clauses'] ?? []) !== []) data-consent-form @endif
    {{ $attributes->only('id') }}>
    @csrf
    <x-purchase-intent />

    @foreach($fields as $field)
        @php
            $visible = \App\Support\ServiceForm\Schema::isVisible($schema, $field, $answers);
            $meta = $inputMeta($field);
            $ph = $placeholderText($field);
            $value = $answers[$field['name']] ?? '';
            $control = 'field-'.$field['name'];
            // A question only has to be answered while it is on the screen.
            $required = $field['required'] && $visible;
            // Written as a whole attribute so the hidden fields of an unchosen
            // branch read exactly like the browser expects to find them.
            $offBranch = $visible ? '' : ' hidden';
        @endphp

        <div class="service-form-field" data-form-field="{{ $field['name'] }}"{!! $offBranch !!}>
            @if(($field['type'] ?? '') === 'checkbox')
                <label for="{{ $control }}" class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="checkbox"
                           id="{{ $control }}"
                           name="{{ $field['name'] }}"
                           value="1"
                           @if($required) required @endif
                           @checked($value === '1')
                           class="mt-1 h-4 w-4 rounded border-slate-400 text-blue-700">
                    <span>{{ $field['label'] }}</span>
                </label>
            @else
                <label for="{{ $control }}" class="service-form-label">
                    {{ $field['label'] }}
                    @if($required)<span class="service-form-required" aria-hidden="true">*</span>@endif
                </label>
            @endif

            @if($field['type'] === 'select')
                <select id="{{ $control }}"
                        name="{{ $field['name'] }}"
                        @if($required) required @endif
                        @unless($visible) disabled @endunless
                        class="input-field">
                    <option value="">{{ $ph }}</option>
                    @foreach(\App\Support\ServiceForm\Schema::optionsFor($field, $answers) as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected($value === (string) $optionValue)>{{ $optionLabel }}</option>
                    @endforeach
                </select>
            @elseif($field['type'] === 'textarea')
                <textarea id="{{ $control }}"
                          name="{{ $field['name'] }}"
                          rows="3"
                          @if($ph !== '') placeholder="{{ $ph }}" @endif
                          @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                          @if($required) required @endif
                          @unless($visible) disabled @endunless
                          class="input-field">{{ $value }}</textarea>
            @elseif($field['type'] === 'file')
                <input type="file"
                       id="{{ $control }}"
                       name="{{ $field['name'] }}"
                       @if($field['accept'] ?? null) accept="{{ $field['accept'] }}" @endif
                       @if($required) required @endif
                       @unless($visible) disabled @endunless
                       class="input-field">
            @elseif(in_array($field['type'], ['number', 'date'], true))
                <input id="{{ $control }}"
                       type="{{ $field['type'] }}"
                       name="{{ $field['name'] }}"
                       value="{{ $value }}"
                       @if($ph !== '') placeholder="{{ $ph }}" @endif
                       @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                       @if($required) required @endif
                       @unless($visible) disabled @endunless
                       class="input-field">
            @else
                <input id="{{ $control }}"
                       type="{{ $meta['email'] ? 'email' : 'text' }}"
                       name="{{ $field['name'] }}"
                       value="{{ $value }}"
                       @if($ph !== '') placeholder="{{ $ph }}" @endif
                       @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                       @if($meta['numeric']) inputmode="numeric" @endif
                       @if($required) required @endif
                       @unless($visible) disabled @endunless
                       class="input-field">
            @endif

            @if($field['hint'])
                <p class="service-form-hint">{{ $field['hint'] }}</p>
            @endif
        </div>
    @endforeach

    {{-- The provider states the price as a red line under the fields, once the
         job itself has been chosen, rather than as a tile. The number on that
         line is always the admin's own setting. --}}
    <p class="service-form-cost"
       id="serviceCostLine"
       data-cost-template="{{ $costTemplate }}">{{ $costLine }}</p>

    @foreach($notes as $index => $note)
        <p class="service-form-hint"
           data-note="{{ $index }}"
           @unless($note['show_if'] === null || \App\Support\ServiceForm\Schema::isVisible($schema, ['show_if' => $note['show_if']], $answers)) hidden @endunless>{{ $note['text'] }}</p>
    @endforeach

    <div class="service-form-alert service-form-alert-warning"
         id="servicePaymentNotice"
         data-balance="{{ $balanceKobo }}">
        {{-- Before an option is picked the page cannot promise one figure: the
             service's own standing rate is not what any of its choices charges.
             The span keeps the shape the browser updates on every change. --}}
        &#8358;<span id="servicePayableAmount">{{ $standing ? $priceLabel : $rangeText }}</span> is deducted from your wallet the moment you press {{ $cta ?? $schema['cta'] ?? 'Submit' }}.
        We take {{ $turnaroundLabel }} to complete it, so the result should be ready by
        {{ $expectedBy ? $expectedBy->format('d M Y, h:i A') : 'the stated time' }}. It appears on your receipt page and stays
        there, so keep the receipt after the work is done. If we cannot complete the job, the
        full amount is returned to your wallet.
    </div>

    @if($span !== null)
        {{-- The wallet may cover the cheapest job on this page but not the one
             just picked, so the shortfall is announced with the number that is
             actually about to be charged. --}}
        <div class="service-form-alert service-form-alert-danger" id="serviceTierShortfall" hidden>
            Your wallet balance is &#8358;{{ number_format($balanceKobo / 100, 2) }}, which is not enough for this option.
            <a href="{{ route('wallet.fund') }}" class="underline">Fund your wallet</a> to continue.
        </div>
    @elseif(!$canAfford)
        <div class="service-form-alert service-form-alert-danger">
            Your wallet balance is &#8358;{{ number_format($balanceKobo / 100, 2) }}, which is not enough.
            <a href="{{ route('wallet.fund') }}" class="underline">Fund your wallet</a> to continue.
        </div>
    @endif

    @if(($consent['clauses'] ?? []) !== [])
        {{-- The gate is only the front of the terms; this tick is the record the
             server actually checks, and it is what proves on the order page that
             the customer read them. --}}
        <label class="service-form-field flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="consent_agreed" value="1" required
                   class="mt-1 h-4 w-4 rounded border-slate-400 text-blue-700">
            <span>
                I agree to the terms above and authorise BelovedSubP to carry out this request
                for {{ $schema['title'] ?? '' }}.
            </span>
        </label>
    @endif

    <div class="service-form-actions">
        <a href="{{ $backUrl ?? route('identity.index') }}" class="reference-quiet-button">Previous</a>
        <button type="submit" class="btn-primary" @disabled(!$canAfford)>{{ $cta ?? $schema['cta'] ?? 'Submit' }}</button>
    </div>

    {{-- The branch map, next to the form it describes. These prices are for the
         customer's eye: the debit is worked out from the stored definition when
         this form arrives, never from anything sent back here. --}}
    <script type="application/json" data-service-form-config>@json($config)</script>
</form>
