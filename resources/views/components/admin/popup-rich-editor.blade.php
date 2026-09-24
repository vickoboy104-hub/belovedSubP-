@props([
    'name',
    'id' => null,
    'value' => '',
    'placeholder' => 'Type popup message here...',
    'helper' => null,
])

@php
    $inputId = $id ?: $name;
    $editorHtml = sanitize_popup_message_html((string) $value);
@endphp

<textarea name="{{ $name }}" id="{{ $inputId }}" class="hidden">{{ (string) $value }}</textarea>

<div class="mt-1 rounded-2xl border border-gray-200 bg-white shadow-sm" data-rich-editor-root data-editor-input="{{ $inputId }}">
    <div class="flex flex-wrap gap-2 border-b border-gray-200 px-3 py-3">
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="bold">Bold</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="italic">Italic</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="underline">Underline</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="strikeThrough">Strike</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="insertUnorderedList">Bullets</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="insertOrderedList">Numbers</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="justifyLeft">Left</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="justifyCenter">Center</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="justifyRight">Right</button>
        <button type="button" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700" data-rich-editor-action="removeFormat">Clear</button>
    </div>

    <div contenteditable="true"
         data-rich-editor-surface
         data-placeholder="{{ $placeholder }}"
         class="popup-rich-editor-surface min-h-[180px] w-full rounded-b-2xl px-4 py-4 text-sm leading-7 text-slate-800 focus:outline-none">{!! $editorHtml !!}</div>
</div>

@if($helper)
    <div class="mt-2 text-xs text-white/50">{{ $helper }}</div>
@endif
