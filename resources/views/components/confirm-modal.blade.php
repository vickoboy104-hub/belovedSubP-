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
     data-wallet-kobo="{{ (int) (auth()->user()?->wallet->balance ?? 0) }}"
     class="fixed inset-0 z-[2147483647] hidden items-center justify-center p-4"
     style="isolation:isolate;">

    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>

    <div class="relative w-full max-w-lg">
        <div class="overflow-hidden rounded-[30px] border border-slate-200 bg-white shadow-[0_22px_55px_rgba(18,31,56,0.24)]">
            <div class="p-6 sm:p-7">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xl font-extrabold text-slate-900">{{ $title }}</div>
                        <div class="mt-1 text-sm text-slate-600">
                            Please review the details carefully before proceeding.
                        </div>
                    </div>

                    <button type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-700 transition hover:bg-slate-100"
                            data-modal-close="{{ $id }}">
                        &times;
                    </button>
                </div>

                <div class="mt-4 space-y-0" data-confirm-rows></div>

                <div class="mt-3 rounded-[20px] border border-slate-200 bg-slate-50 px-3 py-2" data-confirm-balance hidden>
                    <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap text-[11px] text-slate-500 sm:text-xs">
                        <span class="shrink-0 font-bold uppercase tracking-[0.18em] text-slate-400">Wallet</span>
                        <span class="shrink-0 rounded-full bg-white px-2 py-1">Bal <span class="font-bold text-slate-900" data-balance-current>&#8358;0.00</span></span>
                        <span class="shrink-0 rounded-full bg-white px-2 py-1">Debit <span class="font-bold text-slate-900" data-balance-deduct>&#8358;0.00</span></span>
                        <span class="shrink-0 rounded-full bg-white px-2 py-1">Left <span class="font-bold text-slate-900" data-balance-remaining>&#8358;0.00</span></span>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <div class="text-xs text-slate-500">
                        Need help? Chat support.
                    </div>
                    <a href="{{ $whatsapp }}"
                       target="_blank"
                       class="text-sm font-extrabold text-emerald-700 transition hover:text-emerald-800">
                        Chat WhatsApp &rarr;
                    </a>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button"
                            class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-extrabold text-slate-700 transition hover:bg-slate-100"
                            data-modal-cancel="{{ $id }}">
                        Cancel
                    </button>

                    <button type="button"
                            class="rounded-2xl bg-[#d8b07a] px-4 py-3 font-extrabold text-slate-900 transition hover:bg-[#c99c60]"
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
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
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
            .filter(([k, v]) => !String(k).startsWith('__'))
            .filter(([,v]) => v !== undefined && v !== null && String(v).trim() !== '');

        if (entries.length === 0) {
            box.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-1.5 text-sm text-slate-500">No details provided.</div>';
            return;
        }

        box.innerHTML = entries.map(([k,v], index, arr) => {
            const key = escapeHtml(k);
            const val = escapeHtml(String(v));
            const edgeClass = index === 0
                ? 'rounded-t-2xl'
                : (index === arr.length - 1 ? 'rounded-b-2xl' : 'rounded-none');

            return `
                <div class="flex items-center justify-between gap-3 border border-slate-200 bg-slate-50 px-4 py-1.5 ${edgeClass}">
                    <div class="text-sm capitalize text-slate-500">${key}</div>
                    <div class="text-right text-sm font-extrabold text-slate-900">${val}</div>
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
                            form.requestSubmit();
                        } else {
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

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('[id$="_overlay"]').forEach(ov => {
            if (!ov.classList.contains('hidden')) hideOverlay(ov);
        });
    });
})();
</script>

