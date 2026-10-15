<?php

namespace justinholtweb\wink\web\assets\chartjs;

use craft\web\AssetBundle;

/**
 * Chart.js 4.5.1 (MIT, see dist/LICENSE.md), served from the plugin. Before 5.2.0 the report page
 * loaded a floating `chart.js@4` from a third-party CDN, which a control panel CSP blocks.
 */
class ChartJsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js = [
            'chart.umd.min.js',
        ];

        parent::init();
    }
}
