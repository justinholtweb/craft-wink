<?php

namespace justinholtweb\wink\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CraftCpAsset::class,
        ];

        $this->css = [
            'css/wink-cp.css',
        ];

        $this->js = [
            'js/wink-cp.js',
        ];

        parent::init();
    }
}
