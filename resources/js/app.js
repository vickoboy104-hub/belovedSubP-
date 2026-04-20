import './bootstrap';

import Alpine from 'alpinejs';

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
    const buttons = document.querySelectorAll('[data-contact-picker-button]');
    const getInput = (button) => {
        const selector = button.getAttribute('data-contact-picker-target');
        return selector ? document.querySelector(selector) : null;
    };

    buttons.forEach((button) => {
        const input = getInput(button);
        if (!input) return;

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
                const contacts = await navigator.contacts.select(['name', 'tel'], { multiple: false });
                const picked = contacts && contacts[0];
                const tel = picked && Array.isArray(picked.tel) ? picked.tel[0] : '';
                if (!tel) return;

                input.value = tel;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.focus();
            } catch (error) {
                // User cancelled or browser denied access; keep manual input available.
            }
        });
    });
})();

Alpine.start();
