@props([
    'id' => 'confirmModal',
    'title' => 'Confirm Transaction',
    'confirmText' => 'Confirm & Proceed',
    'whatsapp' => null,
])

@php
    $whatsapp = $whatsapp ?: setting('whatsapp_link', 'https://wa.me/2348165587119');
@endphp

<div id="{{ $id }}_overlay"
     class="fixed inset-0 z-[2147483647] hidden items-center justify-center p-4"
     style="isolation:isolate;">

    {{-- Modal --}}
    <div class="relative w-full max-w-lg">
        <div class="rounded-3xl border border-white/15 bg-[#0b1220]/95 shadow-2xl overflow-hidden">
            <div class="p-6 sm:p-7">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xl font-extrabold text-white">{{ $title }}</div>
                        <div class="text-white/60 text-sm mt-1">
                            Please review the details carefully before proceeding.
                        </div>
                    </div>

                    <button type="button"
                            class="px-3 py-2 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/10 text-white font-extrabold"
                            data-modal-close="{{ $id }}">
                        ✕
                    </button>
                </div>

                {{-- Details (dynamic) --}}
                <div class="mt-5 space-y-3" data-confirm-rows>
                    {{-- JS will populate here --}}
                </div>

                {{-- Optional WhatsApp --}}
                <div class="mt-5 flex items-center justify-between gap-3">
                    <div class="text-xs text-white/50">
                        Need help? Chat support.
                    </div>
                    <a href="{{ $whatsapp }}"
                       target="_blank"
                       class="text-green-300 hover:text-green-200 font-extrabold text-sm">
                        Chat WhatsApp →
                    </a>
                </div>

                {{-- Actions --}}
                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button type="button"
                            class="px-4 py-3 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/10 text-white font-extrabold"
                            data-modal-cancel="{{ $id }}">
                        Cancel
                    </button>

                    <button type="button"
                            class="px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold"
                            data-modal-confirm="{{ $id }}">
                        {{ $confirmText }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function escapeHtml(str){
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function qs(sel, root=document){ return root.querySelector(sel); }

    function showOverlay(overlay){
        overlay.classList.remove('hidden');
        overlay.classList.add('flex'); // IMPORTANT: ensures it actually displays
    }

    function hideOverlay(overlay){
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        overlay.dataset.formTarget = '';
    }

    function renderRows(overlay, data){
        const box = qs('[data-confirm-rows]', overlay);
        if (!box) return;

        const entries = Object.entries(data || {})
            .filter(([k,v]) => v !== undefined && v !== null && String(v).trim() !== '');

        if (entries.length === 0) {
            box.innerHTML = `<div class="text-white/60 text-sm">No details provided.</div>`;
            return;
        }

        box.innerHTML = entries.map(([k,v]) => {
            const key = escapeHtml(k);
            const val = escapeHtml(String(v));
            return `
                <div class="flex items-center justify-between gap-3 py-2 border-b border-white/10 last:border-b-0">
                    <div class="text-white/60 text-sm capitalize">${key}</div>
                    <div class="text-white font-extrabold text-sm text-right">${val}</div>
                </div>
            `;
        }).join('');
    }

    // ✅ Global function your pages call
    window.openConfirmModal = function(modalId, data, formIdToSubmit) {
        const overlay = document.getElementById(modalId + '_overlay');
        if (!overlay) {
            console.error('[Confirm Modal] Overlay not found:', modalId + '_overlay');
            return;
        }

        const form = document.getElementById(formIdToSubmit);
        if (!form) {
            console.error('[Confirm Modal] Form not found:', formIdToSubmit);
            return;
        }

        // Save target form
        overlay.dataset.formTarget = formIdToSubmit || '';

        // Render details dynamically (works for ALL services)
        renderRows(overlay, data || {});

        console.log('[Confirm Modal] Opening modal:', { modalId, formId: formIdToSubmit });
        // Show modal
        showOverlay(overlay);
    };

    document.addEventListener('click', function (e) {
        // Close handlers
        const closeBtn = e.target.closest('[data-modal-close]');
        const cancelBtn = e.target.closest('[data-modal-cancel]');
        if (closeBtn) {
            e.preventDefault();
            e.stopPropagation();
            const id = closeBtn.getAttribute('data-modal-close');
            const ov = document.getElementById(id + '_overlay');
            if (ov) hideOverlay(ov);
            return;
        }
        if (cancelBtn) {
            e.preventDefault();
            e.stopPropagation();
            const id = cancelBtn.getAttribute('data-modal-cancel');
            const ov = document.getElementById(id + '_overlay');
            if (ov) hideOverlay(ov);
            return;
        }

        // Confirm handler
        const confirmBtn = e.target.closest('[data-modal-confirm]');
        if (confirmBtn) {
            e.preventDefault();
            e.stopPropagation();
            
            const modalId = confirmBtn.getAttribute('data-modal-confirm');
            const ov = document.getElementById(modalId + '_overlay');
            const formId = ov?.dataset?.formTarget || '';
            const form = formId ? document.getElementById(formId) : null;

            console.log('[Confirm Modal] ============================================');
            console.log('[Confirm Modal] Confirm Button Clicked');
            console.log('[Confirm Modal] Modal ID:', modalId);
            console.log('[Confirm Modal] Form ID to find:', formId);
            console.log('[Confirm Modal] Form found:', !!form);
            console.log('[Confirm Modal] Overlay dataset:', ov?.dataset);
            
            if (form) {
                console.log('[Confirm Modal] Form details:', {
                    id: form.id,
                    action: form.action,
                    method: form.method,
                    inputs: form.querySelectorAll('input').length,
                    formData: new FormData(form)
                });

                // Loader if available
                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Processing transaction...');
                }

                // prevent double click
                confirmBtn.disabled = true;
                confirmBtn.classList.add('opacity-60');

                // Close modal BEFORE submitting
                if (ov) hideOverlay(ov);

                console.log('[Confirm Modal] Submitting form:', formId);
                
                // Use a small delay to ensure modal is fully hidden
                setTimeout(() => {
                    try {
                        console.log('[Confirm Modal] About to call form.submit()');
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            const evt = new Event('submit', { cancelable: true });
                            const allowed = form.dispatchEvent(evt);
                            if (allowed) form.submit();
                        }
                        console.log('[Confirm Modal] form.submit() called successfully');
                    } catch (err) {
                        console.error('[Confirm Modal] Error submitting form:', err);
                    }
                }, 100);
            } else {
                console.warn('[Confirm Modal] Form NOT found!');
                console.warn('[Confirm Modal] Looking for form with ID:', formId);
                console.warn('[Confirm Modal] All forms on page:', Array.from(document.querySelectorAll('form')).map(f => f.id));
                if (ov) hideOverlay(ov);
            }
            console.log('[Confirm Modal] ============================================');
        }
    });

    // ESC closes any open confirm modal
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('[id$="_overlay"]').forEach(ov => {
            if (!ov.classList.contains('hidden')) hideOverlay(ov);
        });
    });
})();
</script>
