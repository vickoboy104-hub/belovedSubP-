@php
    $success = session('success');
    $error = session('error');
    $type = $success ? 'success' : ($error ? 'error' : null);
    $message = $success ?: ($error ?: null);
@endphp

@if($type && $message)
    <div id="flashToast" class="fixed inset-0 z-[85] flex items-center justify-center px-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" aria-hidden="true"></div>

        <div class="relative w-full max-w-md rounded-3xl border border-white/15 bg-white/10 shadow-2xl overflow-hidden toast-pop">
            <div class="p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="toast-icon {{ $type === 'success' ? 'toast-icon-success text-emerald-200' : 'toast-icon-error text-red-200' }}">
                            @if($type === 'success')
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6L9 17l-5-5"></path>
                                </svg>
                            @else
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 6L6 18"></path>
                                    <path d="M6 6l12 12"></path>
                                </svg>
                            @endif
                        </div>
                        <div>
                            <div class="text-xl font-extrabold text-white">
                                {{ $type === 'success' ? 'Success' : 'Failed' }}
                            </div>
                            <div class="text-white/70 text-sm mt-1">Transaction status</div>
                        </div>
                    </div>
                    <button type="button" onclick="closeFlashToast()"
                            class="w-10 h-10 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/10 flex items-center justify-center text-white/80">
                        X
                    </button>
                </div>

                <div class="mt-4 rounded-2xl p-4 border {{ $type === 'success' ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-100' : 'border-red-500/25 bg-red-500/10 text-red-100' }}">
                    {{ $message }}
                </div>

                <div class="mt-4 h-1 rounded-full bg-white/10 overflow-hidden">
                    <div class="toast-progress {{ $type === 'success' ? 'bg-emerald-400/70' : 'bg-red-400/70' }}"></div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button type="button" onclick="closeFlashToast()"
                            class="px-5 py-3 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/10 font-extrabold text-white transition">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function closeFlashToast() {
            const el = document.getElementById('flashToast');
            if (el) el.remove();
        }

        // Auto-close after a short delay
        (function(){
            setTimeout(closeFlashToast, 8000);
        })();
    </script>
@endif
