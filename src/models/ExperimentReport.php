<?php

namespace justinholtweb\wink\models;

use craft\base\Model;

class ExperimentReport extends Model
{
    public ?int $experimentId = null;
    public int $totalImpressions = 0;
    public int $totalConversions = 0;
    public float $overallConversionRate = 0.0;

    /** @var VariantReport[] */
    public array $variants = [];

    public ?int $winnerVariantId = null;
    public float $confidence = 0.0;
    public bool $isSignificant = false;
}
