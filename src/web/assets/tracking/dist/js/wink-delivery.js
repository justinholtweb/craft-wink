/**
 * Wink cache-safe delivery runtime.
 *
 * A cache-safe experiment block holds every variant, hidden by CSS. This assigns the visitor
 * exactly as AssignmentService::assignVariant() does on the server (CRC-32 of the visitor ID and
 * experiment ID, the same enrollment roll, the same weights in the same order, the same cookie),
 * reveals that variant, and records the impression through /wink/track when the Wink tracker isn't
 * on the page to do it. Server- and browser-assigned visitors therefore always agree.
 *
 * DeliveryService inlines this after every block, so it must define itself once and keep every
 * statement semicolon-terminated (it is shipped with its indentation stripped). It also loads
 * before wink.min.js through TrackingAsset, and runs under node for the parity tests.
 */
(function (root) {
    'use strict';

    if (root.WinkDelivery) {
        return;
    }

    var VALID_ID = /^[A-Za-z0-9-]{8,64}$/;
    var table = null;
    var current = null;
    var pending = [];
    var doc = root.document;

    function crc32(str) {
        var bytes = unescape(encodeURIComponent(String(str)));
        var crc = -1;
        var i;
        var j;
        var c;

        if (!table) {
            table = [];
            for (i = 0; i < 256; i++) {
                c = i;
                for (j = 0; j < 8; j++) {
                    c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
                }
                table[i] = c >>> 0;
            }
        }

        for (i = 0; i < bytes.length; i++) {
            crc = table[(crc ^ bytes.charCodeAt(i)) & 0xFF] ^ (crc >>> 8);
        }

        return (crc ^ -1) >>> 0;
    }

    function controlOf(variants) {
        for (var i = 0; i < variants.length; i++) {
            if (variants[i].c) {
                return variants[i].h;
            }
        }
        return variants[0].h;
    }

    function assign(visitorId, cfg) {
        var variants = cfg.variants || [];
        var total = 0;
        var cumulative = 0;
        var bucket;
        var i;

        if (!variants.length) {
            return null;
        }

        if (crc32(visitorId + String(cfg.id) + 'enrollment') % 100 >= cfg.traffic) {
            return controlOf(variants);
        }

        for (i = 0; i < variants.length; i++) {
            total += variants[i].w;
        }
        if (total <= 0) {
            return variants[0].h;
        }

        bucket = crc32(visitorId + String(cfg.id)) % total;
        for (i = 0; i < variants.length; i++) {
            cumulative += variants[i].w;
            if (bucket < cumulative) {
                return variants[i].h;
            }
        }

        return variants[0].h;
    }

    function readCookie(name) {
        var parts = doc && doc.cookie ? doc.cookie.split(/;\s*/) : [];
        var eq;

        for (var i = 0; i < parts.length; i++) {
            eq = parts[i].indexOf('=');
            if (eq > 0 && parts[i].slice(0, eq) === name) {
                try {
                    return decodeURIComponent(parts[i].slice(eq + 1));
                } catch (e) {
                    return null;
                }
            }
        }

        return null;
    }

    function newId() {
        var c = root.crypto;
        var b = new Uint8Array(16);
        var hex = '';
        var i;

        if (c && c.randomUUID) {
            return c.randomUUID();
        }
        if (c && c.getRandomValues) {
            c.getRandomValues(b);
        } else {
            for (i = 0; i < 16; i++) {
                b[i] = Math.floor(Math.random() * 256);
            }
        }
        b[6] = b[6] & 0x0f | 0x40;
        b[8] = b[8] & 0x3f | 0x80;
        for (i = 0; i < 16; i++) {
            hex += (b[i] + 0x100).toString(16).slice(1);
        }

        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function visitorId(cfg) {
        var id = current || readCookie(cfg.cookie);

        if (!id || !VALID_ID.test(id)) {
            id = newId();
            if (doc) {
                doc.cookie = cfg.cookie + '=' + id + '; path=/; max-age=' + (cfg.days * 86400) + '; SameSite=Lax'
                    + (cfg.domain ? '; domain=' + cfg.domain : '')
                    + (root.location && root.location.protocol === 'https:' ? '; Secure' : '');
            }
        }

        return (current = id);
    }

    function resolve(el) {
        var cfg = null;
        var id = null;
        var handle = null;
        var options;
        var shown = false;
        var i;

        if (!el || !el.getAttribute || el.getAttribute('data-wink-variant') !== null) {
            return;
        }

        try {
            cfg = JSON.parse(el.getAttribute('data-wink-config'));
            id = visitorId(cfg);
            handle = assign(id, cfg);
        } catch (e) {
            handle = null;
        }

        options = el.children;
        for (i = 0; i < options.length; i++) {
            if (handle !== null && options[i].getAttribute('data-wink-option') === handle) {
                options[i].setAttribute('data-wink-chosen', '');
                shown = true;
            }
        }
        // Couldn't assign: show what a visitor without script sees.
        if (handle === null) {
            for (i = 0; i < options.length; i++) {
                if (options[i].hasAttribute('data-wink-fallback')) {
                    options[i].setAttribute('data-wink-chosen', '');
                }
            }
        }

        el.setAttribute('data-wink-variant', handle || '');
        if (id) {
            el.setAttribute('data-wink-vid', id);
        }

        if (handle !== null && cfg && cfg.track) {
            pending.push({
                track: cfg.track,
                event: {
                    experiment: el.getAttribute('data-wink-experiment'),
                    variant: handle,
                    vid: id,
                    type: 'impression',
                    url: root.location ? root.location.href : null,
                    referrer: doc && doc.referrer ? doc.referrer : null
                }
            });
        }

        return shown;
    }

    function resolveAll() {
        if (!doc) {
            return;
        }
        var blocks = doc.querySelectorAll('[data-wink-delivery="cache-safe"]');
        for (var i = 0; i < blocks.length; i++) {
            resolve(blocks[i]);
        }
    }

    // The Wink tracker (winkTrackingScript()) records impressions from the revealed blocks itself.
    // Without it, send them here, once the page has loaded.
    function flush() {
        var items = pending.splice(0, pending.length);
        var dnt = root.navigator && (root.navigator.doNotTrack === '1' || root.doNotTrack === '1');
        var events = [];
        var url = null;

        if (root.Wink || !items.length || typeof root.fetch !== 'function') {
            return;
        }
        for (var i = 0; i < items.length; i++) {
            if (!(items[i].track.dnt && dnt)) {
                url = items[i].track.url;
                events.push(items[i].event);
            }
        }
        if (!events.length) {
            return;
        }

        root.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({events: events.slice(0, 25)})
        }).catch(function () {});
    }

    if (doc && root.addEventListener) {
        // Blocks whose inline call was blocked (a Content Security Policy, say) still resolve.
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', resolveAll);
        }
        if (doc.readyState === 'complete') {
            setTimeout(flush, 0);
        } else {
            root.addEventListener('load', flush);
        }
    }

    var api = {
        crc32: crc32,
        assign: assign,
        visitorId: visitorId,
        resolve: resolve,
        resolveAll: resolveAll
    };

    root.WinkDelivery = api;

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
