<?php

namespace justinholtweb\wink\tests\integration;

use Craft;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\ExperimentStatus;
use justinholtweb\wink\enums\GoalType;

final class ExperimentElementTest extends WinkTestCase
{
    public function testSaveAndReloadRoundTripsAllColumns(): void
    {
        $experiment = $this->createExperiment([
            'title' => 'Headline Test',
            'handle' => 'headline-test',
            'description' => 'Testing the hero headline',
            'trafficPercent' => 60,
        ]);

        $this->assertNotNull($experiment->id);

        $reloaded = Experiment::find()->id($experiment->id)->one();

        $this->assertNotNull($reloaded);
        $this->assertSame('Headline Test', $reloaded->title);
        $this->assertSame('headline-test', $reloaded->handle);
        $this->assertSame('Testing the hero headline', $reloaded->description);
        $this->assertSame('draft', $reloaded->experimentStatus);
        $this->assertSame(60, $reloaded->trafficPercent);
    }

    public function testHandleIsRequired(): void
    {
        $experiment = new Experiment();
        $experiment->title = 'No Handle';
        $experiment->handle = '';

        $this->assertFalse(Craft::$app->getElements()->saveElement($experiment));
        $this->assertArrayHasKey('handle', $experiment->getErrors());
    }

    /**
     * @dataProvider invalidHandleProvider
     */
    public function testInvalidHandlesAreRejected(string $handle): void
    {
        $experiment = new Experiment();
        $experiment->title = 'Bad Handle';
        $experiment->handle = $handle;

        $this->assertFalse(
            Craft::$app->getElements()->saveElement($experiment),
            "Handle '$handle' should have been rejected",
        );
    }

    public static function invalidHandleProvider(): array
    {
        return [
            'starts with a digit' => ['1abc'],
            'starts with a hyphen' => ['-abc'],
            'uppercase' => ['Abc'],
            'underscore' => ['a_b'],
            'space' => ['a b'],
            'special character' => ['a!b'],
        ];
    }

    /**
     * @dataProvider validHandleProvider
     */
    public function testValidHandlesAreAccepted(string $handle): void
    {
        $experiment = new Experiment();
        $experiment->title = 'Good Handle';
        $experiment->handle = $handle;

        $this->assertTrue(
            Craft::$app->getElements()->saveElement($experiment),
            "Handle '$handle' should have been accepted: " . json_encode($experiment->getErrors()),
        );
    }

    public static function validHandleProvider(): array
    {
        return [
            'simple' => ['abc'],
            'hyphenated' => ['a-b-c'],
            'with digits' => ['test-2024'],
            'single letter' => ['a'],
        ];
    }

    /**
     * @dataProvider outOfRangeTrafficProvider
     */
    public function testTrafficPercentIsRangeChecked(int $percent): void
    {
        $experiment = new Experiment();
        $experiment->title = 'Traffic';
        $experiment->handle = 'traffic-test';
        $experiment->trafficPercent = $percent;

        $this->assertFalse(Craft::$app->getElements()->saveElement($experiment));
        $this->assertArrayHasKey('trafficPercent', $experiment->getErrors());
    }

    public static function outOfRangeTrafficProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'over 100' => [101]];
    }

    public function testStatusEnumAccessor(): void
    {
        $experiment = $this->createExperiment(['experimentStatus' => 'running']);

        $this->assertSame(ExperimentStatus::Running, $experiment->getExperimentStatusEnum());
        $this->assertSame('running', $experiment->getStatus());
    }

    public function testVariantsPersistAndReload(): void
    {
        $experiment = $this->createExperiment(['handle' => 'variant-persist']);
        $this->addVariants($experiment, [
            ['handle' => 'control', 'title' => 'Control', 'weight' => 50, 'isControl' => true],
            ['handle' => 'challenger', 'title' => 'Challenger', 'weight' => 50],
        ]);

        $reloaded = Experiment::find()->id($experiment->id)->one();
        $variants = $reloaded->getVariants();

        $this->assertCount(2, $variants);
        $this->assertSame('control', $variants[0]->handle);
        $this->assertTrue($variants[0]->isControl);
        $this->assertSame('challenger', $variants[1]->handle);
        $this->assertFalse($variants[1]->isControl);
        $this->assertSame(2, $reloaded->getVariantCount());
    }

    public function testVariantsAreOrderedBySortOrder(): void
    {
        $experiment = $this->createExperiment(['handle' => 'sort-order']);
        $this->addVariants($experiment, [
            ['handle' => 'first'],
            ['handle' => 'second'],
            ['handle' => 'third'],
        ]);

        $handles = array_map(
            fn($v) => $v->handle,
            Experiment::find()->id($experiment->id)->one()->getVariants(),
        );

        $this->assertSame(['first', 'second', 'third'], $handles);
    }

    public function testPrimaryGoalPrefersTheFlaggedGoal(): void
    {
        $experiment = $this->createExperiment(['handle' => 'primary-goal']);
        $this->addGoals($experiment, [
            ['handle' => 'secondary', 'goalType' => GoalType::Click],
            ['handle' => 'main', 'goalType' => GoalType::FormSubmit, 'isPrimary' => true],
        ]);

        $reloaded = Experiment::find()->id($experiment->id)->one();

        $this->assertSame('main', $reloaded->getPrimaryGoal()->handle);
    }

    public function testPrimaryGoalFallsBackToTheFirstGoal(): void
    {
        $experiment = $this->createExperiment(['handle' => 'no-primary']);
        $this->addGoals($experiment, [
            ['handle' => 'only-goal', 'goalType' => GoalType::Pageview],
        ]);

        $reloaded = Experiment::find()->id($experiment->id)->one();

        $this->assertSame('only-goal', $reloaded->getPrimaryGoal()->handle);
    }

    public function testPrimaryGoalIsNullWithoutGoals(): void
    {
        $experiment = $this->createExperiment(['handle' => 'goalless']);

        $this->assertNull($experiment->getPrimaryGoal());
    }

    public function testGoalTypeIsRehydratedAsAnEnum(): void
    {
        $experiment = $this->createExperiment(['handle' => 'goal-enum']);
        $this->addGoals($experiment, [
            ['handle' => 'cta', 'goalType' => GoalType::Click, 'goalTarget' => '.cta-button'],
        ]);

        $goal = Experiment::find()->id($experiment->id)->one()->getGoals()[0];

        $this->assertSame(GoalType::Click, $goal->goalType);
        $this->assertSame('.cta-button', $goal->goalTarget);
    }

    /**
     * The element index renders the status column through getAttributeHtml().
     */
    public function testStatusColumnRendersStatusMarkup(): void
    {
        $experiment = $this->createExperiment([
            'handle' => 'status-column',
            'experimentStatus' => 'running',
        ]);

        $html = $experiment->getAttributeHtml('experimentStatus');

        $this->assertStringContainsString('Running', $html);
        $this->assertStringContainsString('green', $html);
    }

    public function testTrafficPercentColumnRendersAsAPercentage(): void
    {
        $experiment = $this->createExperiment(['handle' => 'traffic-column', 'trafficPercent' => 25]);

        $this->assertSame('25%', $experiment->getAttributeHtml('trafficPercent'));
    }

    public function testVariantCountColumnRendersTheCount(): void
    {
        $experiment = $this->createExperiment(['handle' => 'count-column']);
        $this->addVariants($experiment, [['handle' => 'a'], ['handle' => 'b']]);

        $this->assertSame('2', $experiment->getAttributeHtml('variantCount'));
    }

    public function testDeletingAnExperimentCascadesToVariants(): void
    {
        $experiment = $this->createExperiment(['handle' => 'cascade']);
        $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);
        $experimentId = $experiment->id;

        Craft::$app->getElements()->deleteElement($experiment, true);

        $this->assertNull(Experiment::find()->id($experimentId)->one());
    }
}
