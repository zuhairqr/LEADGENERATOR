'use strict';

(() => {
    const button = document.getElementById('install-app');
    const status = document.getElementById('app-status');
    const iosHelp = document.getElementById('ios-install-help');
    let installPrompt = null;
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

    function updateConnection() {
        if (!status) return;
        status.hidden = navigator.onLine;
        status.textContent = navigator.onLine ? '' : 'Offline: saving and AI requests need a connection. Unsaved edits are not backed up.';
    }
    window.addEventListener('online', updateConnection);
    window.addEventListener('offline', updateConnection);
    updateConnection();

    window.addEventListener('beforeinstallprompt', event => {
        if (!button || standalone) return;
        event.preventDefault();
        installPrompt = event;
        button.hidden = false;
    });
    if (button) button.addEventListener('click', async () => {
        if (!installPrompt) return;
        const prompt = installPrompt;
        installPrompt = null;
        button.hidden = true;
        try {
            await prompt.prompt();
            await prompt.userChoice;
        } catch (_) {
            // Browser install support varies; the browser menu remains available.
        }
    });
    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        if (button) button.hidden = true;
        if (iosHelp) iosHelp.hidden = true;
    });
    if (iosHelp && !standalone && /iphone|ipad|ipod/i.test(navigator.userAgent)) iosHelp.hidden = false;

    // Revalidate authentication when a browser restores a previously displayed page.
    window.addEventListener('pageshow', event => {
        if (event.persisted) window.location.reload();
    });

    if ('serviceWorker' in navigator && window.isSecureContext) {
        const script = document.currentScript;
        const worker = new URL('sw.js', script.src);
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(worker.href, { scope: new URL('./', worker).href })
                .catch(() => {
                    // Normal online dashboard use does not depend on PWA support.
                    if (status && navigator.onLine) {
                        status.hidden = false;
                        status.textContent = 'Offline support is unavailable. You can still use the dashboard online.';
                    }
                });
        });
    }
})();
