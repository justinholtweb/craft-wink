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
- **Server-side variant assignment** — deterministic hashing means no flicker and cache-safe
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

## License

Proprietary. See LICENSE.md.
