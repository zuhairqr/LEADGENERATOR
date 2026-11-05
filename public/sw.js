'use strict';

// Cache only this explicit public shell. Never cache HTML from authenticated routes,
// API responses, login pages or mutations. No background sync or automatic retries.
const CACHE_PREFIX = 'lead-generator-shell-';
const CACHE_NAME = `${CACHE_PREFIX}v1`;
const publicFiles = ['offline.html', 'app.css', 'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png'];
const publicUrls = publicFiles.map(file => new URL(file, self.registration.scope).href);
const offlineUrl = new URL('offline.html', self.registration.scope).href;

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(publicUrls)));
    // Let the browser activate after existing tabs close; do not interrupt edits.
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(
        keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
            .map(key => caches.delete(key))
    )).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request, { cache: 'no-store' }).catch(async () => {
            const cache = await caches.open(CACHE_NAME);
            const fallback = await cache.match(offlineUrl);
            if (fallback) {
                const base = self.registration.scope.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                const html = (await fallback.text()).replace('<head>', `<head><base href="${base}">`);
                return new Response(html, { status: 503, headers: {
                    'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'
                } });
            }
            return new Response('Offline. Reconnect to access Lead Generator.', {
                status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            });
        }));
        return;
    }

    if (publicUrls.includes(url.href)) {
        event.respondWith(caches.open(CACHE_NAME).then(async cache =>
            (await cache.match(request)) || fetch(request)
        ));
    }
    // All remaining requests go directly to the network, without Cache Storage.
});
