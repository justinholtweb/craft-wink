/**
 * Wink's front-end scripts, as an ES module, for a site that bundles its own JavaScript.
 *
 *     import Wink from './vendor/wink/wink.mjs';
 *     Wink.start();   // reads the page's {{ winkTrackingConfig() }}
 *
 * Copy this file, wink-delivery.js and wink.min.js side by side. This is a wrapper rather than a
 * second copy: the two classic scripts still set `window.WinkDelivery` and `window.Wink`, in the
 * order TrackingAsset loads them, so `Wink.convert(…)` from inline scripts keeps working and a page
 * that also has the injected tracker, or a cache-safe block's inline runtime, ends up with one
 * instance of each rather than two.
 *
 * Variant reveal is not this file's job: a cache-safe block's own inline script does that before
 * the block paints. See "Bundling the tracker" in the README.
 */
import './wink-delivery.js';
import './wink.min.js';

export const WinkDelivery = window.WinkDelivery;

export default window.Wink;
