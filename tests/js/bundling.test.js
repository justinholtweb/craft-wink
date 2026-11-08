/**
 * The tracker and the delivery runtime, run for real against a stub DOM, loaded every way a site
 * can: published by winkTrackingScript() as classic scripts, bundled as CommonJS, and bundled as
 * an ES module through wink.mjs.
 *
 *     node tests/js/bundling.test.js
 *
 * The PHP suites can only read these files as text; this watches what they actually send.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { pathToFileURL } = require('url');

const dist = path.join(__dirname, '../../src/web/assets/tracking/dist/js');
const tracker = fs.readFileSync(path.join(dist, 'wink.min.js'), 'utf8');
const delivery = fs.readFileSync(path.join(dist, 'wink-delivery.js'), 'utf8');

let passed = 0;
let failed = 0;

async function check(label, test) {
    let result;
    try {
        result = await test();
    } catch (e) {
        result = e.stack || String(e);
    }
    if (result === true) {
        passed++;
        console.log('  ✓ ' + label);
    } else {
        failed++;
        console.log('  ✗ ' + label + '\n    ' + (typeof result === 'string' ? result : 'returned ' + JSON.stringify(result)));
    }
}

/** An element with attributes, optionally children. */
function element(attrs, children) {
    const a = Object.assign({}, attrs);
    return {
        children: children || [],
        getAttribute: (n) => (n in a ? a[n] : null),
        setAttribute: (n, v) => { a[n] = String(v); },
        hasAttribute: (n) => n in a,
        closest: () => null,
        attrs: a,
    };
}

/** A cache-safe block as DeliveryService renders it, for a visitor who is always in the variant. */
function cacheSafeBlock(handle) {
    const cfg = {
        id: 1, traffic: 100,
        variants: [{ h: 'control', w: 0, c: true }, { h: 'variant-a', w: 100, c: false }],
        cookie: '_wink_vid', days: 365, domain: '', consent: false,
        track: { url: '/wink/track', dnt: false },
    };
    return element({
        'data-wink-experiment': handle,
        'data-wink-delivery': 'cache-safe',
        'data-wink-config': JSON.stringify(cfg),
    }, [
        element({ 'data-wink-option': 'control', 'data-wink-fallback': '' }),
        element({ 'data-wink-option': 'variant-a' }),
    ]);
}

/**
 * A page: which elements are on it, and everything the scripts try to send. `readyState` is where
 * the parser is when the scripts run: `loading` for the injected tags, `interactive` for a
 * deferred or module bundle. `trackingConfig` goes on `configOn` — `body`, `html`, or `div` (an
 * element after the content) — and `content` is user content placed ahead of the blocks, in
 * document order after <html> and <body>. `withUrl` gives the scripts a URL parser.
 */
function page({ trackingConfig = null, configOn = 'body', content = [], blocks = [], cookie = '', readyState = 'loading', winkConfig, withUrl = false } = {}) {
    const sent = [];
    const timers = [];
    const intervals = [];
    const listeners = {};
    const on = (target) => (type, fn) => { (listeners[target + ':' + type] ||= []).push(fn); };

    const html = element({});
    const body = element({});
    const all = [html, body].concat(content, blocks);
    if (trackingConfig !== null) {
        if (configOn === 'div') {
            all.push(element({ 'data-wink-tracking-config': trackingConfig }));
        } else {
            (configOn === 'html' ? html : body).setAttribute('data-wink-tracking-config', trackingConfig);
        }
    }
    const matches = (el, selector) => {
        const m = selector.match(/^\[([\w-]+)(?:="?([^"\]]*)"?)?\]$/);
        return m && el.hasAttribute(m[1]) && (m[2] === undefined || el.getAttribute(m[1]) === m[2]);
    };

    const document = {
        readyState,
        cookie,
        referrer: '',
        documentElement: html,
        body,
        querySelector: (s) => all.find((el) => matches(el, s)) || null,
        querySelectorAll: (s) => all.filter((el) => matches(el, s)),
        addEventListener: on('document'),
    };

    function XMLHttpRequest() {}
    XMLHttpRequest.prototype.open = function (method, url) { this.url = url; };
    XMLHttpRequest.prototype.setRequestHeader = function () {};
    XMLHttpRequest.prototype.send = function (body) { sent.push({ url: this.url, events: JSON.parse(body).events }); };

    const window = {
        document,
        navigator: {},
        location: { href: 'https://shop.test/', pathname: '/', search: '', protocol: 'https:' },
        crypto: { randomUUID: () => '11111111-2222-4333-8444-555555555555' },
        addEventListener: on('window'),
        setTimeout(fn) { timers.push(fn); return timers.length; },
        setInterval(fn) { intervals.push(fn); return intervals.length; },
        XMLHttpRequest,
        fetch(url, init) { sent.push({ url, events: JSON.parse(init.body).events }); return Promise.resolve({}); },
        JSON, Math, Object, String, Uint8Array, encodeURIComponent, decodeURIComponent, unescape,
    };
    if (winkConfig !== undefined) {
        window._winkConfig = winkConfig;
    }
    if (withUrl) {
        window.URL = URL;
    }

    /** Fire every pending timer: the tracker's batch, without waiting for it. */
    const flush = () => timers.splice(0).forEach((fn) => fn());
    const fire = (key) => (listeners[key] || []).forEach((fn) => fn({ target: null }));

    return { window, document, sent, listeners, flush, fire };
}

/** Run a file as a classic script on the page, optionally with a CommonJS `module`. */
function run(p, source, module) {
    if (!p.context) {
        p.context = vm.createContext(p.window);
        p.window.window = p.context;
    }
    p.context.module = module;
    vm.runInContext(source, p.context);
    delete p.context.module;
    return p.context;
}

const injectedConfig = { endpoint: '/wink/track', batchInterval: 5, goals: [], requireConsent: false, cookies: {} };
const events = (p) => p.sent.flatMap((s) => s.events);

(async () => {
    console.log('\nTracker');

    await check('injected: window._winkConfig ahead of the file starts it straight away, as before', () => {
        const p = page({ blocks: [cacheSafeBlock('hero')], winkConfig: injectedConfig });
        run(p, delivery);
        const w = run(p, tracker);
        p.fire('document:DOMContentLoaded');
        p.flush();

        return events(p).length === 1 && events(p)[0].variant === 'variant-a'
            && p.sent[0].url === '/wink/track' || JSON.stringify(p.sent);
    });

    await check('bundled: nothing starts until Wink.start(), which reads data-wink-tracking-config', () => {
        const config = JSON.stringify({ endpoint: '/from-page/track', batchInterval: 7, goals: [] });
        const p = page({ trackingConfig: config, blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        run(p, delivery);
        const w = run(p, tracker);
        p.flush();
        const before = p.sent.length;

        const started = w.Wink.start();
        p.flush();

        return before === 0 && started === true && p.sent[0] && p.sent[0].url === '/from-page/track'
            && events(p)[0].experiment === 'hero' || JSON.stringify({ before, started, sent: p.sent });
    });

    await check('options passed to Wink.start() win over the page', () => {
        const p = page({ trackingConfig: JSON.stringify({ endpoint: '/from-page' }), blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        run(p, delivery);
        run(p, tracker).Wink.start({ endpoint: '/from-options' });
        p.flush();

        return p.sent[0] && p.sent[0].url === '/from-options' || JSON.stringify(p.sent);
    });

    await check('with no configuration anywhere (tracking off), Wink.start() starts nothing', () => {
        const p = page({ blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        run(p, delivery);
        const w = run(p, tracker);
        const started = w.Wink.start();
        w.Wink.convert('signup');
        p.flush();

        return started === false && w.Wink.started === false && p.sent.length === 0 || JSON.stringify({ started, sent: p.sent });
    });

    await check('malformed data-wink-tracking-config is ignored rather than thrown', () => {
        const p = page({ trackingConfig: '{not json', readyState: 'interactive' });
        const w = run(p, tracker);

        return w.Wink.start() === false && p.sent.length === 0;
    });

    await check('the page’s requireConsent holds for a bundled tracker: no consent cookie, nothing sent', () => {
        const p = page({ trackingConfig: JSON.stringify({ endpoint: '/wink/track', requireConsent: true, cookies: { consent: '_wink_consent' } }), blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        const w = run(p, tracker);
        w.Wink.start();
        w.Wink.convert('signup');
        p.flush();

        return p.sent.length === 0 || JSON.stringify(p.sent);
    });

    await check('a CommonJS bundler gets the same object as window.Wink', () => {
        const p = page();
        const module = { exports: {} };
        const w = run(p, tracker, module);

        return typeof w.Wink.start === 'function' && module.exports === w.Wink;
    });

    await check('loaded twice, the first copy wins: one set of listeners, one impression, bundler gets it', () => {
        const p = page({ blocks: [cacheSafeBlock('hero')], winkConfig: injectedConfig });
        run(p, delivery);
        const first = run(p, tracker).Wink;
        const module = { exports: {} };
        const second = run(p, tracker, module).Wink;
        p.fire('document:DOMContentLoaded');
        p.flush();

        return second === first && module.exports === first
            && (p.listeners['document:click'] || []).length === 1 && events(p).length === 1
            || JSON.stringify({ same: second === first, clicks: (p.listeners['document:click'] || []).length, events: events(p).length });
    });

    await check('bundled first and unstarted, the injected copy that follows still starts it', () => {
        const p = page({ blocks: [cacheSafeBlock('hero')] });
        run(p, delivery);
        const bundled = run(p, tracker, { exports: {} }).Wink;
        p.window._winkConfig = injectedConfig;
        run(p, tracker);
        p.fire('document:DOMContentLoaded');
        p.flush();

        return bundled.started === true && events(p).length === 1 || JSON.stringify({ started: bundled.started, events: events(p).length });
    });

    console.log('\nForged configuration (5.4.1)');

    const forged = (config) => element({ 'data-wink-tracking-config': JSON.stringify(config) });
    const evil = { endpoint: 'https://evil.test/collect', cookies: { consent: 'always_present' } };

    await check('the page config on <body> is used, and on <html>', () => {
        const urls = ['body', 'html'].map((configOn) => {
            const p = page({ trackingConfig: JSON.stringify({ endpoint: '/on-' + configOn }), configOn, blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
            const w = run(p, tracker);
            w.Wink.start();
            w.Wink.convert('signup');
            p.flush();
            return p.sent[0] && p.sent[0].url;
        });

        return urls.join() === '/on-body,/on-html' || JSON.stringify(urls);
    });

    await check('a forged config in user content is ignored: no config on <body>, nothing starts', () => {
        const p = page({ content: [forged(evil)], blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        const w = run(p, tracker);
        const started = w.Wink.start();
        p.flush();

        return started === false && p.sent.length === 0 || JSON.stringify({ started, sent: p.sent });
    });

    await check('a forged config ahead of one on another element: neither is read', () => {
        const p = page({ content: [forged(evil)], trackingConfig: JSON.stringify({ endpoint: '/wink/track' }), configOn: 'div', blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        const w = run(p, tracker);
        const started = w.Wink.start();
        p.flush();

        return started === false && p.sent.length === 0 || JSON.stringify({ started, sent: p.sent });
    });

    await check('a forged consent cookie name cannot switch consent on (config on a <div>, as 5.4.0 allowed)', () => {
        const p = page({
            content: [forged(evil)],
            trackingConfig: JSON.stringify({ endpoint: '/wink/track', requireConsent: true }),
            configOn: 'div',
            cookie: 'always_present=1',
            blocks: [cacheSafeBlock('hero')],
            readyState: 'interactive',
        });
        const w = run(p, tracker);
        w.Wink.start();
        w.Wink.convert('signup');
        p.flush();

        return p.sent.length === 0 || JSON.stringify(p.sent);
    });

    const endpointCases = [
        ['https://evil.test/collect', '/wink/track'],
        ['//evil.test/collect', '/wink/track'],
        ['/\\evil.test/collect', '/wink/track'],
        ['\t//evil.test/collect', '/wink/track'],
        ['/\t/evil.test/collect', '/wink/track'],
        ['\u0001 //evil.test/collect', '/wink/track'],
        ['javascript:alert(1)', '/wink/track'],
        ['  /custom/track', '/custom/track'],
    ];
    for (const withUrl of [false, true]) {
        const origin = withUrl ? 'https://shop.test' : '';  // what an accepted path resolves to
        for (const source of ['page', 'window._winkConfig']) {
            await check('cross-origin endpoints fall back to /wink/track (' + source + ', ' + (withUrl ? 'URL' : 'no URL parser') + ')', () => {
                const got = endpointCases.map(([endpoint]) => {
                    const p = source === 'page'
                        ? page({ trackingConfig: JSON.stringify({ endpoint }), blocks: [cacheSafeBlock('hero')], readyState: 'interactive', withUrl })
                        : page({ winkConfig: Object.assign({}, injectedConfig, { endpoint }), blocks: [cacheSafeBlock('hero')], readyState: 'interactive', withUrl });
                    const w = run(p, tracker);
                    w.Wink.start();
                    w.Wink.convert('signup');
                    p.flush();
                    return p.sent[0] && p.sent[0].url;
                });
                // Rejected: the default, as written. Accepted: as the URL parser resolved it.
                const want = endpointCases.map(([, w]) => (w === '/wink/track' ? w : origin + w));

                return JSON.stringify(got) === JSON.stringify(want) || JSON.stringify({ got, want });
            });
        }
    }

    await check('URL parser: a same-origin absolute endpoint is kept; another port or scheme is not', () => {
        const got = ['https://shop.test/abs', 'https://shop.test:8443/x', 'http://shop.test/x'].map((endpoint) => {
            const p = page({ trackingConfig: JSON.stringify({ endpoint }), blocks: [cacheSafeBlock('hero')], readyState: 'interactive', withUrl: true });
            const w = run(p, tracker);
            w.Wink.start();
            w.Wink.convert('signup');
            p.flush();
            return p.sent[0] && p.sent[0].url;
        });

        return got.join() === 'https://shop.test/abs,/wink/track,/wink/track' || JSON.stringify(got);
    });

    await check('an endpoint passed to Wink.start() is the site’s own code and is used as given', () => {
        const p = page({ trackingConfig: JSON.stringify({ endpoint: '/wink/track' }), blocks: [cacheSafeBlock('hero')], readyState: 'interactive', withUrl: true });
        const w = run(p, tracker);
        w.Wink.start({ endpoint: 'https://collector.shop.test/track' });
        w.Wink.convert('signup');
        p.flush();

        return p.sent[0] && p.sent[0].url === 'https://collector.shop.test/track' || JSON.stringify(p.sent);
    });

    await check('bad cookie names fall back to the defaults, from the page, _winkConfig and Wink.start()', () => {
        const bad = { consent: 'x; domain=evil.test', visitor: 'a=b', domain: 'shop.test; SameSite=None' };
        const written = [];
        const results = ['page', 'winkConfig', 'options'].map((source) => {
            const p = page({
                trackingConfig: source === 'page' ? JSON.stringify({ endpoint: '/wink/track', cookies: bad }) : null,
                winkConfig: source === 'winkConfig' ? Object.assign({}, injectedConfig, { cookies: bad }) : undefined,
                readyState: 'interactive',
            });
            let jar = '';
            Object.defineProperty(p.document, 'cookie', { get: () => jar, set: (v) => { written.push(v); jar = v; }, configurable: true });
            const w = run(p, tracker);
            if (source === 'options') {
                w.Wink.start({ endpoint: '/wink/track', cookies: bad });
            }
            w.Wink.consent();
            w.Wink.revokeConsent();
            return written.splice(0);
        });
        const ok = results.every((r) => r.length === 2 && r.every((c) => /^_wink_consent=1?; path=\/; max-age=\d+; SameSite=Lax; Secure$/.test(c)));

        return ok || JSON.stringify(results);
    });

    await check('wink.min.js and wink-delivery.js still parse as classic scripts (no module syntax)', () => {
        new vm.Script(tracker);
        new vm.Script(delivery);
        return true;
    });

    console.log('\nDelivery runtime');

    await check('loaded twice (inlined, then bundled), a CommonJS bundler gets the first instance', () => {
        const p = page();
        const first = run(p, delivery).WinkDelivery;
        const module = { exports: {} };
        run(p, delivery, module);

        return module.exports === first && p.context.WinkDelivery === first;
    });

    await check('bundled to run after parsing, it reveals a block whose inline script was blocked', () => {
        const block = cacheSafeBlock('hero');
        const p = page({ blocks: [block], readyState: 'interactive' });
        run(p, delivery);
        p.fire('document:DOMContentLoaded');

        const chosen = block.children.filter((c) => c.hasAttribute('data-wink-chosen')).map((c) => c.getAttribute('data-wink-option'));

        return chosen.join() === 'variant-a' || JSON.stringify(chosen);
    });

    await check('with the tracker loaded but not started, it still sends the impression itself', () => {
        const p = page({ blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        run(p, delivery);
        run(p, tracker);           // bundled, Wink.start() never called
        p.fire('window:load');

        return events(p).length === 1 && events(p)[0].vid === '11111111-2222-4333-8444-555555555555' || JSON.stringify(p.sent);
    });

    await check('with the tracker started, it leaves the impression to the tracker', () => {
        const p = page({ blocks: [cacheSafeBlock('hero')], winkConfig: injectedConfig });
        run(p, delivery);
        run(p, tracker);
        p.fire('document:DOMContentLoaded');
        p.fire('window:load');
        p.flush();

        return events(p).length === 1 || JSON.stringify(p.sent);
    });

    /** A cache-safe block whose data-wink-config has been forged, for a visitor in the variant. */
    const forgedBlock = (handle, changes) => {
        const block = cacheSafeBlock(handle);
        block.setAttribute('data-wink-config', JSON.stringify(Object.assign(JSON.parse(block.getAttribute('data-wink-config')), changes)));
        return block;
    };

    for (const withUrl of [false, true]) {
        await check('a block config that sends impressions off-origin sends nothing (' + (withUrl ? 'URL' : 'no URL parser') + ')', () => {
            const urls = ['https://evil.test/c', '//evil.test/c', '/\\evil.test/c', '\t//evil.test/c', 'javascript:alert(1)'];
            const p = page({ blocks: urls.map((url, i) => forgedBlock('f' + i, { track: { url, dnt: false } })), readyState: 'interactive', withUrl });
            run(p, delivery);
            p.fire('window:load');

            return p.sent.length === 0 || JSON.stringify(p.sent);
        });
    }

    await check('a forged block after a real one cannot redirect the real block’s impression', () => {
        const p = page({ blocks: [cacheSafeBlock('hero'), forgedBlock('forged', { track: { url: '//evil.test/c', dnt: false } })], readyState: 'interactive' });
        run(p, delivery);
        p.fire('window:load');

        return p.sent.length === 1 && p.sent[0].url === '/wink/track' && events(p).map((e) => e.experiment).join() === 'hero'
            || JSON.stringify(p.sent);
    });

    await check('a forged block naming another path on this site cannot take the real block’s impression', () => {
        const p = page({ blocks: [cacheSafeBlock('hero'), forgedBlock('forged', { track: { url: '/actions/users/save-user', dnt: false } })], readyState: 'interactive' });
        run(p, delivery);
        p.fire('window:load');

        const to = (url) => p.sent.filter((s) => s.url === url).flatMap((s) => s.events.map((e) => e.experiment)).join();

        return to('/wink/track') === 'hero' && !to('/actions/users/save-user').includes('hero')
            || JSON.stringify(p.sent);
    });

    await check('a block config with a bad cookie name writes no cookie and shows the fallback', () => {
        const written = [];
        const blocks = [
            forgedBlock('a', { cookie: 'x; domain=evil.test' }),
            forgedBlock('b', { cookie: '_wink_vid', domain: 'shop.test; SameSite=None' }),
            forgedBlock('c', { consent: 'always present' }),
        ];
        const p = page({ blocks, readyState: 'interactive', cookie: 'always present=1' });
        Object.defineProperty(p.document, 'cookie', { get: () => '', set: (v) => written.push(v), configurable: true });
        run(p, delivery);
        p.fire('window:load');
        const chosen = blocks.map((b) => b.children.filter((c) => c.hasAttribute('data-wink-chosen')).map((c) => c.getAttribute('data-wink-option')).join());

        return chosen.join('|') === 'control|variant-a|control'
            && written.length === 1 && /^_wink_vid=[^;]+; path=\/; max-age=\d+; SameSite=Lax; Secure$/.test(written[0])
            || JSON.stringify({ chosen, written });
    });

    console.log('\nES module');

    await check('wink.mjs default-exports the tracker, names WinkDelivery, and still sets both globals', async () => {
        const config = JSON.stringify({ endpoint: '/wink/track', goals: [] });
        const p = page({ trackingConfig: config, blocks: [cacheSafeBlock('hero')], readyState: 'interactive' });
        const keys = ['window', 'document', 'location', 'XMLHttpRequest', 'setTimeout', 'setInterval', 'addEventListener'];
        const saved = keys.map((k) => Object.getOwnPropertyDescriptor(globalThis, k));
        const set = (k, v) => Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true });

        set('window', globalThis);
        set('document', p.document);
        set('location', p.window.location);
        set('XMLHttpRequest', p.window.XMLHttpRequest);
        set('setTimeout', p.window.setTimeout);
        set('setInterval', p.window.setInterval);
        set('addEventListener', p.window.addEventListener);

        try {
            const mod = await import(pathToFileURL(path.join(dist, 'wink.mjs')).href);
            mod.default.start();
            p.flush();

            return mod.default === globalThis.Wink && mod.WinkDelivery === globalThis.WinkDelivery
                && typeof mod.WinkDelivery.assign === 'function'
                && events(p).length === 1 && events(p)[0].variant === 'variant-a'
                || JSON.stringify({ sent: p.sent, same: mod.default === globalThis.Wink });
        } finally {
            keys.forEach((k, i) => (saved[i] ? Object.defineProperty(globalThis, k, saved[i]) : delete globalThis[k]));
            delete globalThis.Wink;
            delete globalThis.WinkDelivery;
        }
    });

    console.log('\n' + passed + ' passed, ' + failed + ' failed');
    process.exit(failed ? 1 : 0);
})();
