<?php

/**
 * Wink default config.
 *
 * Copy this file to config/wink.php to override defaults.
 */
return [
    // Tracking
    'enableTracking' => true,
    'respectDnt' => true,
    'anonymizeIp' => false,
    'cookieName' => '_wink_vid',
    'cookieDuration' => 365,
    'trackingBudgetPerMinute' => 120, // events one address may send per minute; 0 = no limit

    // GA4 / GTM
    'enableGa4' => false,
    'ga4MeasurementId' => '',
    'enableGtm' => false,
    'gtmEventName' => 'wink_experiment',

    // Performance
    'batchInterval' => 5,
    'retentionDays' => 90,

    // Statistical
    'significanceThreshold' => 95,
    'minimumSampleSize' => 100,
];
