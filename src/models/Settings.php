<?php

namespace jholt\wink\models;

use craft\base\Model;

class Settings extends Model
{
    // Tracking
    public bool $enableTracking = true;
    public bool $respectDnt = true;
    public bool $anonymizeIp = false;
    public string $cookieName = '_wink_vid';
    public int $cookieDuration = 365;

    // GA4 / GTM
    public bool $enableGa4 = false;
    public string $ga4MeasurementId = '';
    public bool $enableGtm = false;
    public string $gtmEventName = 'wink_experiment';

    // Performance
    public int $batchInterval = 5;
    public int $retentionDays = 90;

    // Statistical
    public int $significanceThreshold = 95;
    public int $minimumSampleSize = 100;

    protected function defineRules(): array
    {
        return [
            [['cookieName'], 'required'],
            [['cookieDuration', 'batchInterval', 'retentionDays', 'minimumSampleSize'], 'integer', 'min' => 1],
            [['significanceThreshold'], 'in', 'range' => [90, 95, 99]],
            [['ga4MeasurementId'], 'string', 'max' => 50],
            [['gtmEventName'], 'string', 'max' => 100],
        ];
    }
}
