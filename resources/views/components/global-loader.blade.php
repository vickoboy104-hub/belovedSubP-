<div id="globalLoader" class="fixed inset-0 z-[90] hidden">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>

    <div class="relative max-w-sm mx-auto mt-32 px-4">
        <div class="rounded-3xl border border-white/15 bg-white/10 p-6 shadow-2xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-white/10 border border-white/10 flex items-center justify-center">
                    <div class="w-6 h-6 rounded-full border-2 border-white/20 border-t-white animate-spin"></div>
                </div>
                <div>
                    <div id="globalLoaderText" class="font-extrabold text-white">Processing...</div>
                    <div class="text-sm text-white/60">Please wait, do not close this page.</div>
                </div>
            </div>
            <div class="mt-4 h-1 rounded-full bg-white/10 overflow-hidden">
                <div class="loader-bar"></div>
            </div>
        </div>
    </div>
</div>

<script>
    window.showGlobalLoader = function () {
        const el = document.getElementById('globalLoader');
        if (el) el.classList.remove('hidden');
    }
    window.hideGlobalLoader = function () {
        const el = document.getElementById('globalLoader');
        if (el) el.classList.add('hidden');
    }
</script>
