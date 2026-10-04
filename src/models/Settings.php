<?php

namespace justinholtweb\wink\models;

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

    /**
     * Tracking events one address may send per minute (impressions and conversions together).
     * Enough for a busy reader; not enough to steer an experiment. 0 turns the budget off.
     */
    public int $trackingBudgetPerMinute = 120;

    protected function defineRules(): array
    {
        return [
            [['cookieName'], 'required'],
            [['cookieName'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]{1,64}$/'],
            [['trackingBudgetPerMinute'], 'integer', 'min' => 0],
            [['cookieDuration', 'batchInterval', 'retentionDays', 'minimumSampleSize'], 'integer', 'min' => 1],
            [['significanceThreshold'], 'in', 'range' => [90, 95, 99]],
            [['ga4MeasurementId'], 'string', 'max' => 50],
            [['gtmEventName'], 'string', 'max' => 100],
        ];
    }
}
