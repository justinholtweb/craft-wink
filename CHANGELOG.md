# Release Notes for Wink

## 5.0.6 - 2026-10-04

> {warning} **Wink now has permissions.** Until now every control panel user could manage
> experiments, read reports and change settings. After updating, grant *Manage experiments* and
> *View experiment reports* under **Settings → Users** to everyone who needs them. Settings are
> now admin-only. Behind a proxy or CDN, set Craft's `trustedHosts`, so the new per-address
> tracking budget charges visitors rather than the proxy.

### Security
- Wink registered no permissions, so any control panel user could create, edit, start, end and
  delete experiments, declare winners, read reports and change settings. There are now two
  permissions: *Manage experiments* and *View experiment reports*. Variant content is published
  on the site as HTML, so *Manage experiments* is effectively the right to edit templates. The
  experiment element checks them too, so the element index's Delete action follows them.
- Settings are admin-only, and saved only where admin changes are allowed. The cookie name must
  now be a valid cookie token.
- The public tracking endpoint took any number of events of any kind. It now takes at most 25 a
  request, of type `impression` or `conversion`. There is a per-address budget,
  `trackingBudgetPerMinute` (120 by default), that a forged `X-Forwarded-For` can't reset.
- A conversion was recorded for a goal the experiment doesn't have, and from visitors who had
  never seen the experiment. It now needs both: a known goal, and an impression first.
- Click-goal selectors and the GTM event name are written into an inline `<script>`. They are now
  hex-escaped, so a `</script>` in one can't close it.

### Fixed
- Conversion rates counted conversion *events*, so a returning buyer counted twice and a
  variant's rate could pass 100%, breaking the confidence interval and the significance test.
  Rates, intervals, the z-test and the chart now count converted visitors. Every conversion is
  still recorded.
- With *Anonymize IP* on, IPv6 addresses were stored whole. They now keep only their first 48
  bits.
- Client-supplied URLs, referrers and user agents longer than their 500-character columns failed
  the insert. They are now truncated. Event metadata is limited to 2 KB of JSON.

## 5.0.5 - 2026-07-26

### Fixed
- Element index columns (status, traffic %, variant count) rendered blank or raw. The overridden method was renamed from `tableAttributeHtml()` to `attributeHtml()` for Craft 5, which had silently disabled it.
- Winner determination could declare a significantly *worse* variant the winner. The two-proportion z-test is two-tailed, so significance alone doesn't imply improvement — a variant must now actually beat the control to win.
- Visitor IDs were unstable within a single request. Each call to `getVisitorId()` re-read the request cookie and minted a fresh UUID, scattering one visitor's assignments and impressions across throwaway IDs whenever a page contained more than one experiment. The ID is now memoized per request.
- Conversion goals are returned in a stable, author-defined order instead of alphabetically. This also fixes the wrong goal being deleted when goals were re-saved.

### Added
- Codeception integration test suite running against Craft's test framework (element CRUD, queries, services, tracking, report generation, Twig rendering), plus expanded PHPUnit unit coverage (enums, the `{% experiment %}` tag compiler, PSR-4 layout). Includes a DDEV environment and `ddev test` runner.

### Changed
- Moved `VariantReport` into its own file (`src/models/VariantReport.php`) to comply with PSR-4; previously it was only loadable as a side effect of loading `ExperimentReport`.

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
