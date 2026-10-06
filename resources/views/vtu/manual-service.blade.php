<x-app-layout>
    @php
        $slug = $definition['slug'];
        $payableKobo = (int) round(((float) $priceNaira) * 100);
        $canAfford = $walletBalanceKobo >= $payableKobo;

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
    @endphp

    <x-page-hero class="reference-shared-banner"
                 :title="$definition['title']"
                 :subtitle="$definition['summary']">
        <a href="{{ route('identity.index') }}" class="reference-hero-action">All identity requests</a>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-3xl space-y-5">
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

        <section class="app-section p-5 sm:p-6">
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="app-record-label">Price</dt>
                    <dd class="app-record-value">&#8358;{{ number_format((float) $priceNaira, 2) }}</dd>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="app-record-label">Time to complete</dt>
                    <dd class="app-record-value">{{ $turnaroundLabel }}</dd>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="app-record-label">Ready by</dt>
                    <dd class="app-record-value">{{ $expectedBy->format('d M Y, h:i A') }}</dd>
                </div>
            </dl>
        </section>

        <section class="app-form-shell space-y-4">
            <form method="POST" action="{{ route('vtu.manual.submit', $slug) }}" class="space-y-4">
                @csrf

                @foreach($definition['fields'] as $field)
                    @php $meta = $inputMeta($field); @endphp
                    <div>
                        <label for="field-{{ $field['name'] }}" class="block text-sm font-bold text-slate-700">
                            {{ $field['label'] }}
                            @if($field['required'])<span class="text-rose-600" aria-hidden="true">*</span>@endif
                        </label>

                        @if($field['type'] === 'select')
                            <select id="field-{{ $field['name'] }}"
                                    name="{{ $field['name'] }}"
                                    @if($field['required']) required @endif
                                    class="input-field mt-2">
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
                                      class="input-field mt-2">{{ old($field['name']) }}</textarea>
                        @else
                            <input id="field-{{ $field['name'] }}"
                                   type="{{ str_contains(implode('|', $field['rules']), 'email') ? 'email' : 'text' }}"
                                   name="{{ $field['name'] }}"
                                   value="{{ old($field['name']) }}"
                                   @if($meta['maxlength']) maxlength="{{ $meta['maxlength'] }}" @endif
                                   @if($meta['numeric']) inputmode="numeric" @endif
                                   @if($field['required']) required @endif
                                   class="input-field mt-2">
                        @endif

                        @if($field['hint'])
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $field['hint'] }}</p>
                        @endif
                    </div>
                @endforeach

                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">
                    &#8358;{{ number_format($payableKobo / 100, 2) }} is taken from your wallet when you submit.
                    {{ $turnaroundLabel }} to complete it, and your result appears on the receipt page.
                    If we cannot complete it, the full amount is refunded to your wallet.
                </div>

                @if(!$canAfford)
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        Your wallet balance is &#8358;{{ number_format($walletBalanceKobo / 100, 2) }}, which is not enough.
                        <a href="{{ route('wallet.fund') }}" class="underline">Fund your wallet</a> to continue.
                    </div>
                @endif

                <button type="submit" class="btn-primary w-full justify-center" @disabled(!$canAfford)>
                    Pay &#8358;{{ number_format($payableKobo / 100, 2) }} and submit
                </button>
            </form>
        </section>
    </div>
</x-app-layout>
