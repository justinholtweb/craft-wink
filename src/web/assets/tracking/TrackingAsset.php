<?php

namespace justinholtweb\wink\web\assets\tracking;

use craft\web\AssetBundle;

class TrackingAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        // The delivery runtime first: the tracker resolves any cache-safe block still unresolved
        // before it records impressions.
        $this->js = [
            'js/wink-delivery.js',
            'js/wink.min.js',
        ];

        parent::init();
    }
}
