import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// ==============================
// THEME SWITCH (Dark / Light)
// ==============================
(function () {
    const html = document.documentElement;
    const saved = localStorage.getItem('theme');

    // Default to DARK (because your site is currently dark)
    if (!saved) {
        html.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        html.classList.toggle('dark', saved === 'dark');
    }

    const btn = document.getElementById('themeToggle');
    if (btn) {
        btn.addEventListener('click', () => {
            const isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
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
