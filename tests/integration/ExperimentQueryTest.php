<?php

namespace justinholtweb\wink\tests\integration;

use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\ExperimentStatus;

final class ExperimentQueryTest extends WinkTestCase
{
    private function seedThree(): void
    {
        $this->createExperiment(['handle' => 'alpha', 'experimentStatus' => 'running', 'trafficPercent' => 50]);
        $this->createExperiment(['handle' => 'beta', 'experimentStatus' => 'paused', 'trafficPercent' => 100]);
        $this->createExperiment(['handle' => 'gamma', 'experimentStatus' => 'running', 'trafficPercent' => 100]);
    }

    public function testFilterByHandle(): void
    {
        $this->seedThree();

        $results = Experiment::find()->handle('beta')->all();

        $this->assertCount(1, $results);
        $this->assertSame('beta', $results[0]->handle);
    }

    public function testFilterByStatusString(): void
    {
        $this->seedThree();

        $handles = array_map(fn($e) => $e->handle, Experiment::find()->experimentStatus('running')->all());
        sort($handles);

        $this->assertSame(['alpha', 'gamma'], $handles);
    }

    public function testFilterByStatusEnum(): void
    {
        $this->seedThree();

        $results = Experiment::find()->experimentStatus(ExperimentStatus::Paused)->all();

        $this->assertCount(1, $results);
        $this->assertSame('beta', $results[0]->handle);
    }

    public function testFilterByTrafficPercent(): void
    {
        $this->seedThree();

        $results = Experiment::find()->trafficPercent(50)->all();

        $this->assertCount(1, $results);
        $this->assertSame('alpha', $results[0]->handle);
    }

    public function testFiltersCombine(): void
    {
        $this->seedThree();

        $results = Experiment::find()
            ->experimentStatus('running')
            ->trafficPercent(100)
            ->all();

        $this->assertCount(1, $results);
        $this->assertSame('gamma', $results[0]->handle);
    }

    public function testNullFiltersAreIgnored(): void
    {
        $this->seedThree();

        $this->assertCount(3, Experiment::find()->handle(null)->experimentStatus(null)->all());
    }

    public function testCustomColumnsAreSelected(): void
    {
        $this->createExperiment([
            'handle' => 'columns',
            'description' => 'A description',
            'experimentStatus' => 'running',
            'trafficPercent' => 42,
        ]);

        $experiment = Experiment::find()->handle('columns')->one();

        $this->assertSame('columns', $experiment->handle);
        $this->assertSame('A description', $experiment->description);
        $this->assertSame('running', $experiment->experimentStatus);
        $this->assertSame(42, $experiment->trafficPercent);
    }

    public function testStatusConditionDrivesTheStatusParam(): void
    {
        $this->seedThree();

        // `status` is Craft's generic element-status param, mapped through
        // ExperimentQuery::statusCondition().
        $handles = array_map(fn($e) => $e->handle, Experiment::find()->status('running')->all());
        sort($handles);

        $this->assertSame(['alpha', 'gamma'], $handles);
    }

    public function testCountMatchesFilters(): void
    {
        $this->seedThree();

        $this->assertSame(2, (int)Experiment::find()->experimentStatus('running')->count());
        $this->assertSame(1, (int)Experiment::find()->experimentStatus('paused')->count());
        $this->assertSame(0, (int)Experiment::find()->experimentStatus('archived')->count());
    }

    public function testUnknownHandleReturnsNothing(): void
    {
        $this->seedThree();

        $this->assertNull(Experiment::find()->handle('nope')->one());
        $this->assertSame([], Experiment::find()->handle('nope')->all());
    }
}
