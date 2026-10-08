<x-app-layout>
    @php
        $serviceIcons = [
            'mtn_awoof' => asset('networks/mtn.png'),
            'mtn_gifting' => asset('networks/mtn.png'),
            'mtn_sme' => asset('networks/mtn.png'),
            'mtn_cg' => asset('networks/mtn.png'),
            'mtn_cg_lite' => asset('networks/mtn.png'),
            'mtn_coupon' => asset('networks/mtn.png'),
            'mtncg' => asset('networks/mtn.png'),
            'airtel_sme' => asset('networks/Airtel.png'),
            'airtel_cg' => asset('networks/Airtel.png'),
            'airtel_gifting' => asset('networks/Airtel.png'),
            'glo_data' => asset('networks/glo.png'),
            'glo_sme' => asset('networks/glo.png'),
            'etisalat_data' => asset('networks/9mobile.png'),
        ];
        /* The last price sweep already knows whether the provider lists Awoof,
           so the tile and the robot can answer on first paint instead of
           waiting for the browser to go and ask. 'unknown' means the sweep
           never got a clean answer, so nothing is claimed until the live
           check agrees. */
        $awoofSeed = in_array($awoofState ?? 'unknown', ['available', 'unavailable'], true)
            ? (string) $awoofState
            : 'unknown';
    @endphp

    <x-page-hero class="reference-shared-banner" title="Buy Data Subscription" subtitle="Select a data provider to see available plans." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-5"
         id="dataServiceIndex"
         data-awoof-check-url="{{ route('gsubz.plans', ['service' => 'mtn_awoof']) }}"
         data-awoof-server-state="{{ $awoofSeed }}">
        <div class="reference-tile-grid">
            @foreach($services as $slug => $label)
                @php($isAwoofCard = $slug === 'mtn_awoof')
                @if($isAwoofCard)
                    {{-- The sweep above decides the starting state; the script
                         below then confirms it against the provider live. --}}
                    <x-service-tile :label="$label"
                                    :href="route('vtu.data.service', $slug)"
                                    :image="$serviceIcons[$slug] ?? asset('networks/mtn.png')"
                                    id="awoofServiceCard"
                                    class="{{ $awoofSeed === 'available' ? '' : 'hidden' }}"
                                    data-awoof-card
                                    data-awoof-state="{{ $awoofSeed }}"
                                    aria-hidden="{{ $awoofSeed === 'available' ? 'false' : 'true' }}" />
                @else
                    <x-service-tile :label="$label"
                                    :href="route('vtu.data.service', $slug)"
                                    :image="$serviceIcons[$slug] ?? asset('networks/mtn.png')" />
                @endif
            @endforeach
        </div>
    </div>

    <div id="awoofAvailabilityGuide" class="awoof-guide hidden" aria-hidden="true">
        <div id="awoofAvailabilityBubble" class="awoof-guide-bubble">
            <div class="awoof-guide-bubble-card">
                <div class="awoof-guide-robot" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="4.5" width="14" height="13" rx="4"></rect>
                        <path d="M12 2.5v2"></path>
                        <path d="M9 20h6"></path>
                        <circle cx="9.5" cy="10.5" r="0.9" fill="currentColor" stroke="none"></circle>
                        <circle cx="14.5" cy="10.5" r="0.9" fill="currentColor" stroke="none"></circle>
                        <path d="M9.2 13.8c.7.7 1.7 1 2.8 1 1.2 0 2.2-.3 2.8-1"></path>
                    </svg>
                </div>
                <div id="awoofAvailabilityText" class="awoof-guide-copy"></div>
            </div>
        </div>

        <div id="awoofAvailabilityPointer" class="awoof-guide-pointer hidden">
            <div class="awoof-guide-pointer-card">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8.5 11.5V6.8a1.2 1.2 0 0 1 2.4 0v3.4"></path>
                    <path d="M10.9 10.7V5.7a1.2 1.2 0 0 1 2.4 0v5"></path>
                    <path d="M13.3 10.9V6.9a1.2 1.2 0 0 1 2.4 0v5.6"></path>
                    <path d="M15.7 11.7V9.5a1.2 1.2 0 0 1 2.4 0v4.4c0 3-2.2 5.5-5.1 5.9l-1.7.2a4.7 4.7 0 0 1-5-3.2l-1-3a1.5 1.5 0 0 1 2.8-1.2l.8 1.7"></path>
                </svg>
            </div>
        </div>
    </div>

    <style>
        @keyframes awoofGuideFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        @keyframes awoofGuidePoint {
            0%, 100% { transform: rotate(-10deg) translate3d(0, 0, 0); }
            50% { transform: rotate(-15deg) translate3d(-3px, 3px, 0); }
        }

        @keyframes awoofGuidePulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(56, 189, 248, 0.18);
                border-color: #d6e6f7;
            }
            50% {
                box-shadow: 0 0 0 5px rgba(56, 189, 248, 0.08);
                border-color: #9fd1fb;
            }
        }

        .awoof-guide {
            position: fixed;
            inset: 0;
            z-index: 88;
            pointer-events: none;
        }

        .awoof-guide-bubble,
        .awoof-guide-pointer {
            position: fixed;
            opacity: 0;
            transition: opacity 180ms ease, transform 220ms ease;
            will-change: transform, opacity;
        }

        .awoof-guide.is-visible .awoof-guide-bubble,
        .awoof-guide.is-visible .awoof-guide-pointer {
            opacity: 1;
        }

        .awoof-guide-bubble-card {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            max-width: 190px;
            border-radius: 18px;
            border: 1px solid rgba(23, 35, 61, 0.1);
            background: rgba(255, 255, 255, 0.58);
            padding: 0.62rem 0.74rem;
            box-shadow: 0 12px 28px rgba(18, 31, 56, 0.08);
            backdrop-filter: blur(10px);
            animation: awoofGuideFloat 1.8s ease-in-out infinite;
        }

        .awoof-guide-robot {
            display: inline-flex;
            height: 34px;
            width: 34px;
            flex: 0 0 34px;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(125, 211, 252, 0.28), rgba(191, 219, 254, 0.18));
            color: rgba(23, 35, 61, 0.8);
        }

        .awoof-guide-copy {
            font-size: 0.74rem;
            line-height: 1.18rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: rgba(23, 35, 61, 0.84);
        }

        .awoof-guide-pointer-card {
            display: inline-flex;
            height: 34px;
            width: 34px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(23, 35, 61, 0.08);
            background: rgba(255, 255, 255, 0.46);
            color: rgba(23, 35, 61, 0.74);
            box-shadow: 0 10px 24px rgba(18, 31, 56, 0.08);
            backdrop-filter: blur(8px);
            transform-origin: 70% 22%;
            animation: awoofGuidePoint 1.2s ease-in-out infinite;
        }

        [data-awoof-card].awoof-card-highlight {
            animation: awoofGuidePulse 1.6s ease-in-out infinite;
        }

        @media (max-width: 640px) {
            .awoof-guide-bubble-card {
                max-width: 156px;
                gap: 0.5rem;
                padding: 0.54rem 0.64rem;
            }

            .awoof-guide-robot,
            .awoof-guide-pointer-card {
                height: 30px;
                width: 30px;
            }

            .awoof-guide-copy {
                font-size: 0.68rem;
                line-height: 1.02rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .awoof-guide-bubble-card,
            .awoof-guide-pointer-card,
            [data-awoof-card].awoof-card-highlight {
                animation: none !important;
            }
        }
    </style>

    <script>
        (function () {
            const page = document.getElementById('dataServiceIndex');
            const awoofCard = document.getElementById('awoofServiceCard');
            const guide = document.getElementById('awoofAvailabilityGuide');
            const bubble = document.getElementById('awoofAvailabilityBubble');
            const bubbleText = document.getElementById('awoofAvailabilityText');
            const pointer = document.getElementById('awoofAvailabilityPointer');
            const checkUrl = page?.dataset?.awoofCheckUrl || '';
            const serverState = page?.dataset?.awoofServerState || 'unknown';

            if (!page || !awoofCard || !guide || !bubble || !bubbleText || !pointer || !checkUrl) {
                return;
            }

            if (guide.parentElement !== document.body) {
                document.body.appendChild(guide);
            }

            let hideTimer = null;
            let currentMode = 'unavailable';

            function clamp(value, min, max) {
                return Math.min(Math.max(value, min), max);
            }

            function hideGuide() {
                guide.classList.add('hidden');
                guide.classList.remove('is-visible');
                pointer.classList.add('hidden');
                awoofCard.classList.remove('awoof-card-highlight');
                window.clearTimeout(hideTimer);
            }

            function getCardAnchor(rect) {
                return {
                    x: rect.left + Math.min(Math.max(rect.width * 0.24, 42), 72),
                    y: rect.top + Math.min(Math.max(rect.height * 0.34, 28), 56),
                };
            }

            function positionGuide() {
                const bubbleRect = bubble.getBoundingClientRect();
                const pointerRect = pointer.getBoundingClientRect();
                const pageRect = page.getBoundingClientRect();
                const viewportPadding = 12;
                const mobileViewport = window.innerWidth < 768;

                let bubbleX = viewportPadding;
                let bubbleY = clamp(pageRect.top + 8, 84, 140);
                let pointerX = viewportPadding;
                let pointerY = viewportPadding;

                if (currentMode === 'available' && !awoofCard.classList.contains('hidden')) {
                    const rect = awoofCard.getBoundingClientRect();
                    const anchor = getCardAnchor(rect);

                    if (mobileViewport) {
                        bubbleX = clamp(anchor.x - (bubbleRect.width / 2), viewportPadding, window.innerWidth - bubbleRect.width - viewportPadding);
                        bubbleY = rect.top - bubbleRect.height - 10;
                        if (bubbleY < 92) {
                            bubbleY = rect.bottom + 10;
                        }

                        pointerX = clamp(anchor.x - (pointerRect.width / 2), viewportPadding, window.innerWidth - pointerRect.width - viewportPadding);
                        pointerY = clamp(anchor.y - (pointerRect.height / 2), viewportPadding, window.innerHeight - pointerRect.height - viewportPadding);
                    } else {
                        const fitsRight = rect.right + bubbleRect.width + 14 <= window.innerWidth - viewportPadding;
                        bubbleX = fitsRight
                            ? rect.right + 14
                            : rect.left - bubbleRect.width - 14;
                        bubbleX = clamp(bubbleX, viewportPadding, window.innerWidth - bubbleRect.width - viewportPadding);
                        bubbleY = clamp(anchor.y - (bubbleRect.height / 2), 90, window.innerHeight - bubbleRect.height - viewportPadding);

                        pointerX = clamp(anchor.x - (pointerRect.width / 2), viewportPadding, window.innerWidth - pointerRect.width - viewportPadding);
                        pointerY = clamp(anchor.y - (pointerRect.height / 2), viewportPadding, window.innerHeight - pointerRect.height - viewportPadding);
                    }
                } else {
                    bubbleX = mobileViewport
                        ? clamp((window.innerWidth / 2) - (bubbleRect.width / 2), viewportPadding, window.innerWidth - bubbleRect.width - viewportPadding)
                        : clamp(pageRect.left + 12, viewportPadding, window.innerWidth - bubbleRect.width - viewportPadding);
                    bubbleY = clamp(pageRect.top + 12, 92, 168);
                }

                bubble.style.transform = `translate3d(${Math.round(bubbleX)}px, ${Math.round(bubbleY)}px, 0)`;

                if (currentMode === 'available') {
                    pointer.style.transform = `translate3d(${Math.round(pointerX)}px, ${Math.round(pointerY)}px, 0)`;
                }
            }

            function showGuide(message, mode) {
                currentMode = mode;
                bubbleText.textContent = message;
                guide.classList.remove('hidden');
                pointer.classList.toggle('hidden', mode !== 'available');
                awoofCard.classList.toggle('awoof-card-highlight', mode === 'available');

                window.requestAnimationFrame(() => {
                    positionGuide();
                    guide.classList.add('is-visible');
                });

                window.clearTimeout(hideTimer);
                hideTimer = window.setTimeout(hideGuide, 6500);
            }

            function markCard(state) {
                const visible = state === 'available';
                awoofCard.classList.toggle('hidden', !visible);
                awoofCard.setAttribute('aria-hidden', visible ? 'false' : 'true');
                awoofCard.dataset.awoofState = state;
            }

            async function askProvider() {
                try {
                    const response = await fetch(checkUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        return 'unknown';
                    }

                    let data = {};
                    try {
                        data = await response.json();
                    } catch (error) {}

                    /* Only a real answer from the provider can prove the plan is
                       gone: ok=true with no plans means they listed nothing.
                       Anything else is an outage and must not hide a service
                       the last sweep saw for sale. */
                    if (data?.ok !== true) {
                        return 'unknown';
                    }

                    const plans = Array.isArray(data?.plans) ? data.plans : [];
                    return plans.length > 0 ? 'available' : 'unavailable';
                } catch (error) {
                    return 'unknown';
                }
            }

            async function detectAwoofAvailability() {
                if (serverState === 'available' || serverState === 'unavailable') {
                    markCard(serverState);
                }

                const verified = await askProvider();
                if (verified === 'unknown') {
                    return;
                }

                markCard(verified);
                if (verified === 'available') {
                    showGuide('Hurrah, Awoof plan is back.', 'available');
                    return;
                }

                showGuide('Awoof plan is not available.', 'unavailable');
            }

            window.addEventListener('resize', () => {
                if (!guide.classList.contains('hidden')) {
                    positionGuide();
                }
            }, { passive: true });

            window.addEventListener('scroll', () => {
                if (!guide.classList.contains('hidden')) {
                    positionGuide();
                }
            }, { passive: true });

            detectAwoofAvailability();
        })();
    </script>
</x-app-layout>
