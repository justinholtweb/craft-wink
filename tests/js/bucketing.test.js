/**
 * The browser runtime must assign every visitor exactly as the server does, or a cache-safe page
 * shows one variant while /wink/track counts another. The expected assignments in
 * fixtures/bucketing.json come from AssignmentService::assignVariant() and are pinned there by
 * tests/unit/BucketingParityTest.php.
 *
 *     node tests/js/bucketing.test.js
 *
 * Also checks resolve() against a minimal stand-in DOM: the chosen variant is revealed, the
 * visitor cookie is written in the server's format, a returning visitor keeps their ID, and a block
 * it can't read falls back to the control.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const runtimePath = path.join(__dirname, '../../src/web/assets/tracking/dist/js/wink-delivery.js');
const source = fs.readFileSync(runtimePath, 'utf8');
const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/bucketing.json'), 'utf8'));

let passed = 0;
let failed = 0;

function check(label, test) {
    let result;
    try {
        result = test();
    } catch (e) {
        result = e.stack || String(e);
    }
    if (result === true) {
        passed++;
        console.log('  ✓ ' + label);
    } else {
        failed++;
        console.log('  ✗ ' + label + '\n    ' + result);
    }
}

/** Run the runtime in a fresh context, as a page would, and return what it defined. */
function load(code, window) {
    const context = vm.createContext(window || {});
    context.window = context;
    vm.runInContext(code, context);
    return context.WinkDelivery;
}

/** The runtime as DeliveryService::getRuntime() inlines it: no doc comment, no indentation. */
const inlined = source.replace(/^\/\*[\s\S]*?\*\/\s*/, '').replace(/^[ \t]+/gm, '').trim() + '\n';

console.log('\nBucketing parity with AssignmentService');

for (const [label, code] of [['the shipped file', source], ['the inlined copy', inlined]]) {
    const runtime = load(code);

    check(`CRC-32 matches PHP's crc32() (${label})`, () => {
        // crc32('The quick brown fox jumps over the lazy dog') in PHP.
        return runtime.crc32('The quick brown fox jumps over the lazy dog') === 0x414FA339 || runtime.crc32('The quick brown fox jumps over the lazy dog').toString(16);
    });

    check(`every visitor gets the server's variant in all ${fixture.experiments.length} experiments (${label})`, () => {
        const mismatches = [];
        for (const experiment of fixture.experiments) {
            for (const visitor of fixture.visitors) {
                const expected = fixture.assignments[String(experiment.id)][visitor];
                const actual = runtime.assign(visitor, experiment);
                if (actual !== expected) {
                    mismatches.push(`${experiment.id}/${visitor}: server ${expected}, browser ${actual}`);
                }
            }
        }
        return mismatches.length === 0 || mismatches.slice(0, 5).join('; ') + ` (${mismatches.length} in all)`;
    });
}

check('the fixture covers enough visitors to mean something', () => {
    return fixture.visitors.length >= 250 && fixture.experiments.length >= 6 || 'too small';
});

// -------------------------------------------------------------------------------------------
console.log('\nRevealing a block');

/** Just enough DOM for resolve(): attributes, children, and document.cookie. */
function element(attributes, children) {
    const attrs = Object.assign({}, attributes);
    return {
        children: children || [],
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        setAttribute: (name, value) => { attrs[name] = String(value); },
        hasAttribute: (name) => name in attrs,
        attrs,
    };
}

function page(cookie) {
    const jar = {};
    if (cookie) {
        jar[cookie.split('=')[0]] = cookie.split('=')[1];
    }
    const document = {
        readyState: 'loading',
        referrer: '',
        addEventListener() {},
        querySelectorAll: () => [],
        get cookie() {
            return Object.keys(jar).map((k) => k + '=' + jar[k]).join('; ');
        },
        set cookie(value) {
            const [pair] = value.split(';');
            const eq = pair.indexOf('=');
            jar[pair.slice(0, eq)] = pair.slice(eq + 1);
            document.lastWrite = value;
        },
    };
    const window = {document, location: {href: 'https://example.test/', protocol: 'https:'}, addEventListener() {}, navigator: {}};
    return {window, document, jar};
}

const experiment = fixture.experiments[0];
const config = Object.assign({}, experiment, {cookie: '_wink_vid', days: 365, domain: '', track: false});

function block(cfg) {
    return element(
        {'data-wink-experiment': 'headline', 'data-wink-delivery': 'cache-safe', 'data-wink-config': typeof cfg === 'string' ? cfg : JSON.stringify(cfg)},
        experiment.variants.map((v) => element(Object.assign({'data-wink-option': v.h}, v.c ? {'data-wink-fallback': ''} : {}))),
    );
}

const chosen = (el) => el.children.filter((c) => c.hasAttribute('data-wink-chosen')).map((c) => c.getAttribute('data-wink-option'));

check('a returning visitor sees the variant the server would give them, and keeps their ID', () => {
    const visitor = fixture.visitors[10];
    const {window, jar} = page('_wink_vid=' + visitor);
    const runtime = load(inlined, window);
    const el = block(config);
    runtime.resolve(el);
    const expected = fixture.assignments[String(experiment.id)][visitor];

    return chosen(el).join() === expected && el.getAttribute('data-wink-variant') === expected
        && el.getAttribute('data-wink-vid') === visitor && jar._wink_vid === visitor
        || `chose ${chosen(el)} (expected ${expected}), cookie ${jar._wink_vid}`;
});

check('a new visitor gets a UUID cookie the server accepts, with the configured lifetime', () => {
    const {window, document, jar} = page(null);
    const runtime = load(inlined, window);
    const el = block(config);
    runtime.resolve(el);
    const id = jar._wink_vid;

    return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(id)
        && document.lastWrite.includes('path=/') && document.lastWrite.includes('max-age=31536000')
        && document.lastWrite.includes('SameSite=Lax') && document.lastWrite.includes('Secure')
        && chosen(el).join() === runtime.assign(id, config)
        || `cookie ${document.lastWrite}, chose ${chosen(el)}`;
});

check('a cookie value the server would reject is replaced, not used', () => {
    const {window, jar} = page('_wink_vid=' + encodeURIComponent('<script>'));
    const runtime = load(inlined, window);
    runtime.resolve(block(config));

    return /^[0-9a-f-]{36}$/.test(jar._wink_vid) || `kept ${jar._wink_vid}`;
});

check('two blocks on one page assign the same visitor', () => {
    const {window} = page(null);
    const runtime = load(inlined, window);
    const a = block(config);
    const b = block(config);
    runtime.resolve(a);
    runtime.resolve(b);

    return a.getAttribute('data-wink-vid') === b.getAttribute('data-wink-vid') || 'different IDs';
});

check('a block it can’t read shows the control, and isn’t tracked as a variant', () => {
    const {window} = page(null);
    const runtime = load(inlined, window);
    const el = block('{not json');
    runtime.resolve(el);

    return chosen(el).join() === 'control' && el.getAttribute('data-wink-variant') === '' || `chose ${chosen(el)}, variant "${el.getAttribute('data-wink-variant')}"`;
});

check('resolving twice changes nothing', () => {
    const {window} = page('_wink_vid=' + fixture.visitors[3]);
    const runtime = load(inlined, window);
    const el = block(config);
    runtime.resolve(el);
    const before = JSON.stringify(el.children.map((c) => c.attrs));
    runtime.resolve(el);

    return JSON.stringify(el.children.map((c) => c.attrs)) === before || 'changed';
});

check('the runtime defines itself once when inlined after several blocks', () => {
    const {window} = page(null);
    const first = load(inlined, window);
    vm.runInContext(inlined, vm.createContext(window));

    return window.WinkDelivery === first || 'redefined';
});

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
