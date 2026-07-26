<?php

namespace justinholtweb\wink\models;

use craft\base\Model;

class VariantReport extends Model
{
    public ?int $variantId = null;
    public string $variantTitle = '';
    public string $variantHandle = '';
    public bool $isControl = false;
    public int $impressions = 0;
    public int $conversions = 0;
    public float $conversionRate = 0.0;
    public float $uplift = 0.0;
    public float $zScore = 0.0;
    public float $pValue = 1.0;
    public float $confidence = 0.0;
    public array $confidenceInterval = [0.0, 0.0];

    /** @var array{date: string, impressions: int, conversions: int}[] */
    public array $timeSeries = [];
}
