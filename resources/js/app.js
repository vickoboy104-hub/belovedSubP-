import './bootstrap';

import Alpine from 'alpinejs';

// The Battery Status API is available only in some secure-context browsers.
// Never show a guessed percentage when the device declines to expose it.
async function showDeviceBattery() {
    const badges = document.querySelectorAll('[data-device-battery]');
    if (!badges.length) return;

    const setText = (value) => badges.forEach((badge) => { badge.textContent = value; });
    if (!window.isSecureContext || typeof navigator.getBattery !== 'function') {
        setText('Battery unavailable');
        return;
    }

    try {
        const battery = await navigator.getBattery();
        const refresh = () => {
            const level = Number(battery.level);
            setText(Number.isFinite(level) && level >= 0 && level <= 1
                ? `▰ ${Math.round(level * 100)}%${battery.charging ? ' · Charging' : ''}`
                : 'Battery unavailable');
        };
        refresh();
        battery.addEventListener('levelchange', refresh);
        battery.addEventListener('chargingchange', refresh);
    } catch (_error) {
        setText('Battery unavailable');
    }
}

document.addEventListener('DOMContentLoaded', showDeviceBattery);

window.Alpine = Alpine;
document.body.classList.add('page-is-entering');

// ==============================
// THEME BASELINE
// ==============================
(function () {
    const html = document.documentElement;
    html.classList.remove('dark');
    localStorage.setItem('theme', 'light');
})();


// ==============================
// VIEWPORT LAYERS
// ==============================
(function () {
    const layerSelector = [
        '#globalLoader',
        '#ninPopupOverlay',
        '#flashToast',
        '#transactionResultOverlay',
        '#transactionContinueOverlay',
        '#maintenanceOverlay',
        '.page-save-overlay',
        '[id$="_overlay"]',
        '[id$="Overlay"]',
    ].join(',');

    function isViewportLayer(element) {
        if (!(element instanceof HTMLElement)) return false;
        if (!element.matches(layerSelector)) return false;

        const style = window.getComputedStyle(element);
        return element.classList.contains('fixed') || style.position === 'fixed';
    }

    function promoteViewportLayer(element) {
        if (!isViewportLayer(element)) return;
        if (element.parentElement === document.body) return;
        if (element.dataset.viewportLayerPromoted === '1') return;

        element.dataset.viewportLayerPromoted = '1';
        document.body.appendChild(element);
    }

    function scanViewportLayers(root = document) {
        if (!(root instanceof Document || root instanceof HTMLElement)) return;

        if (root instanceof HTMLElement) {
            promoteViewportLayer(root);
        }

        root.querySelectorAll(layerSelector).forEach((element) => {
            promoteViewportLayer(element);
        });
    }

    function boot() {
        scanViewportLayers();

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof HTMLElement)) return;
                    scanViewportLayers(node);
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    }

    window.promoteViewportLayer = promoteViewportLayer;

    if (document.body) {
        boot();
    } else {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    }
})();


// ==============================
// NIN MARQUEE (Every 30 sec)
// ==============================
(function () {
    const marquee = document.getElementById('ninMarquee');
    if (!marquee) return;

    const runMarquee = () => {
        marquee.classList.remove('nin-marquee-animate');
        void marquee.offsetWidth; // restart animation
        marquee.classList.add('nin-marquee-animate');
    };

    runMarquee();
    setInterval(runMarquee, 30000);
})();


// ==============================
// DASHBOARD POPUP ONLY
// ==============================
(function () {
    const overlay = document.getElementById('ninPopupOverlay');
    if (!overlay) return;

    if (overlay.parentElement !== document.body) {
        document.body.appendChild(overlay);
    }

    const closeBtn = document.getElementById('ninPopupClose');
    const laterBtn = document.getElementById('ninPopupLater');
    const mode = overlay.dataset.popupMode || 'once';
    const key = overlay.dataset.popupKey || 'nin_popup_seen';

    const showPopup = () => {
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    };

    if (mode === 'always') {
        showPopup();
    } else {
        const seen = sessionStorage.getItem(key);
        if (!seen) {
            showPopup();
            sessionStorage.setItem(key, 'yes');
        }
    }

    const closePopup = () => {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
    };

    if (closeBtn) closeBtn.addEventListener('click', closePopup);
    if (laterBtn) laterBtn.addEventListener('click', closePopup);
})();

// ==============================
// PAGE TRANSITION LOADER
// ==============================
(function () {
    const loader = document.getElementById('globalLoader');
    const loaderText = document.getElementById('globalLoaderText');
    if (!loader) return;

    let pending = false;

    const showLoader = (text = 'Loading...') => {
        if (loaderText) loaderText.textContent = text;
        loader.classList.remove('hidden');
        loader.classList.add('flex');
        pending = true;
    };

    const hideLoader = () => {
        loader.classList.add('hidden');
        loader.classList.remove('flex');
        pending = false;
    };

    window.showGlobalLoader = showLoader;
    window.hideGlobalLoader = hideLoader;

    window.addEventListener('pageshow', () => {
        hideLoader();
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href') || '';
        if (
            href.startsWith('#') ||
            href.startsWith('javascript:') ||
            link.hasAttribute('download') ||
            link.target === '_blank' ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        const url = new URL(link.href, window.location.origin);
        if (url.origin !== window.location.origin) return;
        if (url.href === window.location.href) return;

        showLoader('Opening page...');
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (pending) return;

        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'get') {
            showLoader('Loading results...');
            return;
        }

        showLoader('Processing...');
    });
})();

// ==============================
// CONTACT PICKER
// ==============================
(function () {
    const supported = !!(navigator.contacts && typeof navigator.contacts.select === 'function' && window.isSecureContext);

    const normalizePhone = (value) => String(value || '')
        .replace(/[^\d+]+/g, '')
        .replace(/(?!^)\+/g, '')
        .trim();

    const ensureButtonLabel = (button) => {
        if (button.querySelector('.contact-picker-btn-label')) return;

        const label = document.createElement('span');
        label.className = 'contact-picker-btn-label';
        label.textContent = button.getAttribute('data-contact-picker-label') || 'Pick contact';
        button.appendChild(label);
    };

    const setBusyState = (button, busy) => {
        if (!(button instanceof HTMLButtonElement)) return;
        const label = button.querySelector('.contact-picker-btn-label');

        if (busy) {
            button.disabled = true;
            button.dataset.pickerBusy = '1';
            button.setAttribute('aria-busy', 'true');
            if (label) label.textContent = 'Opening...';
            return;
        }

        button.disabled = false;
        delete button.dataset.pickerBusy;
        button.removeAttribute('aria-busy');
        if (label) {
            label.textContent = button.getAttribute('data-contact-picker-label') || 'Pick contact';
        }
    };

    const getInput = (button) => {
        const selector = button.getAttribute('data-contact-picker-target');
        return selector ? document.querySelector(selector) : null;
    };

    const initButton = (button) => {
        if (!(button instanceof HTMLButtonElement)) return;
        if (button.dataset.contactPickerReady === '1') return;

        const input = getInput(button);
        if (!input) return;

        button.dataset.contactPickerReady = '1';
        ensureButtonLabel(button);
        button.setAttribute('title', 'Choose from phone contacts');

        if (supported) {
            button.classList.remove('hidden');
            button.classList.add('inline-flex');
        }

        button.addEventListener('click', async () => {
            if (!supported) {
                input.focus();
                return;
            }

            try {
                setBusyState(button, true);
                const contacts = await navigator.contacts.select(['name', 'tel'], { multiple: false });
                const picked = contacts && contacts[0];
                const tel = picked && Array.isArray(picked.tel)
                    ? picked.tel.find((value) => String(value || '').trim() !== '') || ''
                    : '';
                if (!tel) return;

                input.value = normalizePhone(tel) || tel;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.focus();
            } catch (error) {
                // User cancelled or browser denied access; keep manual input available.
            } finally {
                setBusyState(button, false);
            }
        });
    };

    const initButtons = (root = document) => {
        if (root instanceof HTMLButtonElement && root.matches('[data-contact-picker-button]')) {
            initButton(root);
            return;
        }

        if (!(root instanceof Document || root instanceof HTMLElement)) return;

        root.querySelectorAll('[data-contact-picker-button]').forEach((button) => {
            initButton(button);
        });
    };

    window.initContactPickerButtons = initButtons;
    initButtons();

    if (document.body) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof HTMLElement)) return;
                    initButtons(node);
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    }
})();

// ==============================
// ADMIN RICH TEXT EDITORS
// ==============================
(function () {
    const roots = Array.from(document.querySelectorAll('[data-rich-editor-root]'));
    if (roots.length === 0) return;

    const syncEditor = (root) => {
        const targetId = root.getAttribute('data-editor-input');
        const surface = root.querySelector('[data-rich-editor-surface]');
        const textarea = targetId ? document.getElementById(targetId) : null;
        if (!(surface instanceof HTMLElement) || !(textarea instanceof HTMLTextAreaElement)) return;

        const html = surface.innerHTML
            .replace(/<(div|p)><br><\/\1>/gi, '')
            .replace(/&nbsp;/gi, ' ')
            .trim();

        textarea.value = html;
    };

    const focusSurface = (root) => {
        const surface = root.querySelector('[data-rich-editor-surface]');
        if (!(surface instanceof HTMLElement)) return null;

        surface.focus();
        return surface;
    };

    roots.forEach((root) => {
        const surface = root.querySelector('[data-rich-editor-surface]');
        if (!(surface instanceof HTMLElement)) return;

        surface.addEventListener('input', () => syncEditor(root));
        surface.addEventListener('blur', () => syncEditor(root));
        surface.addEventListener('paste', () => {
            window.setTimeout(() => syncEditor(root), 0);
        });

        syncEditor(root);
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-rich-editor-action]');
        if (!(button instanceof HTMLButtonElement)) return;

        const root = button.closest('[data-rich-editor-root]');
        if (!(root instanceof HTMLElement)) return;

        const command = button.getAttribute('data-rich-editor-action');
        if (!command) return;

        const surface = focusSurface(root);
        if (!(surface instanceof HTMLElement)) return;

        event.preventDefault();

        const value = button.getAttribute('data-editor-value');
        document.execCommand(command, false, value ?? null);
        syncEditor(root);
    });
})();

// ==============================
// IDLE GUIDE
// ==============================
(function () {
    const idleDelay = 9000;
    let idleTimer = null;
    let layer = null;
    let bubble = null;
    let hand = null;
    let label = null;
    let currentTarget = null;
    let lastPointerMoveAt = 0;

    function ensureGuide() {
        if (layer) return;

        layer = document.createElement('div');
        layer.id = 'idleGuideLayer';
        layer.className = 'idle-guide-layer';
        layer.setAttribute('aria-hidden', 'true');
        layer.innerHTML = `
            <div class="idle-guide-bubble" id="idleGuideBubble">
                <div class="idle-guide-bubble-card">
                    <div class="idle-guide-bot" aria-hidden="true">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="4.5" width="14" height="13" rx="4"></rect>
                            <path d="M12 2.5v2"></path>
                            <path d="M9 20h6"></path>
                            <circle cx="9.5" cy="10.5" r="0.9" fill="currentColor" stroke="none"></circle>
                            <circle cx="14.5" cy="10.5" r="0.9" fill="currentColor" stroke="none"></circle>
                            <path d="M9 13.8c.8.7 1.8 1 3 1s2.2-.3 3-1"></path>
                        </svg>
                    </div>
                    <div class="idle-guide-copy" id="idleGuideLabel">Start here</div>
                </div>
            </div>
            <div class="idle-guide-hand" id="idleGuideHand">
                <div class="idle-guide-hand-icon">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8.5 11.5V6.8a1.2 1.2 0 0 1 2.4 0v3.4"></path>
                        <path d="M10.9 10.7V5.7a1.2 1.2 0 0 1 2.4 0v5"></path>
                        <path d="M13.3 10.9V6.9a1.2 1.2 0 0 1 2.4 0v5.6"></path>
                        <path d="M15.7 11.7V9.5a1.2 1.2 0 0 1 2.4 0v4.4c0 3-2.2 5.5-5.1 5.9l-1.7.2a4.7 4.7 0 0 1-5-3.2l-1-3a1.5 1.5 0 0 1 2.8-1.2l.8 1.7"></path>
                    </svg>
                </div>
            </div>
        `;

        document.body.appendChild(layer);
        bubble = document.getElementById('idleGuideBubble');
        hand = document.getElementById('idleGuideHand');
        label = document.getElementById('idleGuideLabel');
    }

    function isElementVisible(element) {
        if (!(element instanceof HTMLElement)) return false;
        if (!element.isConnected || element.hidden || element.closest('.hidden,[hidden],[aria-hidden="true"]')) return false;

        const style = window.getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return false;

        const rect = element.getBoundingClientRect();
        return rect.width > 0
            && rect.height > 0
            && rect.bottom > 72
            && rect.right > 0
            && rect.top < window.innerHeight - 20
            && rect.left < window.innerWidth - 12;
    }

    function isBlockingUiVisible() {
        const selectors = [
            '#globalLoader',
            '#flashToast',
            '#ninPopupOverlay',
            '#transactionResultOverlay',
            '#transactionContinueOverlay',
            '[id$="_overlay"]',
            '[aria-modal="true"]',
        ];

        return Array.from(document.querySelectorAll(selectors.join(','))).some((element) => {
            if (!(element instanceof HTMLElement)) return false;
            if (element.classList.contains('hidden')) return false;
            return isElementVisible(element);
        });
    }

    function clearTargetState() {
        if (currentTarget instanceof HTMLElement) {
            currentTarget.classList.remove('idle-guide-target');
        }
        currentTarget = null;
    }

    function hideGuide() {
        if (layer) {
            layer.classList.remove('is-visible');
        }
        clearTargetState();
    }

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    function getTargetAnchor(target, rect) {
        const isField = target.matches('input, select, textarea');
        const isButton = target.matches('button, a, [role="button"]');
        const centerX = rect.left + (rect.width / 2);
        const centerY = rect.top + (rect.height / 2);

        if (isField) {
            return {
                x: rect.left + Math.min(Math.max(rect.width * 0.16, 28), 64),
                y: centerY,
            };
        }

        if (isButton) {
            return {
                x: centerX,
                y: centerY,
            };
        }

        return {
            x: rect.left + Math.min(Math.max(rect.width * 0.18, 30), 70),
            y: rect.top + Math.min(Math.max(rect.height * 0.28, 22), 52),
        };
    }

    function needsAttention(field) {
        if (!(field instanceof HTMLElement) || !isElementVisible(field)) return false;
        if (field.hasAttribute('data-idle-guide-skip')) return false;
        if (field.matches('input[type="hidden"], input[type="button"], input[type="submit"], input[type="reset"]')) return false;
        if (field.matches('input[type="checkbox"], input[type="radio"]')) return false;
        if ('disabled' in field && field.disabled) return false;
        if ('readOnly' in field && field.readOnly) return false;

        if (field.tagName === 'SELECT') {
            return field.hasAttribute('required') && field.value === '';
        }

        if (field.tagName === 'TEXTAREA') {
            return field.hasAttribute('required') && String(field.value || '').trim() === '';
        }

        if (field.tagName === 'INPUT') {
            return field.hasAttribute('required') && String(field.value || '').trim() === '';
        }

        return false;
    }

    function findFormTarget(form) {
        if (!(form instanceof HTMLFormElement) || !isElementVisible(form)) return null;

        const fields = Array.from(form.querySelectorAll('input, select, textarea'));
        const pendingField = fields.find(needsAttention);
        if (pendingField) {
            return {
                element: pendingField,
                label: pendingField.tagName === 'SELECT' ? 'Choose here' : 'Start here',
            };
        }

        const actions = Array.from(form.querySelectorAll('[data-idle-guide-target], button.btn-primary, button[type="submit"], input[type="submit"], a.btn-primary'));
        const action = actions.find((candidate) => {
            if (!(candidate instanceof HTMLElement)) return false;
            if (!isElementVisible(candidate)) return false;
            if ('disabled' in candidate && candidate.disabled) return false;
            return true;
        });

        if (!action) return null;

        return {
            element: action,
            label: action.tagName === 'A' ? 'Open this' : 'Continue here',
        };
    }

    function findPageTarget() {
        if (document.visibilityState !== 'visible') return null;
        if (isBlockingUiVisible()) return null;

        const active = document.activeElement;
        if (active instanceof HTMLElement && active.matches('input, select, textarea, button')) {
            return null;
        }

        const forms = Array.from(document.querySelectorAll('main form'));
        for (const form of forms) {
            const match = findFormTarget(form);
            if (match) return match;
        }

        const fallbackTargets = Array.from(document.querySelectorAll('main [data-idle-guide-target], main a.btn-primary, main button.btn-primary, main .app-service-card'));
        const fallback = fallbackTargets.find((candidate) => {
            if (!(candidate instanceof HTMLElement)) return false;
            if (!isElementVisible(candidate)) return false;
            if ('disabled' in candidate && candidate.disabled) return false;
            return true;
        });

        if (!fallback) return null;

        return {
            element: fallback,
            label: fallback.tagName === 'A' ? 'Open this' : 'Continue here',
        };
    }

    function positionGuide(target) {
        if (!(target instanceof HTMLElement)) return;

        ensureGuide();

        const rect = target.getBoundingClientRect();
        const anchor = getTargetAnchor(target, rect);
        const bubbleRect = bubble.getBoundingClientRect();
        const handRect = hand.getBoundingClientRect();
        const margin = 14;
        const viewportPadding = 12;
        const mobileViewport = window.innerWidth < 768;
        let bubbleX;
        let bubbleY;
        let handX = clamp(anchor.x - (handRect.width / 2), viewportPadding, window.innerWidth - handRect.width - viewportPadding);
        let handY = clamp(anchor.y - (handRect.height / 2), viewportPadding, window.innerHeight - handRect.height - viewportPadding);

        if (mobileViewport) {
            const canFitAbove = rect.top - bubbleRect.height - margin >= viewportPadding;
            const preferAbove = canFitAbove && rect.top > window.innerHeight * 0.42;

            bubbleX = clamp(anchor.x - (bubbleRect.width / 2), viewportPadding, window.innerWidth - bubbleRect.width - viewportPadding);
            bubbleY = preferAbove
                ? rect.top - bubbleRect.height - margin
                : rect.bottom + margin;

            if (bubbleY + bubbleRect.height > window.innerHeight - viewportPadding) {
                bubbleY = rect.top - bubbleRect.height - margin;
            }

            bubbleY = clamp(bubbleY, viewportPadding, window.innerHeight - bubbleRect.height - viewportPadding);
            handY = clamp(preferAbove ? rect.top - (handRect.height * 0.4) : rect.top + Math.min(rect.height * 0.15, 12), viewportPadding, window.innerHeight - handRect.height - viewportPadding);
        } else {
            const fitsRight = rect.right + margin + bubbleRect.width <= window.innerWidth - viewportPadding;
            const fitsLeft = rect.left - margin - bubbleRect.width >= viewportPadding;

            if (fitsRight || (!fitsLeft && rect.left < window.innerWidth * 0.48)) {
                bubbleX = rect.right + margin;
            } else {
                bubbleX = rect.left - bubbleRect.width - margin;
            }

            if (bubbleX < viewportPadding || bubbleX + bubbleRect.width > window.innerWidth - viewportPadding) {
                bubbleX = clamp(anchor.x - (bubbleRect.width / 2), viewportPadding, window.innerWidth - bubbleRect.width - viewportPadding);
            }

            bubbleY = clamp(anchor.y - (bubbleRect.height / 2), viewportPadding, window.innerHeight - bubbleRect.height - viewportPadding);
            handX = clamp(anchor.x - (handRect.width / 2), viewportPadding, window.innerWidth - handRect.width - viewportPadding);
        }

        bubble.style.transform = `translate3d(${Math.round(bubbleX)}px, ${Math.round(bubbleY)}px, 0)`;
        hand.style.transform = `translate3d(${Math.round(handX)}px, ${Math.round(handY)}px, 0)`;
    }

    function showGuide() {
        const targetInfo = findPageTarget();
        if (!targetInfo) {
            scheduleGuide();
            return;
        }

        ensureGuide();
        clearTargetState();

        currentTarget = targetInfo.element;
        currentTarget.classList.add('idle-guide-target');
        label.textContent = targetInfo.label;
        layer.classList.add('is-visible');
        window.requestAnimationFrame(() => {
            if (currentTarget === targetInfo.element) {
                positionGuide(currentTarget);
            }
        });
    }

    function scheduleGuide() {
        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(showGuide, idleDelay);
    }

    function registerActivity(event) {
        if (event?.type === 'pointermove') {
            const now = Date.now();
            if (now - lastPointerMoveAt < 400) return;
            lastPointerMoveAt = now;
        }

        hideGuide();
        scheduleGuide();
    }

    const listeners = [
        ['pointerdown', document],
        ['pointermove', document],
        ['keydown', document],
        ['input', document],
        ['change', document],
        ['focusin', document],
        ['touchstart', document],
        ['scroll', window],
        ['submit', document],
    ];

    listeners.forEach(([eventName, target]) => {
        target.addEventListener(eventName, registerActivity, { passive: eventName !== 'submit' });
    });

    window.addEventListener('resize', () => {
        if (layer && layer.classList.contains('is-visible') && currentTarget) {
            positionGuide(currentTarget);
        }
    }, { passive: true });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            hideGuide();
            window.clearTimeout(idleTimer);
            return;
        }

        scheduleGuide();
    });

    scheduleGuide();
})();

Alpine.start();
