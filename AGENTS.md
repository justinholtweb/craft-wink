# Wink — AI Agent Guide

## What This Is

Wink is a commercial A/B testing and experimentation plugin for **Craft CMS 5.x**. It lets content editors create experiments with content variants, track conversions, and determine statistical winners from the Craft Control Panel.

- **Namespace**: `jholt\wink`
- **Package**: `jholt/craft-wink`
- **PHP**: 8.2+
- **Framework**: Craft CMS 5.x (built on Yii 2)

## Architecture Overview

```
src/
├── Plugin.php                    # Entry point. Registers services, routes, element types, Twig extensions
├── config.php                    # Default config (users override via config/wink.php)
├── enums/                        # PHP 8.2 backed enums
│   ├── ExperimentStatus.php      # draft|running|paused|completed|archived (with transition rules)
│   └── GoalType.php              # pageview|click|form_submit|custom_event
├── elements/
│   ├── Experiment.php            # Custom Craft element type — the core domain object
│   └── db/ExperimentQuery.php    # Element query with handle/status/trafficPercent filters
├── models/                       # Value objects (not persisted directly)
│   ├── Settings.php              # Plugin settings with validation rules
│   ├── Variant.php               # Variant data model
│   ├── Goal.php                  # Conversion goal model
│   └── ExperimentReport.php      # Report + VariantReport value objects for stats output
├── records/                      # ActiveRecord classes (1:1 with DB tables)
│   ├── ExperimentRecord.php      # {{%wink_experiments}}
│   ├── VariantRecord.php         # {{%wink_variants}}
│   ├── GoalRecord.php            # {{%wink_goals}}
│   └── EventRecord.php           # {{%wink_events}} (high-volume tracking data)
├── services/                     # Business logic (registered as Plugin components)
│   ├── ExperimentService.php     # CRUD, status lifecycle, variant/goal management
│   ├── AssignmentService.php     # Visitor ID cookies, deterministic variant assignment
│   ├── TrackingService.php       # Impression/conversion recording, dedup, retention purge
│   └── StatsService.php          # Z-test, p-value, Wilson CI, winner determination, time series
├── controllers/                  # CP and frontend HTTP handlers
│   ├── ExperimentsController.php # CP CRUD (index, edit, save, delete, status transitions)
│   ├── ReportsController.php     # CP reports (index, detail, chart JSON, declare winner)
│   ├── TrackingController.php    # Frontend POST + pixel GIF (anonymous, CSRF-exempt)
│   └── SettingsController.php    # Plugin settings save
├── twig/                         # Twig integration
│   ├── WinkTwigExtension.php     # winkVariant(), winkExperiment(), winkTrackingScript()
│   ├── WinkTokenParser.php       # {% experiment %} / {% variant %} block tag parser
│   └── WinkNode.php              # Compiled Twig node for experiment blocks
├── variables/
│   └── WinkVariable.php          # {{ craft.wink.* }} template variable
├── migrations/
│   └── Install.php               # Creates 4 tables with FKs and indexes
├── web/assets/
│   ├── cp/                       # CpAsset bundle (CSS + JS for control panel)
│   └── tracking/                 # TrackingAsset bundle (wink.min.js frontend tracker)
├── templates/                    # Twig CP templates
│   ├── experiments/_index.twig   # Element index page
│   ├── experiments/_edit.twig    # Experiment editor with inline variants + goals
│   ├── reports/_index.twig       # Reports dashboard
│   ├── reports/_detail.twig      # Single experiment report with Chart.js
│   └── settings/_index.twig      # Plugin settings form
└── translations/en/wink.php      # English translation strings
```

## Key Design Decisions

### Experiments Are Craft Elements
`Experiment` extends `craft\base\Element`. This gives us the standard element index, search, custom statuses, and query API for free. The content table is `{{%wink_experiments}}` joined to `elements` via `id`.

### Server-Side Deterministic Assignment
Variant assignment uses `crc32(visitorId + experimentId)` — no server-side storage needed, no flicker, cache-safe. The visitor ID is a UUID v4 stored in a cookie. Enrollment is checked separately via `crc32(visitorId + experimentId + 'enrollment') % 100 < trafficPercent`.

### Statistical Engine
Two-proportion z-test with pooled proportion. P-value calculated via Abramowitz & Stegun normal CDF approximation. Wilson score confidence intervals (better than Wald for small samples). Auto-declares winner when confidence >= threshold and sample >= minimum.

### Status Lifecycle
`ExperimentStatus` enum enforces valid transitions:
- `draft` → `running`, `archived`
- `running` → `paused`, `completed`
- `paused` → `running`, `completed`, `archived`
- `completed` → `archived`
- `archived` → (terminal)

## Database Schema

Four tables — see `src/migrations/Install.php` for full DDL:
- `{{%wink_experiments}}` — element content table (PK is FK to `elements.id`)
- `{{%wink_variants}}` — variants per experiment (handle, title, content, weight, isControl)
- `{{%wink_goals}}` — conversion goals (goalType enum, goalTarget pattern/selector)
- `{{%wink_events}}` — high-volume tracking (impressions + conversions, bigint PK)

## Service Access Pattern

Services are Yii components on the Plugin instance:
```php
Plugin::getInstance()->experiments  // ExperimentService
Plugin::getInstance()->tracking     // TrackingService
Plugin::getInstance()->stats        // StatsService
Plugin::getInstance()->assignment   // AssignmentService
```

## Conventions to Follow

- **Craft CMS 5 patterns**: follow the conventions in `craftcms/cms` — element types, ActiveRecords, services as components, CP templates extending `_layouts/cp.twig`
- **PHP 8.2 features**: use enums, typed properties, named arguments, `match` expressions, union types
- **Translations**: wrap all user-facing strings with `Craft::t('wink', '...')` in PHP or `'...'|t('wink')` in Twig
- **New translations**: add to `src/translations/en/wink.php`
- **Schema changes**: create a new migration in `src/migrations/`, bump `Plugin::$schemaVersion`
- **No external PHP dependencies**: everything uses Craft/Yii built-ins to keep the plugin lightweight
- **Frontend JS**: vanilla JS only (no frameworks), keep `wink.min.js` under ~3KB gzipped
- **Chart.js**: loaded from CDN in the report detail template, not bundled

## Common Tasks

### Adding a new setting
1. Add property + default to `src/models/Settings.php`
2. Add validation rule in `defineRules()`
3. Add default in `src/config.php`
4. Add form field in `src/templates/settings/_index.twig`
5. Add read/save in `src/controllers/SettingsController.php`
6. Add translation string

### Adding a new goal type
1. Add case to `src/enums/GoalType.php` (with label, targetLabel, targetPlaceholder)
2. Update `wink.min.js` if it needs frontend behavior (like click tracking)
3. Add translation string

### Adding a new experiment status
1. Add case to `src/enums/ExperimentStatus.php`
2. Define transition rules in `canTransitionTo()`
3. Add to `statusCondition()` in `ExperimentQuery.php`
4. Add handling in `ExperimentsController::actionUpdateStatus()`

### Adding a database column
1. Create `src/migrations/m{YYMMDD}_{HHMMSS}_{description}.php`
2. Update the relevant ActiveRecord in `src/records/`
3. Update the model in `src/models/` if applicable
4. Update `ExperimentQuery::beforePrepare()` if it's on experiments
5. Bump `Plugin::$schemaVersion`

## Testing Considerations

- `TrackingController` allows anonymous access and disables CSRF — be careful with changes
- `AssignmentService` is deterministic — the same visitorId + experimentId always gets the same variant
- `StatsService::normalCdfComplement()` is a math approximation — verify against known z-tables if modifying
- The `{% experiment %}` Twig tag compiles to raw PHP in `WinkNode` — test compiled output carefully
- Impression dedup is per-visitor-per-experiment-per-day in `TrackingService::recordImpression()`
