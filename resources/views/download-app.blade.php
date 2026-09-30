<x-guest-layout page-title="Install BelovedSubP" meta-description="Install BelovedSubP from your browser for quick access to identity and everyday services.">
    @php
        $downloadUrl = trim((string) setting('app_download_url', ''));
        $versionLabel = trim((string) setting('app_latest_version', setting('app_download_version', 'Android APK')));
    @endphp

    <div class="reference-guide-page max-w-3xl mx-auto px-4 py-10 space-y-6">
        <x-page-hero class="reference-guest-banner" title="Install BelovedSubP on your phone" subtitle="Open identity services, wallet and everyday payments from your home screen. On supported Android browsers, installation does not need an APK." :battery="false" />

        <div class="rounded-3xl p-6 sm:p-8 border border-gray-200 bg-white">
            <button type="button" id="installBelovedApp" hidden class="btn-primary mt-6">Install from browser</button>
            <div class="mt-6 rounded-2xl bg-blue-50 p-5 text-sm leading-7 text-slate-700">
                <strong class="text-blue-900">On Android Chrome</strong><br>
                Tap the browser menu (⋮), then choose <strong>Install app</strong> or <strong>Add to Home screen</strong>. Open your new icon to use the site like an app.
            </div>

            <script>
                (() => {
                    let installPrompt;
                    const installButton = document.getElementById('installBelovedApp');
                    window.addEventListener('beforeinstallprompt', event => {
                        event.preventDefault();
                        installPrompt = event;
                        installButton.hidden = false;
                    });
                    installButton.addEventListener('click', async () => {
                        if (!installPrompt) return;
                        installPrompt.prompt();
                        await installPrompt.userChoice;
                        installPrompt = null;
                        installButton.hidden = true;
                    });
                    window.addEventListener('appinstalled', () => { installButton.hidden = true; });
                    if ('serviceWorker' in navigator) {
                        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
                    }
                })();
            </script>

            @if($downloadUrl !== '')
                <p class="mt-6 text-sm text-slate-600">Alternatively, if you prefer the Android package:</p>
                <a href="{{ $downloadUrl }}"
                   class="inline-flex mt-6 px-6 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition"
                   target="_blank" rel="noopener">
                    Download {{ $versionLabel }}
                </a>
            @else
                <div class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    You can install from your browser using the instructions above. No APK is needed.
                </div>
            @endif
        </div>
    </div>
</x-guest-layout>
