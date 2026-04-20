<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ setting('site_name', config('app.name', 'VTU Platform')) }}</title>

    @php
        $siteFavicon = setting('favicon_url', setting('site_favicon', ''));
    @endphp

    @if(!empty($siteFavicon))
        <link rel="icon" href="{{ $siteFavicon }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 dark:bg-[#0b1220] text-gray-900 dark:text-white">

    {{-- Optional: Global Loader --}}
    <div id="globalLoader" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/60 px-4">
        <div class="w-full max-w-sm rounded-3xl border border-white/10 bg-[#0b1220] p-6 shadow-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-white/10 border border-white/10 flex items-center justify-center">
                    ⏳
                </div>
                <div>
                    <div class="font-extrabold">Processing</div>
                    <div id="globalLoaderText" class="text-white/60 text-sm">Please wait...</div>
                </div>
            </div>
        </div>
    </div>

    <div class="min-h-screen flex flex-col">
        {{-- Header --}}
        <header class="w-full border-b border-gray-200 dark:border-white/10 bg-white/80 dark:bg-white/5 backdrop-blur">
            <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                    @php
                        $siteLogo = setting('logo_url', setting('site_logo', ''));
                        $siteName = setting('site_name', config('app.name', 'VTU Platform'));
                    @endphp

                    @if(!empty($siteLogo))
                        <img src="{{ $siteLogo }}" alt="Logo" class="w-10 h-10 rounded-2xl object-cover border border-gray-200 dark:border-white/10">
                    @else
                        <div class="w-10 h-10 rounded-2xl bg-black/5 dark:bg-white/10 border border-gray-200 dark:border-white/10 flex items-center justify-center">
                            ⚡
                        </div>
                    @endif

                    <div class="font-extrabold text-lg">{{ $siteName }}</div>
                </a>

                <div class="flex items-center gap-3">
                    <button onclick="toggleTheme()"
                            class="px-4 py-2 rounded-2xl bg-black/5 dark:bg-white/10 border border-gray-200 dark:border-white/10 font-semibold">
                        Toggle Theme
                    </button>
                </div>
            </div>
        </header>

        {{-- Main --}}
        <main class="flex-1 py-6">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="py-6 text-center text-sm text-gray-500 dark:text-white/40">
            © {{ date('Y') }} {{ setting('site_name', config('app.name', 'VTU Platform')) }}.
        </footer>
    </div>

    <script>
        function showGlobalLoader(text){
            const el = document.getElementById('globalLoader');
            const t = document.getElementById('globalLoaderText');
            if (t && text) t.textContent = text;
            if (el) { el.classList.remove('hidden'); el.classList.add('flex'); }
        }
        function hideGlobalLoader(){
            const el = document.getElementById('globalLoader');
            if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
        }

        // =========================================================
        // Confirm Modal (global helper)
        // Ensures service pages work even if a component script is missing.
        // =========================================================
        function openConfirmModal(modalId, summary, formId){
            try {
                // If a custom modal exists on the page (from <x-confirm-modal />), try to use it.
                const existing = document.getElementById(modalId);
                if (existing) {
                    // Common placeholders (we support multiple possible markup styles)
                    const list = existing.querySelector('[data-confirm-list]');
                    if (list) {
                        list.innerHTML = '';
                        Object.entries(summary || {}).forEach(([k,v]) => {
                            const row = document.createElement('div');
                            row.className = 'flex items-center justify-between gap-3 py-2 border-b border-white/10 last:border-b-0';
                            row.innerHTML = `<div class="text-white/60 text-sm">${escapeHtml(k)}</div><div class="font-extrabold text-sm text-white">${escapeHtml(String(v))}</div>`;
                            list.appendChild(row);
                        });
                    }

                    existing.classList.remove('hidden');
                    existing.classList.add('flex');

                    const cancelBtn = existing.querySelector('[data-confirm-cancel]') || existing.querySelector('.confirm-cancel');
                    const okBtn = existing.querySelector('[data-confirm-ok]') || existing.querySelector('.confirm-ok');

                    const close = () => {
                        existing.classList.add('hidden');
                        existing.classList.remove('flex');
                    };

                    if (cancelBtn) {
                        cancelBtn.onclick = (e) => { e.preventDefault(); close(); };
                    }

                    if (okBtn) {
                        okBtn.onclick = (e) => {
                            e.preventDefault();
                            showGlobalLoader('Processing transaction...');
                            okBtn.disabled = true;
                            const form = document.getElementById(formId);
                            if (form) form.submit();
                            else hideGlobalLoader();
                        };
                    }

                    // click outside to close
                    existing.addEventListener('click', (e) => {
                        if (e.target === existing) close();
                    }, { once: true });

                    return;
                }
            } catch (e) {}

            // Otherwise, create a minimal modal dynamically.
            createAndShowFallbackModal(modalId, summary, formId);
        }

        function createAndShowFallbackModal(modalId, summary, formId){
            let wrap = document.getElementById(modalId);
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = modalId;
                wrap.className = 'fixed inset-0 z-[9999] hidden items-center justify-center bg-black/70 p-4';
                wrap.innerHTML = `
                    <div class="w-full max-w-md rounded-3xl border border-white/10 bg-[#0b1220] p-5 shadow-2xl">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-lg font-extrabold text-white">Confirm Transaction</div>
                                <div class="text-sm text-white/60 mt-1">Please check the details below.</div>
                            </div>
                            <button type="button" class="confirm-cancel text-white/70 hover:text-white text-xl">✕</button>
                        </div>

                        <div class="mt-4 space-y-2" data-confirm-list></div>

                        <div class="mt-5 flex gap-3">
                            <button type="button" class="confirm-cancel flex-1 px-4 py-3 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/10 font-extrabold text-white">
                                Cancel
                            </button>
                            <button type="button" class="confirm-ok flex-1 px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 font-extrabold text-white">
                                Confirm & Continue
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(wrap);
            }

            const list = wrap.querySelector('[data-confirm-list]');
            if (list) {
                list.innerHTML = '';
                Object.entries(summary || {}).forEach(([k,v]) => {
                    const row = document.createElement('div');
                    row.className = 'flex items-center justify-between gap-3 py-2 border-b border-white/10 last:border-b-0';
                    row.innerHTML = `<div class="text-white/60 text-sm">${escapeHtml(k)}</div><div class="font-extrabold text-sm text-white">${escapeHtml(String(v))}</div>`;
                    list.appendChild(row);
                });
            }

            wrap.classList.remove('hidden');
            wrap.classList.add('flex');

            const close = () => { wrap.classList.add('hidden'); wrap.classList.remove('flex'); };

            wrap.querySelectorAll('.confirm-cancel').forEach(b => b.onclick = (e)=>{ e.preventDefault(); close(); });

            const okBtn = wrap.querySelector('.confirm-ok');
            if (okBtn) {
                okBtn.onclick = (e) => {
                    e.preventDefault();
                    showGlobalLoader('Processing transaction...');
                    okBtn.disabled = true;
                    const form = document.getElementById(formId);
                    if (form) form.submit();
                    else hideGlobalLoader();
                };
            }

            wrap.addEventListener('click', (e)=>{ if (e.target === wrap) close(); }, { once:true });
        }

        function escapeHtml(str){
            return String(str)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function toggleTheme() {
            const html = document.documentElement;
            html.classList.toggle('dark');
        }
    </script>
</body>
</html>
