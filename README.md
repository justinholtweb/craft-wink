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
- **Privacy-first** — respects Do Not Track, IP anonymization, configurable retention

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

### JavaScript Conversions

```javascript
// Record a conversion
Wink.convert('signup-goal', { plan: 'pro' });
```

A conversion counts only for a goal the experiment has, and only from a visitor who has had an
impression of it. Repeat conversions are recorded, but rates and significance count each converted
visitor once.

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

- `{% experiment %}` and `winkVariant()` render cache-safe markup. `winkExperiment()` and
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
