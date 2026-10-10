@props([
    'id',
    'label' => 'Service price',
])

{{-- The price is a locked field, not a sentence: it only appears once the choice
     that fixes it has been made, so no figure can be misread as an estimate.
     window.setPriceLock(id, amount, caption) fills and reveals it from app.js. --}}
<div {{ $attributes->merge(['class' => 'hidden']) }} data-price-lock>
    <label class="block text-sm font-bold text-slate-700" for="{{ $id }}">{{ $label }}</label>
    <input type="text" id="{{ $id }}" disabled
           class="input-field mt-1 cursor-not-allowed bg-slate-100 font-extrabold text-slate-700"
           value="">
    <p data-price-lock-caption class="mt-1 text-xs text-slate-500"></p>
</div>
