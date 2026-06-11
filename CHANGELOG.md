# Release Notes for Wink

## 5.0.4 - 2026-06-11

### Fixed
- Fatal `ParseError` in `ExperimentsController` and `SettingsController` caused by an unescaped apostrophe in the "Couldn't save…" error messages, which prevented the plugin from loading.

## 5.0.3 - 2026-06-11

### Fixed
- PHP 8.4 compatibility: `Experiment::defineSources()` and `defineActions()` now declare their parameters as explicitly nullable (`?string`), resolving an implicit-nullable deprecation that becomes a fatal error in PHP 9.0.

### Added
- PHPUnit unit test suite covering the statistical engine (two-proportion z-test, Wilson score interval) and deterministic variant assignment.

### Changed
- Plugin schema version aligned to 5.0.0.

## 5.0.0 - 2026-05-02

### Added
- Initial release of Wink for Craft CMS 5.
- Experiments as a custom Craft element type with full index and CRUD.
- Variants with weighted traffic allocation.
- Conversion goals (pageview, click, form submit, custom event).
- Server-side deterministic variant assignment (no flicker).
- Frontend JavaScript tracker with event batching.
- Statistical significance via two-proportion z-test.
- Wilson score confidence intervals.
- Automatic winner determination.
- CP reports with Chart.js visualizations.
- Twig functions: `winkVariant()`, `winkExperiment()`, `winkTrackingScript()`.
- `{% experiment %}` block tag for inline variant content.
- `{{ craft.wink.* }}` template variable.
- Optional GA4/GTM event forwarding.
- Do Not Track respect.
- IP anonymization option.
- Event retention management.
