@php
    $success = session('success');
    $error = session('error');
    $type = $success ? 'success' : ($error ? 'error' : null);
    $message = $success ?: ($error ?: null);
@endphp

@if($type && $message)
    {{-- Deliberately identical to showFlashToast() in layouts/app.blade.php: a
         server flash and a JS notification are the same event and must not look
         like two different products. Same #flashToast id, same layer, same skin. --}}
    <div id="flashToast" class="app-modal-overlay fixed inset-0 z-[99] flex items-center justify-center px-4" role="alertdialog" aria-modal="true">
        <div class="app-modal-panel relative w-full max-w-sm overflow-hidden">
            <div class="p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-extrabold">{{ $type === 'success' ? 'Success' : 'Failed' }}</div>
                        <div class="mt-1 text-xs font-bold uppercase tracking-[0.14em] opacity-70">Transaction status</div>
                    </div>
                    <button type="button" onclick="closeFlashToast()" class="app-modal-close" aria-label="Close">&times;</button>
                </div>

                <div class="mt-4 rounded-2xl border p-4 text-sm font-semibold leading-6 {{ $type === 'success' ? 'app-flag-tone-success' : 'app-flag-tone-error' }}">
                    {{ $message }}
                </div>

                <div class="mt-5 flex items-center justify-end">
                    <button type="button" onclick="closeFlashToast()" class="app-modal-btn app-modal-btn-warm min-w-[96px]">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function closeFlashToast() {
            const el = document.getElementById('flashToast');
            if (el) el.remove();
        }

        (function(){
            setTimeout(closeFlashToast, 8000);
        })();
    </script>
@endif
