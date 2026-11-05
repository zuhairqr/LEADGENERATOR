import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('../public/', import.meta.url);
const workerSource = fs.readFileSync(new URL('sw.js', root), 'utf8');
const scope = 'https://leads.example.test/app/';

function worker({ offline = false, missing = false } = {}) {
    const listeners = {};
    const stored = [];
    const removed = [];
    const requests = [];
    let claimed = false;
    const cache = {
        addAll: async urls => stored.push(...urls),
        match: async input => {
            if (missing) return undefined;
            const url = typeof input === 'string' ? input : input.url;
            return new Response(url.endsWith('offline.html') ? '<html><head></head><body>Offline</body></html>' : 'public asset');
        }
    };
    vm.runInNewContext(workerSource, {
        URL, Response,
        self: {
            registration: { scope }, location: { origin: new URL(scope).origin },
            clients: { claim: async () => { claimed = true; } },
            addEventListener: (name, handler) => { listeners[name] = handler; }
        },
        caches: {
            open: async () => cache,
            keys: async () => ['lead-generator-shell-v0', 'lead-generator-shell-v1', 'another-app-cache'],
            delete: async key => { removed.push(key); }
        },
        fetch: async (request, options) => {
            requests.push({ request, options });
            if (offline) throw new Error('Network disconnected');
            return new Response('network-only private content');
        }
    });
    return {
        stored, removed, requests, claimed: () => claimed,
        lifecycle: async name => {
            let pending;
            listeners[name]({ waitUntil: promise => { pending = promise; } });
            await pending;
        },
        request: (path, { method = 'GET', mode = 'cors' } = {}) => {
            let response;
            listeners.fetch({ request: { url: new URL(path, scope).href, method, mode },
                respondWith: promise => { response = promise; } });
            return response;
        }
    };
}

test('install precaches only five explicit public shell assets, never account pages', async () => {
    const app = worker();
    await app.lifecycle('install');
    assert.deepEqual(app.stored.map(url => url.slice(scope.length)),
        ['offline.html', 'app.css', 'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png']);
    for (const url of app.stored) assert.ok(fs.existsSync(new URL(url.slice(scope.length), root)));
});

test('activation cleans this app’s old cache, not other applications', async () => {
    const app = worker();
    await app.lifecycle('activate');
    assert.deepEqual(app.removed, ['lead-generator-shell-v0']);
    assert.equal(app.claimed(), true);
});

test('private navigation is network-only and opts out of HTTP cache', async () => {
    const app = worker();
    const response = await app.request('leads/42', { mode: 'navigate' });
    assert.equal(await response.text(), 'network-only private content');
    assert.equal(app.requests[0].options.cache, 'no-store');
    assert.deepEqual(app.stored, []);
});

test('offline nested navigation has a scope-correct base and no private content', async () => {
    const app = worker({ offline: true });
    const response = await app.request('leads/42', { mode: 'navigate' });
    assert.equal(response.status, 503);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    const html = await response.text();
    assert.ok(html.includes(`<base href="${scope}">`));
    assert.ok(!html.includes('private content'));
});

test('missing offline shell returns explicit 503 rather than fabricated success', async () => {
    const app = worker({ offline: true, missing: true });
    const response = await app.request('./', { mode: 'navigate' });
    assert.equal(response.status, 503);
    assert.match(await response.text(), /Reconnect/);
});

test('mutations, API calls and other origins are not intercepted', () => {
    const app = worker({ offline: true });
    for (const method of ['POST', 'PATCH', 'PUT', 'DELETE']) {
        assert.equal(app.request('leads/42', { method }), undefined);
    }
    assert.equal(app.request('api/leads/import'), undefined);
    assert.equal(app.request('login'), undefined);
    assert.equal(app.request('https://other.example.test/app.css'), undefined);
    assert.equal(app.request('app.css?private=1'), undefined);
    assert.equal(app.requests.length, 0);
});

test('allowlisted shell assets can load offline', async () => {
    const app = worker({ offline: true });
    assert.equal(await (await app.request('app.css')).text(), 'public asset');
    assert.equal(app.requests.length, 0);
});

test('manifest uses standalone mode and valid real PNG icon dimensions', () => {
    const manifest = JSON.parse(fs.readFileSync(new URL('manifest.webmanifest', root), 'utf8'));
    assert.equal(manifest.display, 'standalone');
    assert.equal(manifest.scope, './');
    assert.equal(manifest.start_url, './');
    for (const size of [192, 512]) {
        const icon = manifest.icons.find(entry => entry.sizes === `${size}x${size}`);
        assert.ok(icon);
        const png = fs.readFileSync(new URL(icon.src, root));
        assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        assert.equal(png.readUInt32BE(16), size);
        assert.equal(png.readUInt32BE(20), size);
    }
});

function client({ online = true, secure = true } = {}) {
    const handlers = {};
    const elements = Object.fromEntries(['install-app', 'app-status', 'ios-install-help'].map(id =>
        [id, { hidden: true, textContent: '', addEventListener(name, handler) { this[name] = handler; } }]));
    const registrations = [];
    let reloads = 0;
    const navigator = { onLine: online, userAgent: 'iPhone', serviceWorker: {
        register: async (...args) => { registrations.push(args); }
    } };
    vm.runInNewContext(fs.readFileSync(new URL('pwa.js', root), 'utf8'), {
        URL, navigator,
        document: { currentScript: { src: `${scope}pwa.js` }, getElementById: id => elements[id] },
        window: { navigator, isSecureContext: secure, matchMedia: () => ({ matches: false }),
            location: { reload: () => { reloads++; } },
            addEventListener: (name, handler) => { handlers[name] = handler; } }
    });
    return { handlers, elements, registrations, reloads: () => reloads };
}

test('client registers a scope-relative worker only in secure contexts', () => {
    const app = client();
    app.handlers.load();
    assert.equal(app.registrations[0][0], `${scope}sw.js`);
    assert.equal(app.registrations[0][1].scope, scope);
    assert.equal(client({ secure: false }).handlers.load, undefined);
});

test('install button only prompts after browser event and user click', async () => {
    const app = client();
    const button = app.elements['install-app'];
    assert.equal(button.hidden, true);
    let prompts = 0;
    app.handlers.beforeinstallprompt({ preventDefault() {}, prompt: async () => { prompts++; },
        userChoice: Promise.resolve({ outcome: 'accepted' }) });
    assert.equal(button.hidden, false);
    assert.equal(prompts, 0);
    await button.click();
    assert.equal(prompts, 1);
    assert.equal(button.hidden, true);
    await button.click();
    assert.equal(prompts, 1);
});

test('offline warning, iOS help and restored-page revalidation are wired', () => {
    const app = client({ online: false });
    assert.equal(app.elements['app-status'].hidden, false);
    assert.match(app.elements['app-status'].textContent, /Unsaved edits/);
    assert.equal(app.elements['ios-install-help'].hidden, false);
    app.handlers.pageshow({ persisted: false });
    assert.equal(app.reloads(), 0);
    app.handlers.pageshow({ persisted: true });
    assert.equal(app.reloads(), 1);
    app.handlers.appinstalled();
    assert.equal(app.elements['ios-install-help'].hidden, true);
});
