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
     class="app-modal-overlay fixed inset-0 z-[9999] hidden items-center justify-center p-4">
    <div class="app-modal-panel app-notice-modal w-full">
        <div class="flex items-center justify-between border-b border-slate-200/80 px-4 py-3">
            <div>
                <div class="text-base font-extrabold text-slate-950">Notice</div>
                <div class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-600">Important Update</div>
            </div>
            <button id="ninPopupClose"
                    type="button"
                    aria-label="Close notice"
                    class="app-modal-close">
                &times;
            </button>
        </div>

        <div class="space-y-4 p-4">
            <div class="popup-rich-content rounded-2xl border border-slate-200 bg-white/80 px-4 py-3 text-sm leading-6 text-slate-800">
                {!! $messageHtml !== '' ? $messageHtml : nl2br(e((string) $message)) !!}
            </div>

            <div class="app-modal-actions">
                <button id="ninPopupLater"
                        type="button"
                        class="app-modal-btn app-modal-btn-muted">
                    Later
                </button>

                @if(!empty($whatsAppChannel))
                    <a href="{{ $whatsAppChannel }}" target="_blank"
                       class="app-modal-btn app-modal-btn-warm">
                        Join BelovedSubP Channel
                    </a>
                @endif

                <a href="{{ $whatsApp }}" target="_blank"
                   class="app-modal-btn app-modal-btn-success">
                    Chat on WhatsApp
                </a>
            </div>
        </div>
    </div>
</div>
