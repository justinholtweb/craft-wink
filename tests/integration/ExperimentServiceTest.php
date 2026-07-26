<?php

namespace justinholtweb\wink\tests\integration;

use justinholtweb\wink\enums\ExperimentStatus;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\records\VariantRecord;
use justinholtweb\wink\services\ExperimentService;

final class ExperimentServiceTest extends WinkTestCase
{
    private function service(): ExperimentService
    {
        return Plugin::getInstance()->experiments;
    }

    // ---- lookups ------------------------------------------------------------

    public function testGetExperimentByHandle(): void
    {
        $experiment = $this->createExperiment(['handle' => 'lookup-test']);

        $found = $this->service()->getExperimentByHandle('lookup-test');

        $this->assertNotNull($found);
        $this->assertSame($experiment->id, $found->id);
    }

    public function testGetExperimentByHandleReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->service()->getExperimentByHandle('does-not-exist'));
    }

    public function testGetRunningExperimentOnlyMatchesRunning(): void
    {
        $this->createExperiment(['handle' => 'paused-one', 'experimentStatus' => 'paused']);

        $this->assertNull($this->service()->getRunningExperiment('paused-one'));
    }

    public function testGetRunningExperimentMatchesRunning(): void
    {
        $experiment = $this->createExperiment(['handle' => 'live-one', 'experimentStatus' => 'running']);

        $found = $this->service()->getRunningExperiment('live-one');

        $this->assertNotNull($found);
        $this->assertSame($experiment->id, $found->id);
    }

    // ---- status lifecycle ---------------------------------------------------

    public function testStartSetsRunningAndStampsStartDate(): void
    {
        $experiment = $this->createExperiment(['handle' => 'startable']);

        $this->assertTrue($this->service()->startExperiment($experiment));
        $this->assertSame('running', $experiment->experimentStatus);
        $this->assertNotNull($experiment->startDate);

        $reloaded = $this->service()->getExperimentById($experiment->id);
        $this->assertSame('running', $reloaded->experimentStatus);
    }

    public function testPauseRequiresRunning(): void
    {
        $draft = $this->createExperiment(['handle' => 'draft-pause']);

        $this->assertFalse($this->service()->pauseExperiment($draft));
        $this->assertSame('draft', $draft->experimentStatus);
    }

    public function testPauseFromRunning(): void
    {
        $experiment = $this->createExperiment(['handle' => 'pausable', 'experimentStatus' => 'running']);

        $this->assertTrue($this->service()->pauseExperiment($experiment));
        $this->assertSame('paused', $experiment->experimentStatus);
    }

    public function testCompleteStampsEndDateAndWinner(): void
    {
        $experiment = $this->createExperiment(['handle' => 'completable', 'experimentStatus' => 'running']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'challenger'],
        ]);

        $this->assertTrue($this->service()->completeExperiment($experiment, $variants[1]->id));
        $this->assertSame('completed', $experiment->experimentStatus);
        $this->assertNotNull($experiment->endDate);
        $this->assertSame($variants[1]->id, $experiment->winnerVariantId);

        $reloaded = $this->service()->getExperimentById($experiment->id);
        $this->assertSame($variants[1]->id, $reloaded->winnerVariantId);
    }

    public function testArchiveRequiresAnArchivableStatus(): void
    {
        $running = $this->createExperiment(['handle' => 'running-archive', 'experimentStatus' => 'running']);

        // Running must be paused or completed before it can be archived.
        $this->assertFalse($this->service()->archiveExperiment($running));
        $this->assertSame('running', $running->experimentStatus);
    }

    public function testArchiveFromPaused(): void
    {
        $experiment = $this->createExperiment(['handle' => 'paused-archive', 'experimentStatus' => 'paused']);

        $this->assertTrue($this->service()->archiveExperiment($experiment));
        $this->assertSame('archived', $experiment->experimentStatus);
    }

    public function testArchivedIsTerminal(): void
    {
        $experiment = $this->createExperiment(['handle' => 'terminal', 'experimentStatus' => 'archived']);

        $this->assertFalse($this->service()->startExperiment($experiment));
        $this->assertFalse($this->service()->pauseExperiment($experiment));
        $this->assertFalse($this->service()->completeExperiment($experiment));
        $this->assertFalse($this->service()->archiveExperiment($experiment));
        $this->assertSame('archived', $experiment->experimentStatus);
    }

    public function testFullHappyPathLifecycle(): void
    {
        $experiment = $this->createExperiment(['handle' => 'lifecycle']);

        $this->assertTrue($this->service()->startExperiment($experiment));
        $this->assertTrue($this->service()->pauseExperiment($experiment));
        $this->assertTrue($this->service()->startExperiment($experiment));
        $this->assertTrue($this->service()->completeExperiment($experiment));
        $this->assertTrue($this->service()->archiveExperiment($experiment));

        $this->assertSame(ExperimentStatus::Archived, $experiment->getExperimentStatusEnum());
    }

    // ---- variants -----------------------------------------------------------

    public function testSaveVariantsAssignsSortOrderAndIds(): void
    {
        $experiment = $this->createExperiment(['handle' => 'variant-ids']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'b'],
            ['handle' => 'c'],
        ]);

        $this->assertSame([0, 1, 2], array_map(fn($v) => $v->sortOrder, $variants));
        foreach ($variants as $variant) {
            $this->assertNotNull($variant->id);
        }
    }

    public function testSaveVariantsUpdatesExistingRatherThanDuplicating(): void
    {
        $experiment = $this->createExperiment(['handle' => 'variant-update']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'title' => 'Original', 'isControl' => true],
        ]);
        $originalId = $variants[0]->id;

        $variants[0]->title = 'Renamed';
        $this->assertTrue($this->service()->saveVariants($experiment->id, $variants));

        $fresh = $this->service()->getVariantsByExperimentId($experiment->id);
        $this->assertCount(1, $fresh);
        $this->assertSame($originalId, $fresh[0]->id, 'The row should have been updated in place');
        $this->assertSame('Renamed', $fresh[0]->title);
    }

    public function testSaveVariantsDeletesOmittedVariants(): void
    {
        $experiment = $this->createExperiment(['handle' => 'variant-delete']);
        $variants = $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'doomed'],
        ]);
        $doomedId = $variants[1]->id;

        // Re-save with only the control.
        $this->assertTrue($this->service()->saveVariants($experiment->id, [$variants[0]]));

        $fresh = $this->service()->getVariantsByExperimentId($experiment->id);
        $this->assertCount(1, $fresh);
        $this->assertSame('control', $fresh[0]->handle);
        $this->assertNull(VariantRecord::findOne($doomedId));
    }

    public function testGetVariantById(): void
    {
        $experiment = $this->createExperiment(['handle' => 'variant-by-id']);
        $variants = $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);

        $found = $this->service()->getVariantById($variants[0]->id);

        $this->assertNotNull($found);
        $this->assertSame('control', $found->handle);
        $this->assertTrue($found->isControl);
    }

    public function testGetVariantByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->service()->getVariantById(999999));
    }

    public function testVariantsAreScopedToTheirExperiment(): void
    {
        $a = $this->createExperiment(['handle' => 'scope-a']);
        $b = $this->createExperiment(['handle' => 'scope-b']);
        $this->addVariants($a, [['handle' => 'a-control', 'isControl' => true]]);
        $this->addVariants($b, [['handle' => 'b-control', 'isControl' => true]]);

        $aVariants = $this->service()->getVariantsByExperimentId($a->id);

        $this->assertCount(1, $aVariants);
        $this->assertSame('a-control', $aVariants[0]->handle);
    }

    public function testSaveVariantsWithEmptyArrayClearsThemAll(): void
    {
        $experiment = $this->createExperiment(['handle' => 'clear-variants']);
        $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);

        $this->assertTrue($this->service()->saveVariants($experiment->id, []));
        $this->assertCount(0, $this->service()->getVariantsByExperimentId($experiment->id));
    }

    public function testNewVariantWithAForeignIdIsInsertedNotHijacked(): void
    {
        $other = $this->createExperiment(['handle' => 'other-exp']);
        $otherVariants = $this->addVariants($other, [['handle' => 'other-control', 'isControl' => true]]);

        $experiment = $this->createExperiment(['handle' => 'hijack-target']);

        // An id belonging to a different experiment must not be adopted.
        $variant = new Variant();
        $variant->id = $otherVariants[0]->id;
        $variant->handle = 'mine';
        $variant->title = 'Mine';

        $this->assertTrue($this->service()->saveVariants($experiment->id, [$variant]));

        $untouched = $this->service()->getVariantById($otherVariants[0]->id);
        $this->assertSame('other-control', $untouched->handle, 'The other experiment\'s variant must be untouched');
    }

    // ---- goals --------------------------------------------------------------

    public function testGetGoalByHandleIsScopedToTheExperiment(): void
    {
        $a = $this->createExperiment(['handle' => 'goal-scope-a']);
        $b = $this->createExperiment(['handle' => 'goal-scope-b']);
        $this->addGoals($a, [['handle' => 'signup', 'goalType' => GoalType::FormSubmit]]);
        $this->addGoals($b, [['handle' => 'signup', 'goalType' => GoalType::Click]]);

        $aGoal = $this->service()->getGoalByHandle($a->id, 'signup');
        $bGoal = $this->service()->getGoalByHandle($b->id, 'signup');

        $this->assertSame(GoalType::FormSubmit, $aGoal->goalType);
        $this->assertSame(GoalType::Click, $bGoal->goalType);
    }

    public function testGetGoalByHandleReturnsNullWhenMissing(): void
    {
        $experiment = $this->createExperiment(['handle' => 'no-such-goal']);

        $this->assertNull($this->service()->getGoalByHandle($experiment->id, 'nope'));
    }

    public function testSaveGoalsDeletesOmittedGoals(): void
    {
        $experiment = $this->createExperiment(['handle' => 'goal-delete']);
        $goals = $this->addGoals($experiment, [
            ['handle' => 'keeper', 'isPrimary' => true],
            ['handle' => 'doomed'],
        ]);

        $this->assertTrue($this->service()->saveGoals($experiment->id, [$goals[0]]));

        $fresh = $this->service()->getGoalsByExperimentId($experiment->id);
        $this->assertCount(1, $fresh);
        $this->assertSame('keeper', $fresh[0]->handle);
    }

    public function testGoalTargetRoundTrips(): void
    {
        $experiment = $this->createExperiment(['handle' => 'goal-target']);
        $this->addGoals($experiment, [
            ['handle' => 'thanks', 'goalType' => GoalType::Pageview, 'goalTarget' => '/thank-you*'],
        ]);

        $goal = $this->service()->getGoalByHandle($experiment->id, 'thanks');

        $this->assertSame('/thank-you*', $goal->goalTarget);
        $this->assertSame(GoalType::Pageview, $goal->goalType);
    }
}
