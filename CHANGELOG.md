# Release Notes for Wink

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
