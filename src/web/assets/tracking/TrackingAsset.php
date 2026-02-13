<?php

namespace jholt\wink\web\assets\tracking;

use craft\web\AssetBundle;

class TrackingAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js = [
            'js/wink.min.js',
        ];

        parent::init();
    }
}
