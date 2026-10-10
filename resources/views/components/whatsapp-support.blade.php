@props(['href'])

<a href="{{ $href }}" target="_blank" rel="noopener noreferrer"
   class="reference-whatsapp" aria-label="Chat with Support on WhatsApp" title="Chat with Support">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M4.6 16.8 3 21l4.4-1.5a9 9 0 1 0-2.8-2.7Z" />
        <path d="M9 7.8c-.4-.2-.7 0-.9.3l-.6 1c-.3.5-.3 1.1 0 1.7 1.1 2.3 3.1 4.3 5.5 5.3.7.3 1.3.2 1.8-.1l1-.7c.3-.2.4-.6.2-.9l-.6-1.2c-.2-.4-.5-.5-.9-.4l-1.4.5a8.7 8.7 0 0 1-2.5-2.5l.5-1.3c.1-.3 0-.7-.3-.9L9 7.8Z" />
    </svg>
    <span class="reference-whatsapp-tooltip" aria-hidden="true">Chat with Support</span>
</a>
