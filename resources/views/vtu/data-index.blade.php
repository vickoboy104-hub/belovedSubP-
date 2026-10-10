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

        $down = $down ?? [];
        $catalogue = $catalogue ?? $services;
    @endphp

    <x-page-hero class="reference-shared-banner" title="Buy Data Subscription" subtitle="Select a data provider to see available plans." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-5"
         id="dataServiceIndex"
         data-awoof-check-url="{{ route('gsubz.plans', ['service' => 'mtn_awoof']) }}"
         data-awoof-server-state="{{ $awoofSeed }}"
         data-awoof-label="{{ $awoofLabel ?? 'MTN Awoof' }}"
         data-awoof-down-notice="{{ $awoofDownNotice ?? '' }}"
         data-awoof-up-notice="{{ $awoofUpNotice ?? '' }}">
        {{-- The strip stays for the whole visit: an outage notice that fades out
             is an outage notice nobody read. --}}
        <x-service-outage-notice :down="$down" live />

        <div class="reference-tile-grid">
            @foreach($catalogue as $slug => $label)
                @php($isAwoofCard = $slug === 'mtn_awoof')
                @if($isAwoofCard)
                    {{-- Awoof keeps its place in the grid even when hidden, so
                         the browser can bring the tile back the moment the
                         provider starts listing it again. --}}
                    <x-service-tile :label="$label"
                                    :href="route('vtu.data.service', $slug)"
                                    :image="$serviceIcons[$slug] ?? asset('networks/mtn.png')"
                                    id="awoofServiceCard"
                                    class="{{ $awoofSeed === 'unavailable' ? 'hidden' : '' }}"
                                    data-awoof-card
                                    data-awoof-state="{{ $awoofSeed }}"
                                    aria-hidden="{{ $awoofSeed === 'unavailable' ? 'true' : 'false' }}" />
                @elseif(!array_key_exists($slug, $down))
                    <x-service-tile :label="$label"
                                    :href="route('vtu.data.service', $slug)"
                                    :image="$serviceIcons[$slug] ?? asset('networks/mtn.png')" />
                @endif
            @endforeach
        </div>
    </div>

    <script>
        (function () {
            const page = document.getElementById('dataServiceIndex');
            const awoofCard = document.getElementById('awoofServiceCard');
            const notice = page ? page.querySelector('[data-outage-notice]') : null;

            if (!page || !awoofCard || !notice) {
                return;
            }

            const chips = notice.querySelector('[data-outage-chips]');
            const noticeText = notice.querySelector('[data-outage-text]');
            const checkUrl = page.dataset.awoofCheckUrl || '';
            const serverState = page.dataset.awoofServerState || 'unknown';
            const awoofLabel = page.dataset.awoofLabel || 'MTN Awoof';
            const downNotice = page.dataset.awoofDownNotice || '';
            const upNotice = page.dataset.awoofUpNotice || '';
            const serverNotice = noticeText.textContent.trim();

            if (!checkUrl || !chips) {
                return;
            }

            function awoofChip() {
                return chips.querySelector('[data-outage-slug="mtn_awoof"]');
            }

            function markCard(state) {
                const hidden = state === 'unavailable';
                awoofCard.classList.toggle('hidden', hidden);
                awoofCard.setAttribute('aria-hidden', hidden ? 'true' : 'false');
                awoofCard.dataset.awoofState = state;
            }

            function announceDown() {
                if (!awoofChip()) {
                    const chip = document.createElement('span');
                    chip.className = 'svc-outage-chip is-arriving';
                    chip.dataset.outageSlug = 'mtn_awoof';
                    chip.textContent = awoofLabel;
                    chips.appendChild(chip);
                }

                noticeText.textContent = downNotice || serverNotice;
                notice.classList.remove('hidden');
                notice.setAttribute('aria-hidden', 'false');
            }

            function announceBack() {
                const chip = awoofChip();
                if (chip) {
                    chip.remove();
                }

                if (chips.children.length === 0) {
                    notice.classList.add('hidden');
                    notice.setAttribute('aria-hidden', 'true');
                    return;
                }

                noticeText.textContent = upNotice || serverNotice;
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

                if (verified === 'unavailable') {
                    markCard('unavailable');
                    announceDown();
                    return;
                }

                markCard('available');
                announceBack();
            }

            detectAwoofAvailability();
        })();
    </script>
</x-app-layout>
