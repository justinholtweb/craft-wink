<?php

namespace justinholtweb\wink\tests\integration;

use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\VariantReport;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\services\StatsService;

/**
 * End-to-end report generation against real event rows.
 */
final class StatsReportTest extends WinkTestCase
{
    private function stats(): StatsService
    {
        return Plugin::getInstance()->stats;
    }

    /**
     * Build a two-variant experiment and seed impression/conversion counts.
     *
     * @return array{Experiment, array}
     */
    private function seedExperiment(
        int $controlImpressions,
        int $controlConversions,
        int $challengerImpressions,
        int $challengerConversions,
        string $handle = 'stats-test',
    ): array {
        $experiment = $this->createExperiment(['handle' => $handle, 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'title' => 'Control', 'isControl' => true],
            ['handle' => 'challenger', 'title' => 'Challenger'],
        ]);

        $this->seedEvents($experiment->id, $variants[0]->id, 'impression', $controlImpressions, null, null, 'ci');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', $controlConversions, null, null, 'cc');
        $this->seedEvents($experiment->id, $variants[1]->id, 'impression', $challengerImpressions, null, null, 'hi');
        $this->seedEvents($experiment->id, $variants[1]->id, 'conversion', $challengerConversions, null, null, 'hc');

        return [$experiment, $variants];
    }

    private function variantReport(object $report, string $handle): VariantReport
    {
        foreach ($report->variants as $vr) {
            if ($vr->variantHandle === $handle) {
                return $vr;
            }
        }

        $this->fail("No variant report for '$handle'");
    }

    // ---- totals -------------------------------------------------------------

    public function testReportAggregatesTotals(): void
    {
        [$experiment] = $this->seedExperiment(100, 10, 100, 20);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertSame($experiment->id, $report->experimentId);
        $this->assertSame(200, $report->totalImpressions);
        $this->assertSame(30, $report->totalConversions);
        $this->assertEqualsWithDelta(0.15, $report->overallConversionRate, 1e-9);
    }

    public function testPerVariantRatesAreComputed(): void
    {
        [$experiment] = $this->seedExperiment(200, 20, 100, 30);

        $report = $this->stats()->getExperimentReport($experiment);

        $control = $this->variantReport($report, 'control');
        $challenger = $this->variantReport($report, 'challenger');

        $this->assertSame(200, $control->impressions);
        $this->assertSame(20, $control->conversions);
        $this->assertEqualsWithDelta(0.10, $control->conversionRate, 1e-9);

        $this->assertSame(100, $challenger->impressions);
        $this->assertSame(30, $challenger->conversions);
        $this->assertEqualsWithDelta(0.30, $challenger->conversionRate, 1e-9);
    }

    public function testEmptyExperimentProducesZeroedReport(): void
    {
        $experiment = $this->createExperiment(['handle' => 'empty-stats']);
        $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'challenger'],
        ]);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertSame(0, $report->totalImpressions);
        $this->assertSame(0, $report->totalConversions);
        $this->assertSame(0.0, $report->overallConversionRate);
        $this->assertNull($report->winnerVariantId);
        $this->assertFalse($report->isSignificant);
    }

    public function testExperimentWithNoVariantsProducesEmptyReport(): void
    {
        $experiment = $this->createExperiment(['handle' => 'no-variants']);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertSame([], $report->variants);
        $this->assertSame(0, $report->totalImpressions);
    }

    // ---- uplift -------------------------------------------------------------

    public function testUpliftIsRelativeToControl(): void
    {
        // Control 10%, challenger 30% => +200% uplift.
        [$experiment] = $this->seedExperiment(100, 10, 100, 30);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertEqualsWithDelta(200.0, $this->variantReport($report, 'challenger')->uplift, 1e-6);
    }

    public function testNegativeUpliftForAWorseVariant(): void
    {
        // Control 20%, challenger 10% => -50% uplift.
        [$experiment] = $this->seedExperiment(100, 20, 100, 10);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertEqualsWithDelta(-50.0, $this->variantReport($report, 'challenger')->uplift, 1e-6);
    }

    public function testControlHasNoUpliftAgainstItself(): void
    {
        [$experiment] = $this->seedExperiment(100, 10, 100, 30);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertSame(0.0, $this->variantReport($report, 'control')->uplift);
    }

    // ---- confidence intervals ----------------------------------------------

    public function testConfidenceIntervalBracketsTheObservedRate(): void
    {
        [$experiment] = $this->seedExperiment(1000, 100, 1000, 150);

        $report = $this->stats()->getExperimentReport($experiment);
        $control = $this->variantReport($report, 'control');

        [$lower, $upper] = $control->confidenceInterval;

        $this->assertLessThan($control->conversionRate, $lower);
        $this->assertGreaterThan($control->conversionRate, $upper);
    }

    // ---- winner determination ----------------------------------------------

    public function testClearWinnerIsDeclared(): void
    {
        // 10% vs 20% over 1000 each — a large, unambiguous improvement.
        [$experiment, $variants] = $this->seedExperiment(1000, 100, 1000, 200);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertTrue($report->isSignificant);
        $this->assertSame($variants[1]->id, $report->winnerVariantId);
        $this->assertGreaterThan(95.0, $report->confidence);
    }

    public function testNoWinnerWhenResultsAreEquivalent(): void
    {
        [$experiment] = $this->seedExperiment(1000, 100, 1000, 100);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertFalse($report->isSignificant);
        $this->assertNull($report->winnerVariantId);
    }

    public function testNoWinnerBelowMinimumSampleSize(): void
    {
        $this->withSettings(['minimumSampleSize' => 500]);

        // A huge effect, but only 50 impressions on the challenger.
        [$experiment] = $this->seedExperiment(1000, 100, 50, 40);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertNull($report->winnerVariantId, 'Under-powered results must not declare a winner');
        $this->assertFalse($report->isSignificant);
    }

    /**
     * A significantly WORSE variant must never be declared the winner. The
     * z-test is two-tailed, so significance alone does not imply improvement.
     */
    public function testSignificantlyWorseVariantIsNotDeclaredWinner(): void
    {
        // Control 10%, challenger 2% over 1000 each: highly significant, but worse.
        [$experiment] = $this->seedExperiment(1000, 100, 1000, 20);

        $report = $this->stats()->getExperimentReport($experiment);

        $challenger = $this->variantReport($report, 'challenger');
        $this->assertLessThan(0, $challenger->zScore, 'Sanity check: the challenger is worse');

        $this->assertNull(
            $report->winnerVariantId,
            'A variant that performs significantly worse than control must not win',
        );
        $this->assertFalse($report->isSignificant);
    }

    public function testBestOfSeveralWinnersIsChosen(): void
    {
        $experiment = $this->createExperiment(['handle' => 'multi-variant', 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'good'],
            ['handle' => 'better'],
        ]);

        $this->seedEvents($experiment->id, $variants[0]->id, 'impression', 1000, null, null, 'a');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 100, null, null, 'b');
        $this->seedEvents($experiment->id, $variants[1]->id, 'impression', 1000, null, null, 'c');
        $this->seedEvents($experiment->id, $variants[1]->id, 'conversion', 150, null, null, 'd');
        $this->seedEvents($experiment->id, $variants[2]->id, 'impression', 1000, null, null, 'e');
        $this->seedEvents($experiment->id, $variants[2]->id, 'conversion', 220, null, null, 'f');

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertSame($variants[2]->id, $report->winnerVariantId, 'The strongest variant should win');
    }

    public function testControlIsNeverTheWinner(): void
    {
        // Control massively outperforms; no challenger should be declared.
        [$experiment, $variants] = $this->seedExperiment(1000, 300, 1000, 50);

        $report = $this->stats()->getExperimentReport($experiment);

        $this->assertNotSame($variants[0]->id, $report->winnerVariantId);
    }

    // ---- goal filtering -----------------------------------------------------

    public function testReportCanBeFilteredByGoal(): void
    {
        $experiment = $this->createExperiment(['handle' => 'goal-filtered', 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
        ]);
        $goals = $this->addGoals($experiment, [
            ['handle' => 'signup', 'isPrimary' => true],
            ['handle' => 'purchase'],
        ]);

        $this->seedEvents($experiment->id, $variants[0]->id, 'impression', 100, null, null, 'i');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 30, $goals[0]->id, null, 's');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 10, $goals[1]->id, null, 'p');

        $all = $this->stats()->getExperimentReport($experiment);
        $signupOnly = $this->stats()->getExperimentReport($experiment, $goals[0]->id);
        $purchaseOnly = $this->stats()->getExperimentReport($experiment, $goals[1]->id);

        $this->assertSame(40, $all->totalConversions);
        $this->assertSame(30, $signupOnly->totalConversions);
        $this->assertSame(10, $purchaseOnly->totalConversions);
    }

    // ---- time series --------------------------------------------------------

    public function testTimeSeriesGroupsByDate(): void
    {
        $experiment = $this->createExperiment(['handle' => 'timeseries', 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);

        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        $this->seedEvents($experiment->id, $variants[0]->id, 'impression', 5, null, "$yesterday 10:00:00", 'y');
        $this->seedEvents($experiment->id, $variants[0]->id, 'impression', 3, null, "$today 10:00:00", 't');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 2, null, "$today 11:00:00", 'tc');

        $series = $this->stats()->getTimeSeries($experiment->id, $variants[0]->id);

        $this->assertCount(2, $series);
        $this->assertSame($yesterday, $series[0]['date']);
        $this->assertSame(5, $series[0]['impressions']);
        $this->assertSame(0, $series[0]['conversions']);
        $this->assertSame($today, $series[1]['date']);
        $this->assertSame(3, $series[1]['impressions']);
        $this->assertSame(2, $series[1]['conversions']);
    }

    public function testTimeSeriesIncludesConversionOnlyDays(): void
    {
        $experiment = $this->createExperiment(['handle' => 'conv-only', 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);

        $day = gmdate('Y-m-d', strtotime('-2 days'));
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 4, null, "$day 09:00:00", 'c');

        $series = $this->stats()->getTimeSeries($experiment->id, $variants[0]->id);

        $this->assertCount(1, $series);
        $this->assertSame($day, $series[0]['date']);
        $this->assertSame(0, $series[0]['impressions']);
        $this->assertSame(4, $series[0]['conversions']);
    }

    public function testTimeSeriesIsEmptyWithoutEvents(): void
    {
        $experiment = $this->createExperiment(['handle' => 'no-series']);
        $variants = $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);

        $this->assertSame([], $this->stats()->getTimeSeries($experiment->id, $variants[0]->id));
    }

    public function testTimeSeriesRespectsGoalFilter(): void
    {
        $experiment = $this->createExperiment(['handle' => 'series-goal', 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);
        $goals = $this->addGoals($experiment, [
            ['handle' => 'signup', 'isPrimary' => true],
            ['handle' => 'purchase'],
        ]);

        $day = gmdate('Y-m-d');
        $this->seedEvents($experiment->id, $variants[0]->id, 'impression', 10, null, "$day 08:00:00", 'i');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 6, $goals[0]->id, "$day 09:00:00", 's');
        $this->seedEvents($experiment->id, $variants[0]->id, 'conversion', 1, $goals[1]->id, "$day 09:30:00", 'p');

        $signup = $this->stats()->getTimeSeries($experiment->id, $variants[0]->id, $goals[0]->id);

        $this->assertCount(1, $signup);
        $this->assertSame(6, $signup[0]['conversions']);
    }
}
