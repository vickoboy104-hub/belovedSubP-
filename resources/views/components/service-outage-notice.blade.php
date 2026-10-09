@props([
    'down' => [],
    'message' => null,
    'live' => false,
])

@php
    $down = is_array($down) ? $down : [];
    $notice = trim((string) ($message ?? app(\App\Support\ServiceAvailability::class)->notice($down)));
    $silent = $down === [] || $notice === '';

    /* The browser is allowed to add services to this strip after it has asked
       the provider live, so the page can keep the shell in the document empty
       and ready. A page that never does that gets no markup at all. */
@endphp

@if(!$silent || $live)
    <div {{ $attributes->merge(['class' => 'svc-outage' . ($silent ? ' hidden' : '')]) }}
         data-outage-notice
         role="status"
         aria-live="polite"
         aria-hidden="{{ $silent ? 'true' : 'false' }}">
        <span class="svc-outage-robot" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="4.5" width="14" height="13" rx="4"></rect>
                <path d="M12 2.5v2"></path>
                <path d="M9 20h6"></path>
                <circle cx="9.5" cy="10.5" r="0.9" fill="currentColor" stroke="none"></circle>
                <circle cx="14.5" cy="10.5" r="0.9" fill="currentColor" stroke="none"></circle>
                <path d="M9.2 13.8c.7.7 1.7 1 2.8 1 1.2 0 2.2-.3 2.8-1"></path>
            </svg>
        </span>

        <div class="svc-outage-copy">
            <strong>Service under maintenance</strong>
            <p data-outage-text>{!! nl2br(e($notice)) !!}</p>
            <div class="svc-outage-chips" data-outage-chips>
                @foreach($down as $slug => $label)
                    <span class="svc-outage-chip" data-outage-slug="{{ $slug }}">{{ $label }}</span>
                @endforeach
            </div>
        </div>
    </div>
@endif
