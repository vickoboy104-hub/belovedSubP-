@props([
    'message' => 'For NIN services (New enrolment, correction, printing, etc.) click the WhatsApp Support button to chat with us instantly.',
    'mode' => 'once',
])

@php
    $whatsApp = setting('whatsapp_link', 'https://wa.me/2348165587119');
    $whatsAppChannel = setting('whatsapp_channel_link', '');
@endphp

<div id="ninPopupOverlay"
     data-popup-mode="{{ $mode }}"
     data-popup-key="nin_popup_seen"
     class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white text-gray-900 dark:bg-[#0F172A] dark:text-gray-100 border border-gray-200 dark:border-white/10 shadow-xl">
        <div class="p-5 border-b border-gray-200 dark:border-white/10 flex items-center justify-between">
            <div class="font-semibold text-lg">Notice</div>
            <button id="ninPopupClose"
                    class="px-3 py-1 rounded-lg border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/10 transition">
                ✕
            </button>
        </div>

        <div class="p-5 space-y-4">
            <p class="text-sm leading-relaxed opacity-90">
                {{ $message }}
            </p>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <button id="ninPopupLater"
                        class="px-4 py-2 rounded-xl border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/10 transition">
                    Later
                </button>

                @if(!empty($whatsAppChannel))
                    <a href="{{ $whatsAppChannel }}" target="_blank"
                       class="px-4 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 text-white font-semibold transition">
                        Join BelovedSubP Channel
                    </a>
                @endif

                <a href="{{ $whatsApp }}" target="_blank"
                   class="px-4 py-2 rounded-xl bg-green-600 text-white font-semibold hover:opacity-90 transition">
                    Chat on WhatsApp
                </a>
            </div>
        </div>
    </div>
</div>
