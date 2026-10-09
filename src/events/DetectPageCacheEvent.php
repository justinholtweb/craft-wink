<?php

namespace justinholtweb\wink\events;

use yii\base\Event;

/**
 * Fired when Wink asks whether a full-page cache is serving the site. Wink fills in Blitz itself;
 * set `$pageCache` to the name of any other cache you run (a static cache, Cloudflare APO, Varnish)
 * so `auto` delivery goes cache-safe and the control panel warns about server-side experiments.
 */
class DetectPageCacheEvent extends Event
{
    /**
     * The name of the full-page cache in front of the site, or null for none.
     */
    public ?string $pageCache = null;
}
