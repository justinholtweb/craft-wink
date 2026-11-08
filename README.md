# Wink — A/B Testing for Craft CMS

A/B testing and experimentation plugin for Craft CMS 5. Create experiments with content variants, track conversions, and determine winners with statistical significance — all from within the Control Panel.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require justinholtweb/craft-wink
php craft plugin/install wink
```

## Features

- **Experiments as Elements** — full Craft element index with statuses, search, and filtering
- **Deterministic variant assignment** — the same visitor always gets the same variant, with no flicker
- **Cache-safe delivery** — experiments stay correct behind Blitz, a CDN or any full-page cache
- **Conversion goals** — page views, clicks, form submissions, or custom events
- **Statistical significance** — two-proportion z-test with Wilson score confidence intervals
- **Reports dashboard** — conversion rates, uplift, confidence levels, and time-series charts
- **Twig integration** — functions, block tags, and template variables
- **Frontend tracker** — lightweight JS (~3KB gzipped) with event batching
- **GA4/GTM forwarding** — optional integration with Google Analytics and Tag Manager
- **Privacy-first** — optional consent gate, respects Do Not Track, IP anonymization, configurable retention

## Usage

### Twig Functions

```twig
{# Output variant content directly #}
{{ winkVariant('headline-test') }}

{# Full control over variant rendering #}
{% set test = winkExperiment('headline-test') %}
{% if test and test.variant.handle == 'variant-a' %}
    <h1>Discover Something New</h1>
{% else %}
    <h1>Welcome</h1>
{% endif %}

{# Block syntax with inline variants #}
{% experiment 'headline-test' %}
    {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
    {% variant 'variant-a' %}<h1>Discover</h1>{% endvariant %}
{% endexperiment %}

{# The handle can be any expression, e.g. a page-builder block's own field #}
{% experiment block.experimentHandle %}
    {% variant 'control' %}{% for child in block.variantA.all() %}…{% endfor %}{% endvariant %}
    {% variant 'variant-a' %}{% for child in block.variantB.all() %}…{% endfor %}{% endvariant %}
{% endexperiment %}

{# Or hand over bodies you've already rendered, keyed by variant handle (output unescaped) #}
{% set a %}…{% endset %}
{% set b %}…{% endset %}
{{ winkRender(block.experimentHandle, { control: a, 'variant-a': b }) }}

{# Add tracking script before </body> #}
{{ winkTrackingScript() }}
```

### Template Variables

```twig
{# Query experiments #}
{% set experiments = craft.wink.experiments.experimentStatus('running').all() %}

{# Get assigned variant handle #}
{% set variant = craft.wink.variant('headline-test') %}
```

### Conversion goals

With `{{ winkTrackingScript() }}` on the page, the tracker detects these goals itself, on any page
of the site:

| Goal type | Target | Converts when |
| --- | --- | --- |
| Page View | A URL path; `*` matches anything (`/thank-you*`). A pattern with `?` also matches the query string; one with `://` matches the whole URL. | The visitor loads a matching page. |
| Click | A CSS selector (`.cta-button`) | The visitor clicks a matching element, or anything inside one. |
| Form Submit | A CSS selector (`#signup-form`) | The visitor submits a matching form. |
| Custom Event | — | Your script calls `Wink.convert()`. |

Any goal can also be sent from your own script:

```javascript
Wink.convert('signup-goal', { plan: 'pro' });
```

The page needn't have the experiment on it: a conversion from the thank-you page counts for every
running experiment with that goal handle. A conversion counts only from a visitor who has had an
impression of the experiment. Repeat conversions are recorded, but rates and significance count each
converted visitor once.

## Consent

Turn on **Require Consent** (`requireConsent`) and Wink sets no cookie and records nothing for a
visitor until they consent. Until then they see each experiment's control. From your consent
banner:

```javascript
Wink.consent();          // or: window.dispatchEvent(new Event('wink:consent'))
Wink.revokeConsent();    // deletes Wink's cookies and stops tracking
```

Consent is the `_wink_consent` cookie set to `1`, so a consent manager can also set that cookie
itself. It takes effect from the next page the visitor loads; the page they consented on keeps
showing the control.

## Delivery: server-side or cache-safe

How an experiment reaches the page is the **Delivery** setting (`deliveryMode`), which each
experiment can override on its edit screen.

| Mode | What happens | Use it when |
| --- | --- | --- |
| `server` | The visitor's variant is chosen while the page renders, and only it is sent. | Every page is rendered per request. |
| `cacheSafe` | Every variant is in the HTML, hidden by CSS. A small inline script assigns the visitor in the browser — same hash, weights and cookie as the server — and reveals their variant before it paints. Without JavaScript, the control shows. | Blitz, a CDN, Varnish or any full-page cache serves the page. |
| `auto` (default) | `cacheSafe` when Blitz is installed with caching on, otherwise `server`. | You run Blitz, or no cache. Wink can't see a CDN: if one caches your HTML, choose `cacheSafe`. |

Server-side delivery behind a cache stores the first visitor's variant and shows it to everyone,
while counting impressions against each visitor's own assignment. The control panel warns when an
experiment, or the setting, is server-side while Blitz is caching. Any other full-page cache can be
reported to Wink, for `auto` and for the warning:

```php
use justinholtweb\wink\events\DetectPageCacheEvent;
use justinholtweb\wink\services\DeliveryService;
use yii\base\Event;

Event::on(DeliveryService::class, DeliveryService::EVENT_DETECT_PAGE_CACHE, function(DetectPageCacheEvent $event) {
    $event->pageCache ??= 'Cloudflare';
});
```

In cache-safe mode:

- `{% experiment %}`, `winkRender()` and `winkVariant()` render cache-safe markup. `winkExperiment()` and
  `craft.wink.variant()` hand the visitor's variant to your template, so they always run
  server-side; don't use them on cached pages.
- Impressions are recorded by the browser through `/wink/track` — by the tracker if
  `winkTrackingScript()` is on the page, otherwise by the inline script itself. The server
  recomputes the assignment from the visitor cookie, so it counts the variant that was shown, and it
  skips an event assigned to a different visitor than the cookie names. Conversions work as before.
- The visitor cookie is readable by script (it holds a random ID and nothing else). A signed cookie
  from Wink 5.0 is still honoured and rewritten.
- A Content Security Policy that blocks inline scripts keeps blocks hidden until the tracker loads
  and reveals them.

## Bundling the tracker

A site that serves all its JavaScript from its own bundle (a CDN, say) can bundle Wink's tracker
instead of letting `winkTrackingScript()` publish it from Craft's asset URL.

1. Copy `wink.mjs`, `wink.min.js` and `wink-delivery.js` from
   `vendor/justinholtweb/craft-wink/src/web/assets/tracking/dist/js/` into your source, side by side
   (or import them from `vendor/` directly). `wink.mjs` is a two-line wrapper around the other two,
   not a third copy.
2. Replace `{{ winkTrackingScript() }}` with `{{ winkTrackingConfig() }}` on `<html>` or
   `<body>` — `<body {{ winkTrackingConfig() }}>` — and nowhere else. It writes the same
   configuration as a `data-wink-tracking-config` attribute, publishes nothing, and renders nothing
   while tracking is off. It doesn't vary by visitor, so the page stays cacheable. The tracker reads
   the attribute from those two elements only: rich text and other user content often keep `data-*`
   attributes through a sanitiser, so a copy anywhere else could be forged to send events to
   another site or rename the consent cookie. User content can't put attributes on `<html>` or
   `<body>`. (Even there, an endpoint on another origin is replaced with `/wink/track`, and a cookie
   name that isn't letters, digits, `_` and `-` with the default; an endpoint passed to
   `Wink.start()` is your own code and is used as given.)
3. Start the tracker from your bundle:

   ```javascript
   import Wink from './wink/wink.mjs';       // ES modules

   // CommonJS, in TrackingAsset's order:
   // require('./wink/wink-delivery.js');
   // const Wink = require('./wink/wink.min.js');

   Wink.start();   // reads data-wink-tracking-config; Wink.start({ batchInterval: 10 }) overrides it
   ```

`Wink.start()` with no configuration anywhere starts nothing, and malformed configuration is
ignored rather than thrown. Until it is called, `Wink.convert()` and `Wink.track()` do nothing;
`Wink.consent()` and `Wink.revokeConsent()` work from the start. Both files still set
`window.Wink` and `window.WinkDelivery`, so inline `Wink.convert(…)` calls keep working, and a page
that loads the tracker twice (injected and bundled) keeps one instance and hands it to the bundle.

**Variant flicker.** Bundling moves only the tracker. Showing the visitor's variant is not the
tracker's job: a server-side block is chosen while the page renders, and a cache-safe block carries
its own inline script, straight after the block, that reveals the variant before the browser paints
it. That inline script stays in the page whatever you bundle — don't strip it, and don't try to
replace it with the bundled copy, which runs too late to prevent a flash. The bundled copy matters
only when a Content Security Policy blocks inline scripts: the blocks then stay hidden (never the
wrong variant, but blank) until it runs. Load the bundle with `defer` or `type="module"` and it
reveals them as the HTML finishes parsing, the same moment the injected script did; `async`, or a
bundle loaded after `DOMContentLoaded`, leaves them hidden for longer. Better still, allow the
inline script with a nonce or hash. The inline `<style>` that hides the other variants has to be
allowed too, or every variant shows.

## Permissions

| Permission | Lets someone |
| --- | --- |
| Manage experiments | Create, edit, start, pause, end and delete experiments, and declare a winner. Variant content is published on the site as HTML, so treat this like the right to edit templates. |
| View experiment reports | Read the reports |

Settings are admin-only, and saved only where admin changes are allowed.

## The tracking endpoint

`/wink/track` is public, so it takes no more than a page would send: at most 25 events a request,
and `trackingBudgetPerMinute` (120 by default) per address. Behind a proxy or CDN, set Craft's
`trustedHosts`, or every visitor shares the proxy's budget.

## License

Proprietary. See LICENSE.md.
