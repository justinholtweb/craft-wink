<?php

namespace justinholtweb\wink\services;

use craft\db\Query;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\ExperimentReport;
use justinholtweb\wink\models\VariantReport;
use justinholtweb\wink\Plugin;
use yii\base\Component;

class StatsService extends Component
{
    /**
     * Generate a full report for an experiment.
     */
    public function getExperimentReport(Experiment $experiment, ?int $goalId = null): ExperimentReport
    {
        $report = new ExperimentReport();
        $report->experimentId = $experiment->id;

        $tracking = Plugin::getInstance()->tracking;
        $settings = Plugin::getInstance()->getSettings();
        $variants = $experiment->getVariants();

        $controlReport = null;
        $variantReports = [];

        foreach ($variants as $variant) {
            $vr = new VariantReport();
            $vr->variantId = $variant->id;
            $vr->variantTitle = $variant->title;
            $vr->variantHandle = $variant->handle;
            $vr->isControl = $variant->isControl;
            $vr->impressions = $tracking->getImpressionCount($experiment->id, $variant->id);
            $vr->conversions = $tracking->getConversionCount($experiment->id, $variant->id, $goalId);
            $vr->conversionRate = $vr->impressions > 0
                ? $vr->conversions / $vr->impressions
                : 0.0;

            // Wilson score confidence interval
            $ci = $this->wilsonScoreInterval($vr->conversions, $vr->impressions);
            $vr->confidenceInterval = $ci;

            // Time series data
            $vr->timeSeries = $this->getTimeSeries($experiment->id, $variant->id, $goalId);

            $report->totalImpressions += $vr->impressions;
            $report->totalConversions += $vr->conversions;

            if ($variant->isControl) {
                $controlReport = $vr;
            }

            $variantReports[] = $vr;
        }

        $report->overallConversionRate = $report->totalImpressions > 0
            ? $report->totalConversions / $report->totalImpressions
            : 0.0;

        // Calculate uplift and significance vs control
        if ($controlReport && $controlReport->impressions > 0) {
            foreach ($variantReports as $vr) {
                if ($vr->isControl) {
                    continue;
                }

                // Uplift
                $vr->uplift = $controlReport->conversionRate > 0
                    ? (($vr->conversionRate - $controlReport->conversionRate) / $controlReport->conversionRate) * 100
                    : 0.0;

                // Two-proportion z-test
                $zResult = $this->twoProportionZTest(
                    $vr->conversions,
                    $vr->impressions,
                    $controlReport->conversions,
                    $controlReport->impressions,
                );

                $vr->zScore = $zResult['z'];
                $vr->pValue = $zResult['p'];
                $vr->confidence = (1 - $zResult['p']) * 100;
            }
        }

        $report->variants = $variantReports;

        // Determine winner
        $this->determineWinner($report, $settings->significanceThreshold, $settings->minimumSampleSize);

        return $report;
    }

    /**
     * Two-proportion z-test.
     *
     * @return array{z: float, p: float}
     */
    public function twoProportionZTest(
        int $conversionsA,
        int $impressionsA,
        int $conversionsB,
        int $impressionsB,
    ): array {
        if ($impressionsA <= 0 || $impressionsB <= 0) {
            return ['z' => 0.0, 'p' => 1.0];
        }

        $pA = $conversionsA / $impressionsA;
        $pB = $conversionsB / $impressionsB;
        $nA = $impressionsA;
        $nB = $impressionsB;

        // Pooled proportion
        $pPool = ($conversionsA + $conversionsB) / ($nA + $nB);

        if ($pPool <= 0 || $pPool >= 1) {
            return ['z' => 0.0, 'p' => 1.0];
        }

        // Standard error
        $se = sqrt($pPool * (1 - $pPool) * (1 / $nA + 1 / $nB));

        if ($se <= 0) {
            return ['z' => 0.0, 'p' => 1.0];
        }

        $z = ($pA - $pB) / $se;
        $p = $this->normalCdfComplement(abs($z)) * 2; // Two-tailed

        return ['z' => round($z, 4), 'p' => round($p, 6)];
    }

    /**
     * Wilson score confidence interval for a proportion.
     *
     * @return array{0: float, 1: float}
     */
    public function wilsonScoreInterval(int $successes, int $trials, float $confidence = 0.95): array
    {
        if ($trials <= 0) {
            return [0.0, 0.0];
        }

        // Z-value for confidence level
        $z = match (true) {
            $confidence >= 0.99 => 2.576,
            $confidence >= 0.95 => 1.96,
            $confidence >= 0.90 => 1.645,
            default => 1.96,
        };

        $p = $successes / $trials;
        $n = $trials;

        $denominator = 1 + ($z * $z / $n);
        $center = $p + ($z * $z / (2 * $n));
        $spread = $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $n)) / $n);

        $lower = ($center - $spread) / $denominator;
        $upper = ($center + $spread) / $denominator;

        return [round(max(0, $lower), 6), round(min(1, $upper), 6)];
    }

    /**
     * Get time series data for a variant.
     *
     * @return array{date: string, impressions: int, conversions: int}[]
     */
    public function getTimeSeries(int $experimentId, int $variantId, ?int $goalId = null): array
    {
        // Impressions by date
        $impressions = (new Query())
            ->select(['DATE(dateCreated) as date', 'COUNT(*) as count'])
            ->from('{{%wink_events}}')
            ->where([
                'experimentId' => $experimentId,
                'variantId' => $variantId,
                'eventType' => 'impression',
            ])
            ->groupBy('DATE(dateCreated)')
            ->orderBy('DATE(dateCreated)')
            ->all();

        $conversionsQuery = (new Query())
            ->select(['DATE(dateCreated) as date', 'COUNT(*) as count'])
            ->from('{{%wink_events}}')
            ->where([
                'experimentId' => $experimentId,
                'variantId' => $variantId,
                'eventType' => 'conversion',
            ]);

        if ($goalId !== null) {
            $conversionsQuery->andWhere(['goalId' => $goalId]);
        }

        $conversions = $conversionsQuery
            ->groupBy('DATE(dateCreated)')
            ->orderBy('DATE(dateCreated)')
            ->all();

        // Merge into date-keyed series
        $dates = [];
        foreach ($impressions as $row) {
            $dates[$row['date']] = [
                'date' => $row['date'],
                'impressions' => (int)$row['count'],
                'conversions' => 0,
            ];
        }
        foreach ($conversions as $row) {
            if (!isset($dates[$row['date']])) {
                $dates[$row['date']] = [
                    'date' => $row['date'],
                    'impressions' => 0,
                    'conversions' => 0,
                ];
            }
            $dates[$row['date']]['conversions'] = (int)$row['count'];
        }

        ksort($dates);
        return array_values($dates);
    }

    /**
     * Determine the winner based on significance threshold.
     */
    private function determineWinner(ExperimentReport $report, int $threshold, int $minSample): void
    {
        $confidenceLevel = $threshold / 100;

        $bestVariant = null;
        $bestConfidence = 0;

        foreach ($report->variants as $vr) {
            if ($vr->isControl) {
                continue;
            }

            if ($vr->impressions < $minSample) {
                continue;
            }

            if ($vr->confidence > $bestConfidence && $vr->pValue < (1 - $confidenceLevel)) {
                $bestVariant = $vr;
                $bestConfidence = $vr->confidence;
            }
        }

        if ($bestVariant) {
            $report->winnerVariantId = $bestVariant->variantId;
            $report->confidence = $bestConfidence;
            $report->isSignificant = true;
        }
    }

    /**
     * Complement of the standard normal CDF (1 - Φ(x)).
     * Uses Abramowitz & Stegun approximation (formula 26.2.17).
     */
    private function normalCdfComplement(float $x): float
    {
        if ($x < 0) {
            return 1 - $this->normalCdfComplement(-$x);
        }

        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;
        $p = 0.2316419;

        $t = 1.0 / (1.0 + $p * $x);
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        $t4 = $t3 * $t;
        $t5 = $t4 * $t;

        $phi = exp(-$x * $x / 2) / sqrt(2 * M_PI);

        return $phi * ($b1 * $t + $b2 * $t2 + $b3 * $t3 + $b4 * $t4 + $b5 * $t5);
    }
}
