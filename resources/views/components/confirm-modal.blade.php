@props([
    'id' => 'confirmModal',
    'title' => 'Confirm Transaction',
    'confirmText' => 'Confirm & Proceed',
    'whatsapp' => null,
])

@php
    $whatsapp = $whatsapp ?: whatsapp_link();
@endphp

<div id="{{ $id }}_overlay"
     data-wallet-kobo="{{ (int) (auth()->user()?->wallet->balance ?? 0) }}"
     class="app-modal-overlay fixed inset-0 z-[97] hidden items-center justify-center p-4"
     style="isolation:isolate;"
     role="dialog"
     aria-modal="true">

    <div class="relative w-full app-confirm-modal">
        <div class="app-modal-panel overflow-hidden">
            <div class="p-6 sm:p-7">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xl font-extrabold">{{ $title }}</div>
                        <div class="mt-1 text-sm opacity-80">
                            Please review the details carefully before proceeding.
                        </div>
                    </div>

                    <button type="button"
                            class="app-modal-close"
                            aria-label="Close"
                            data-modal-close="{{ $id }}">
                        &times;
                    </button>
                </div>

                <div class="mt-4" data-confirm-rows></div>

                <div class="mt-3 app-confirm-balance" data-confirm-balance hidden>
                    <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap text-[11px] sm:text-xs">
                        <span class="shrink-0 font-bold uppercase tracking-[0.18em] opacity-70">Wallet</span>
                        <span class="shrink-0 app-confirm-balance-pill">Bal <span class="font-bold" data-balance-current>&#8358;0.00</span></span>
                        <span class="shrink-0 app-confirm-balance-pill">Debit <span class="font-bold" data-balance-deduct>&#8358;0.00</span></span>
                        <span class="shrink-0 app-confirm-balance-pill">Left <span class="font-bold" data-balance-remaining>&#8358;0.00</span></span>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <div class="app-confirm-help">
                        Need help? Chat support.
                    </div>
                    <a href="{{ $whatsapp }}"
                       target="_blank"
                       rel="noopener"
                       class="app-support-link">
                        Chat WhatsApp &rarr;
                    </a>
                </div>

                <div class="mt-5 app-modal-actions">
                    <button type="button"
                            class="app-modal-btn app-modal-btn-muted"
                            data-modal-cancel="{{ $id }}">
                        Cancel
                    </button>

                    <button type="button"
                            class="app-modal-btn app-modal-btn-primary"
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

    function formatNairaFromKobo(kobo) {
        const amount = Number(kobo || 0) / 100;
        return '\u20A6' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function parseAmountToKobo(value) {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return Math.round(value * 100);
        }

        const cleaned = String(value || '').replace(/[^0-9.\-]/g, '');
        const parsed = Number(cleaned);
        if (!Number.isFinite(parsed)) return null;
        return Math.round(parsed * 100);
    }

    function resolveDebitAmountKobo(data) {
        const direct = parseAmountToKobo(data?.__debitAmount ?? data?.__debit_amount ?? data?.amount ?? data?.total ?? data?.payable);
        if (direct !== null) return direct;

        const candidates = ['amount', 'total', 'payable'];
        for (const key of candidates) {
            if (!Object.prototype.hasOwnProperty.call(data || {}, key)) continue;
            const parsed = parseAmountToKobo(data[key]);
            if (parsed !== null) return parsed;
        }

        return null;
    }

    function showOverlay(overlay){
        if (typeof window.promoteViewportLayer === 'function') {
            window.promoteViewportLayer(overlay);
        }
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    }

    function hideOverlay(overlay){
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        const pending = document.getElementById(overlay.dataset.formTarget || '');
        if (pending) delete pending.dataset.sheetConfirmed;
        overlay.dataset.formTarget = '';
    }

    function renderRows(overlay, data){
        const box = qs('[data-confirm-rows]', overlay);
        if (!box) return;

        const entries = Object.entries(data || {})
            .filter(([k, v]) => !String(k).startsWith('__'))
            .filter(([,v]) => v !== undefined && v !== null && String(v).trim() !== '');

        if (entries.length === 0) {
            box.innerHTML = '<div class="app-confirm-empty">No details provided.</div>';
            return;
        }

        box.innerHTML = entries.map(([k,v]) => {
            const key = escapeHtml(k);
            const val = escapeHtml(String(v));

            return `
                <div class="app-confirm-row">
                    <div class="app-confirm-key">${key}</div>
                    <div class="app-confirm-value">${val}</div>
                </div>
            `;
        }).join('');
    }

    function renderBalanceSummary(overlay, data) {
        const wrap = qs('[data-confirm-balance]', overlay);
        const currentEl = qs('[data-balance-current]', overlay);
        const deductEl = qs('[data-balance-deduct]', overlay);
        const remainingEl = qs('[data-balance-remaining]', overlay);
        const walletEl = document.getElementById('walletBalance');
        const currentKobo = Number(walletEl?.dataset?.walletKobo ?? overlay?.dataset?.walletKobo ?? 0);
        const debitKobo = resolveDebitAmountKobo(data);

        if (!wrap || !currentEl || !deductEl || !remainingEl || debitKobo === null) {
            if (wrap) wrap.hidden = true;
            return;
        }

        const remainingKobo = currentKobo - debitKobo;
        currentEl.textContent = formatNairaFromKobo(currentKobo);
        deductEl.textContent = formatNairaFromKobo(debitKobo);
        remainingEl.textContent = formatNairaFromKobo(remainingKobo);
        remainingEl.className = 'font-bold ' + (remainingKobo < 0 ? 'text-rose-700' : 'text-slate-900');
        wrap.hidden = false;
    }

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

        overlay.dataset.formTarget = formIdToSubmit || '';
        renderRows(overlay, data || {});
        renderBalanceSummary(overlay, data || {});
        showOverlay(overlay);
    };

    document.addEventListener('click', function (e) {
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

        const confirmBtn = e.target.closest('[data-modal-confirm]');
        if (confirmBtn) {
            e.preventDefault();
            e.stopPropagation();

            const modalId = confirmBtn.getAttribute('data-modal-confirm');
            const ov = document.getElementById(modalId + '_overlay');
            const formId = ov?.dataset?.formTarget || '';
            const form = formId ? document.getElementById(formId) : null;

            if (form) {
                if (typeof window.showGlobalLoader === 'function') {
                    window.showGlobalLoader('Processing transaction...');
                }

                confirmBtn.disabled = true;
                confirmBtn.classList.add('opacity-60');

                if (ov) hideOverlay(ov);

                setTimeout(() => {
                    try {
                        if (typeof form.requestSubmit === 'function') {
                            // The flag is what tells the gate below this is the release, not a new request.
                            if (form.matches('form[data-confirm-sheet]')) {
                                form.dataset.sheetConfirmed = '1';
                            }
                            form.requestSubmit();
                        } else {
                            delete form.dataset.sheetConfirmed;
                            const evt = new Event('submit', { cancelable: true });
                            const allowed = form.dispatchEvent(evt);
                            if (allowed) form.submit();
                        }
                    } catch (err) {
                        console.error('[Confirm Modal] Error submitting form:', err);
                    }
                }, 100);
            } else if (ov) {
                hideOverlay(ov);
            }
        }
    });

    // Any form can trade the browser's unstyleable confirm() for this sheet by
    // declaring data-confirm-sheet with a modal id, plus data-confirm-details
    // holding the rows to show.
    document.addEventListener('submit', function (e) {
        const form = e.target instanceof HTMLElement ? e.target.closest('form[data-confirm-sheet]') : null;
        if (!form) return;

        if (form.dataset.sheetConfirmed === '1') {
            delete form.dataset.sheetConfirmed;
            return;
        }

        e.preventDefault();

        if (!form.id) {
            console.error('[Confirm Modal] A sheet-gated form needs an id so the sheet can find it.', form);
            form.submit();
            return;
        }

        let details = {};
        try {
            details = JSON.parse(form.getAttribute('data-confirm-details') || '{}');
        } catch (err) {
            details = {};
        }

        window.openConfirmModal(form.getAttribute('data-confirm-sheet'), details, form.id);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('[id$="_overlay"]').forEach(ov => {
            if (!ov.classList.contains('hidden')) hideOverlay(ov);
        });
    });
})();
</script>

