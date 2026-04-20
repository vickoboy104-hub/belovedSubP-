@props([
    'message' => 'Need NIN services? Click the WhatsApp Support button to chat with us instantly.',
])

@php
    $marqueeSpeedSeconds = (float) setting('marquee_speed_seconds', 30);
    if ($marqueeSpeedSeconds < 5) {
        $marqueeSpeedSeconds = 5;
    }
    if ($marqueeSpeedSeconds > 120) {
        $marqueeSpeedSeconds = 120;
    }
@endphp

<div id="ninMarqueeWrapper"
     class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
    <div id="ninMarquee"
         class="whitespace-nowrap py-2 px-4 text-sm font-medium nin-marquee-track"
         style="--nin-marquee-duration: {{ rtrim(rtrim(number_format($marqueeSpeedSeconds, 2, '.', ''), '0'), '.') }}s;">
        {{ $message }}
    </div>
</div>

<style>
    .nin-marquee-track {
        display: inline-block;
        animation: nin-marquee-left var(--nin-marquee-duration, 30s) linear infinite;
    }
    @keyframes nin-marquee-left {
        0%   { transform: translateX(100%); }
        100% { transform: translateX(-100%); }
    }
</style>
