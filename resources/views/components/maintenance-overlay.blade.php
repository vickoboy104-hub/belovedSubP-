@php
    $overlayEnabled = (string) setting('maintenance_overlay_enabled', '0') === '1';
    $overlayMessage = trim((string) setting('maintenance_overlay_message', 'We are currently running an update. Please hold on while we finish.'));
    $overlayEndRaw = trim((string) setting('maintenance_overlay_end_at', ''));
    $overlayEndIso = null;

    try {
        if ($overlayEndRaw !== '') {
            $overlayEndIso = \Illuminate\Support\Carbon::parse($overlayEndRaw)->toIso8601String();
        }
    } catch (\Throwable $e) {
        $overlayEndIso = null;
    }

    $isAdminUser = (bool) (auth()->user()?->is_admin ?? false);
    $isAdminArea = request()->routeIs('admin.*');
    $allowBypass = $isAdminUser && $isAdminArea;

    $showOverlay = false;
    if ($overlayEnabled && !$allowBypass && $overlayEndIso !== null) {
        try {
            $showOverlay = now()->lt(\Illuminate\Support\Carbon::parse($overlayEndIso));
        } catch (\Throwable $e) {
            $showOverlay = false;
        }
    }
@endphp

@if($showOverlay)
    <div id="maintenanceOverlay"
         class="app-modal-overlay fixed inset-0 z-[9999] flex items-center justify-center p-4"
         role="dialog"
         aria-live="polite"
         aria-modal="true">
        <div class="app-modal-panel overflow-hidden">
            <div class="p-6 sm:p-7">
                <div class="text-xl sm:text-2xl font-extrabold">Update In Progress</div>
                <p class="mt-2 text-sm sm:text-base opacity-80">
                    {{ $overlayMessage !== '' ? $overlayMessage : 'We are currently running an update. Please hold on while we finish.' }}
                </p>

                <div class="app-soft-panel mt-5 rounded-2xl border p-4">
                    <div class="text-xs font-bold uppercase tracking-wide opacity-70">Estimated Time Remaining</div>
                    <div id="maintenanceCountdown" class="mt-1 text-3xl sm:text-4xl font-black" data-end-at="{{ $overlayEndIso }}">
                        --:--
                    </div>
                    <div id="maintenanceCountdownLabel" class="mt-1 text-xs opacity-70">
                        Counting down...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const overlay = document.getElementById('maintenanceOverlay');
            const countdown = document.getElementById('maintenanceCountdown');
            const label = document.getElementById('maintenanceCountdownLabel');
            if (!overlay || !countdown) return;

            const endAtRaw = countdown.dataset.endAt || '';
            const endAt = Date.parse(endAtRaw);
            if (!Number.isFinite(endAt)) {
                overlay.remove();
                return;
            }

            function pad(value) {
                return String(value).padStart(2, '0');
            }

            function formatRemaining(totalSeconds) {
                const minutes = Math.floor(totalSeconds / 60);
                const seconds = totalSeconds % 60;
                return pad(minutes) + ':' + pad(seconds);
            }

            function tick() {
                const remainingMs = endAt - Date.now();
                const remainingSeconds = Math.max(0, Math.floor(remainingMs / 1000));

                countdown.textContent = formatRemaining(remainingSeconds);

                const minutes = Math.floor(remainingSeconds / 60);
                const seconds = remainingSeconds % 60;
                label.textContent = minutes + ' minute(s) ' + seconds + ' second(s)';

                if (remainingSeconds <= 0) {
                    clearInterval(timer);
                    location.reload();
                }
            }

            tick();
            const timer = setInterval(tick, 1000);
        })();
    </script>
@endif
