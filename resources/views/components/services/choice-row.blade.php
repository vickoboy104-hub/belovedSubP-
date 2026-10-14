@php
    // The rate a choice selects is named here and numbered on the Settings page,
    // so this only ever holds the key. Its input is renamed as the owner retypes
    // the value beside it, which keeps the two on the same answer.
    $value = (string) ($choice['value'] ?? '');
@endphp

<div class="flex flex-wrap items-end gap-2" data-choice-row>
    <div class="min-w-0 flex-1">
        <input type="text" name="{{ $prefix }}[options][{{ $position }}][value]"
               data-indexed="{{ $prefix }}[options][__C__][value]" maxlength="60"
               class="input-field font-mono text-xs" data-choice-value
               value="{{ $value }}" placeholder="get_nin_slip">
    </div>
    <div class="min-w-0 flex-1" style="flex:1.6 1 0%">
        <input type="text" name="{{ $prefix }}[options][{{ $position }}][label]"
               data-indexed="{{ $prefix }}[options][__C__][label]" maxlength="160"
               class="input-field text-xs"
               value="{{ $choice['label'] ?? '' }}" placeholder="TO GET NIN SLIP">
    </div>
    <div class="min-w-0 flex-1">
        <select name="{{ $prefix }}[prices][{{ $value }}]" class="input-field text-xs" data-choice-price>
            <option value="">Same as the service</option>
            @foreach($rates as $key => $rate)
                <option value="{{ $key }}" @selected($price === $key)>{{ $rate['label'] ?? $key }}</option>
            @endforeach
        </select>
    </div>
    <button type="button" class="text-xs font-bold text-red-600 underline" data-remove-row>Remove</button>
</div>
