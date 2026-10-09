<?php

namespace justinholtweb\wink\services;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\events\DetectPageCacheEvent;
use justinholtweb\wink\models\Settings;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use yii\base\Component;

/**
 * How an experiment block reaches the page.
 *
 * Server-side delivery picks the visitor's variant while the page renders. That is only right when
 * every page is rendered for the visitor asking for it: behind Blitz, a CDN or any full-page cache
 * the first visitor's variant is stored and served to everyone, and every impression is recorded
 * against the wrong variant.
 *
 * Cache-safe delivery renders every variant, hidden, into markup that is the same for every
 * visitor, plus a small inline runtime (`wink-delivery.js`) that assigns the visitor in the browser
 * with the server's hash, weights and cookie, reveals their variant, and records the impression
 * through `/wink/track`. The server recomputes the assignment there from the same cookie, so the
 * variant shown and the variant counted agree.
 */
class DeliveryService extends Component
{
    /**
     * @event DetectPageCacheEvent Lets a site name a full-page cache Wink can't see on its own.
     */
    public const EVENT_DETECT_PAGE_CACHE = 'detectPageCache';

    /** The attribute marking a cache-safe block. The runtime and its CSS select on it. */
    public const MODE_ATTRIBUTE = 'cache-safe';

    private bool $_pageCacheDetected = false;
    private ?string $_pageCache = null;
    private ?string $_runtime = null;

    /**
     * Whether this experiment is delivered cache-safe: its own Delivery choice, else the plugin's,
     * where `auto` means cache-safe when a full-page cache is caching.
     */
    public function isCacheSafe(Experiment $experiment): bool
    {
        return match ($this->getMode($experiment)) {
            Settings::DELIVERY_CACHE_SAFE => true,
            Settings::DELIVERY_SERVER => false,
            default => $this->getPageCache() !== null,
        };
    }

    /**
     * The delivery mode in force for an experiment, before `auto` is resolved.
     */
    public function getMode(Experiment $experiment): string
    {
        return $experiment->deliveryMode ?: Plugin::getInstance()->getSettings()->deliveryMode;
    }

    /**
     * The name of the full-page cache serving this site, or null. Blitz counts when it is installed,
     * enabled and has caching switched on; anything else can be reported through
     * {@see EVENT_DETECT_PAGE_CACHE}.
     */
    public function getPageCache(): ?string
    {
        if ($this->_pageCacheDetected) {
            return $this->_pageCache;
        }

        $event = new DetectPageCacheEvent(['pageCache' => $this->_detectBlitz()]);
        if ($this->hasEventHandlers(self::EVENT_DETECT_PAGE_CACHE)) {
            $this->trigger(self::EVENT_DETECT_PAGE_CACHE, $event);
        }

        $this->_pageCacheDetected = true;

        return $this->_pageCache = $event->pageCache ?: null;
    }

    /**
     * A control panel warning for server-side delivery behind a full-page cache, or null when there
     * is nothing to warn about. With no experiment, it speaks for the plugin setting.
     */
    public function getServerSideWarning(?Experiment $experiment = null): ?string
    {
        $pageCache = $this->getPageCache();
        if ($pageCache === null) {
            return null;
        }

        $mode = $experiment !== null ? $this->getMode($experiment) : Plugin::getInstance()->getSettings()->deliveryMode;
        if ($mode !== Settings::DELIVERY_SERVER) {
            return null;
        }

        return Craft::t('wink', '{cache} is caching full pages, and server-side delivery chooses the variant while the page renders — so the first visitor’s variant is cached and shown to everyone, and impressions are counted against the wrong variant. Use cache-safe delivery.', [
            'cache' => $pageCache,
        ]);
    }

    /**
     * The block for a cache-safe experiment: every variant body the experiment has, hidden by CSS
     * until the runtime reveals the visitor's, with the control shown when script doesn't run.
     * Nothing in it depends on the visitor, so the same bytes can be cached and served to anyone.
     *
     * @param array<string, string> $bodies Rendered HTML keyed by variant handle, in template order.
     */
    public function renderCacheSafe(Experiment $experiment, array $bodies): string
    {
        $variants = $experiment->getVariants();
        $handles = array_map(fn(Variant $variant) => $variant->handle, $variants);
        $fallback = $this->_fallbackHandle($variants);

        $options = '';
        foreach ($bodies as $handle => $body) {
            $handle = (string)$handle;
            if (!in_array($handle, $handles, true)) {
                continue;
            }
            $options .= Html::tag('div', $body, [
                'data-wink-option' => $handle,
                'data-wink-fallback' => $handle === $fallback ? true : null,
            ]);
        }

        $block = Html::tag('div', $options, [
            'data-wink-experiment' => $experiment->handle,
            'data-wink-delivery' => self::MODE_ATTRIBUTE,
            'data-wink-config' => Json::encode($this->getClientConfig($experiment)),
        ]);

        $selector = '[data-wink-delivery=' . self::MODE_ATTRIBUTE . ']';

        // The CSS comes first so no variant can paint before it applies; the runtime comes straight
        // after its block, so the visitor's variant is revealed before the parser moves on.
        return '<style>' . $selector . '>[data-wink-option]{display:none}' . $selector . '>[data-wink-chosen]{display:contents}</style>'
            . '<noscript><style>' . $selector . '>[data-wink-fallback]{display:contents}</style></noscript>'
            . $block
            . '<script>' . $this->getRuntime() . 'WinkDelivery.resolve(document.currentScript&&document.currentScript.previousElementSibling);</script>';
    }

    /**
     * What the runtime needs to assign a visitor exactly as {@see AssignmentService::assignVariant()}
     * does. Variants are in the order the server walks them; none of this varies by visitor.
     *
     * @return array<string, mixed>
     */
    public function getClientConfig(Experiment $experiment): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return [
            'id' => (int)$experiment->id,
            'traffic' => (int)$experiment->trafficPercent,
            'variants' => array_map(fn(Variant $variant) => [
                'h' => $variant->handle,
                'w' => (int)$variant->weight,
                'c' => (bool)$variant->isControl,
            ], $experiment->getVariants()),
            'cookie' => $settings->cookieName,
            'days' => $settings->cookieDuration,
            'domain' => (string)(Craft::cookieConfig()['domain'] ?? ''),
            'track' => $settings->enableTracking ? [
                'url' => '/wink/track',
                'dnt' => $settings->respectDnt,
            ] : false,
        ];
    }

    /**
     * The delivery runtime, inlined into every cache-safe block. It defines itself once per page,
     * so repeating it costs bytes but nothing else, and a block cached by `{% cache %}` still works
     * on whatever page it lands.
     */
    public function getRuntime(): string
    {
        if ($this->_runtime === null) {
            $source = (string)file_get_contents(dirname(__DIR__) . '/web/assets/tracking/dist/js/wink-delivery.js');
            // Drop the doc comment and indentation; statements are semicolon-terminated, so the
            // line breaks stay as they are.
            $source = (string)preg_replace('~^/\*.*?\*/\s*~s', '', $source);
            $source = (string)preg_replace('~^[ \t]+~m', '', $source);
            $this->_runtime = trim($source) . "\n";
        }

        return $this->_runtime;
    }

    /**
     * The variant a visitor sees without script, and a non-enrolled visitor sees with it: the same
     * fallback {@see AssignmentService::assignVariant()} uses.
     *
     * @param Variant[] $variants
     */
    private function _fallbackHandle(array $variants): ?string
    {
        foreach ($variants as $variant) {
            if ($variant->isControl) {
                return $variant->handle;
            }
        }

        return isset($variants[0]) ? $variants[0]->handle : null;
    }

    private function _detectBlitz(): ?string
    {
        $blitz = Craft::$app->getPlugins()->getPlugin('blitz');
        if ($blitz === null) {
            return null;
        }

        $settings = $blitz->getSettings();

        return $settings !== null && !empty($settings->toArray(['cachingEnabled'])['cachingEnabled']) ? 'Blitz' : null;
    }
}
