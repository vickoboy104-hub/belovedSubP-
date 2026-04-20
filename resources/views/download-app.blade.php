<x-guest-layout page-title="Download Our App" meta-description="Download the BelovedSubP Android app and access services quickly.">
    @php
        $downloadUrl = trim((string) setting('app_download_url', ''));
        $versionLabel = trim((string) setting('app_latest_version', setting('app_download_version', 'Android APK')));
    @endphp

    <div class="max-w-3xl mx-auto px-4 py-10">
        <div class="rounded-3xl p-6 sm:p-8 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5">
            <h1 class="text-3xl font-extrabold">Download Our App</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-white/60">
                Install the mobile app for faster access to your wallet, transactions, and VTU services.
            </p>

            @if($downloadUrl !== '')
                <a href="{{ $downloadUrl }}"
                   class="inline-flex mt-6 px-6 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition"
                   target="_blank" rel="noopener">
                    Download {{ $versionLabel }}
                </a>
            @else
                <div class="mt-6 rounded-2xl p-4 border border-amber-500/20 bg-amber-500/10 text-amber-100">
                    App file is not uploaded yet.
                </div>
            @endif
        </div>
    </div>
</x-guest-layout>
