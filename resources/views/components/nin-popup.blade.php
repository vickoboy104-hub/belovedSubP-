@props([
    'message' => 'For NIN services (New enrolment, correction, printing, etc.) click the WhatsApp Support button to chat with us instantly.',
    'mode' => 'once',
    'popupKey' => 'nin_popup_seen',
])

@php
    $whatsApp = setting('whatsapp_link', 'https://wa.me/2348165587119');
    $whatsAppChannel = setting('whatsapp_channel_link', '');
    $messageHtml = sanitize_popup_message_html((string) $message);
@endphp

<div id="ninPopupOverlay"
     data-popup-mode="{{ $mode }}"
     data-popup-key="{{ $popupKey }}"
     class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-lg overflow-hidden rounded-[28px] border border-[#d9cab1] bg-[linear-gradient(180deg,#fbf6ea_0%,#f2eadb_100%)] text-slate-900 shadow-[0_22px_55px_rgba(18,31,56,0.24)]">
        <div class="flex items-center justify-between border-b border-[#d8ccb7] px-5 py-5">
            <div>
                <div class="text-lg font-extrabold">Notice</div>
                <div class="mt-1 text-xs font-medium uppercase tracking-[0.18em] text-slate-500">Important Update</div>
            </div>
            <button id="ninPopupClose"
                    class="flex h-10 w-10 items-center justify-center rounded-2xl border border-[#d8ccb7] bg-[#efe4cf] text-slate-700 transition hover:bg-[#e7d8bb]">
                ×
            </button>
        </div>

        <div class="space-y-5 p-5">
            <div class="popup-rich-content rounded-[22px] border border-[#e0d4c2] bg-[#fff9ef] px-4 py-4 text-sm leading-7 text-slate-700">
                {!! $messageHtml !== '' ? $messageHtml : nl2br(e((string) $message)) !!}
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <button id="ninPopupLater"
                        class="rounded-2xl border border-[#d8ccb7] bg-[#efe4cf] px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-[#e7d8bb]">
                    Later
                </button>

                @if(!empty($whatsAppChannel))
                    <a href="{{ $whatsAppChannel }}" target="_blank"
                       class="rounded-2xl bg-[#b86a24] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#9e5819]">
                        Join BelovedSubP Channel
                    </a>
                @endif

                <a href="{{ $whatsApp }}" target="_blank"
                   class="rounded-2xl bg-[#1f8f52] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#187242]">
                    Chat on WhatsApp
                </a>
            </div>
        </div>
    </div>
</div>
