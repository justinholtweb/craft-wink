<?php

namespace justinholtweb\wink\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Goal;
use justinholtweb\wink\models\Settings;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\records\EventRecord;

/**
 * Shared fixtures for integration tests that run against a booted Craft app
 * and a real database.
 */
abstract class WinkTestCase extends TestCase
{
    private ?Settings $originalSettings = null;

    protected function tearDown(): void
    {
        // Settings live on the plugin instance, which survives the per-test
        // database rollback, so anything a test changed must be put back.
        if ($this->originalSettings !== null) {
            $settings = Plugin::getInstance()->getSettings();
            foreach ($this->originalSettings->toArray() as $key => $value) {
                $settings->$key = $value;
            }
            $this->originalSettings = null;
        }

        if ($this->forcedWebRequest) {
            Craft::$app->getRequest()->setIsConsoleRequest(null);
            $this->forcedWebRequest = false;
        }

        parent::tearDown();
    }

    /**
     * Mutate plugin settings for the duration of one test.
     */
    protected function withSettings(array $overrides): Settings
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($this->originalSettings === null) {
            $this->originalSettings = new Settings();
            foreach ($settings->toArray() as $key => $value) {
                $this->originalSettings->$key = $value;
            }
        }

        foreach ($overrides as $key => $value) {
            $settings->$key = $value;
        }

        return $settings;
    }

    private bool $forcedWebRequest = false;

    /**
     * Point the current request at a client IP.
     *
     * Under CLI, Yii reports the request as a console request, which makes
     * TrackingService skip IP/URL capture entirely — so the request is also
     * flipped to web mode here. Craft memoises the resolved address, so the
     * cached value has to be cleared alongside $_SERVER.
     */
    protected function setClientIp(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;

        $request = Craft::$app->getRequest();
        $request->setIsConsoleRequest(false);
        $this->forcedWebRequest = true;

        $property = new \ReflectionProperty($request, '_ipAddress');
        $property->setAccessible(true);
        $property->setValue($request, null);
    }

    /**
     * Put a cookie on the *request* (Craft's request cookie collection is
     * read-only, so it has to be swapped wholesale).
     */
    protected function setRequestCookie(string $name, string $value): void
    {
        $collection = new \yii\web\CookieCollection(
            [$name => new \yii\web\Cookie(['name' => $name, 'value' => $value])],
            ['readOnly' => false],
        );

        $property = new \ReflectionProperty(\yii\web\Request::class, '_cookies');
        $property->setAccessible(true);
        $property->setValue(Craft::$app->getRequest(), $collection);
    }

    /**
     * Create and save an Experiment element.
     */
    protected function createExperiment(array $config = []): Experiment
    {
        $experiment = new Experiment();
        $experiment->title = $config['title'] ?? 'Test Experiment';
        $experiment->handle = $config['handle'] ?? 'test-experiment';
        $experiment->description = $config['description'] ?? null;
        $experiment->experimentStatus = $config['experimentStatus'] ?? 'draft';
        $experiment->trafficPercent = $config['trafficPercent'] ?? 100;

        if (!Craft::$app->getElements()->saveElement($experiment)) {
            $this->fail('Could not save experiment: ' . json_encode($experiment->getErrors()));
        }

        return $experiment;
    }

    /**
     * Attach variants to an experiment. Each spec is
     * ['handle' => ..., 'title' => ..., 'weight' => ..., 'isControl' => ...].
     *
     * @return Variant[] freshly loaded, with database IDs
     */
    protected function addVariants(Experiment $experiment, array $specs): array
    {
        $variants = [];
        foreach ($specs as $spec) {
            $variant = new Variant();
            $variant->handle = $spec['handle'];
            $variant->title = $spec['title'] ?? ucfirst($spec['handle']);
            $variant->content = $spec['content'] ?? null;
            $variant->weight = $spec['weight'] ?? 50;
            $variant->isControl = $spec['isControl'] ?? false;
            $variants[] = $variant;
        }

        if (!Plugin::getInstance()->experiments->saveVariants($experiment->id, $variants)) {
            $this->fail('Could not save variants');
        }

        $fresh = Plugin::getInstance()->experiments->getVariantsByExperimentId($experiment->id);
        $experiment->setVariants($fresh);

        return $fresh;
    }

    /**
     * Attach goals to an experiment.
     *
     * @return Goal[]
     */
    protected function addGoals(Experiment $experiment, array $specs): array
    {
        $goals = [];
        foreach ($specs as $spec) {
            $goal = new Goal();
            $goal->name = $spec['name'] ?? ucfirst($spec['handle']);
            $goal->handle = $spec['handle'];
            $goal->goalType = $spec['goalType'] ?? GoalType::Pageview;
            $goal->goalTarget = $spec['goalTarget'] ?? null;
            $goal->isPrimary = $spec['isPrimary'] ?? false;
            $goals[] = $goal;
        }

        if (!Plugin::getInstance()->experiments->saveGoals($experiment->id, $goals)) {
            $this->fail('Could not save goals');
        }

        $fresh = Plugin::getInstance()->experiments->getGoalsByExperimentId($experiment->id);
        $experiment->setGoals($fresh);

        return $fresh;
    }

    /**
     * Insert event rows directly, bypassing TrackingService, so tests can
     * control counts, visitor IDs and timestamps precisely.
     */
    protected function seedEvents(
        int $experimentId,
        int $variantId,
        string $eventType,
        int $count,
        ?int $goalId = null,
        ?string $dateCreated = null,
        string $visitorPrefix = 'visitor',
    ): void {
        $date = $dateCreated ?? gmdate('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            $record = new EventRecord();
            $record->experimentId = $experimentId;
            $record->variantId = $variantId;
            $record->goalId = $goalId;
            $record->visitorId = sprintf('%s-%d-%d', $visitorPrefix, $variantId, $i);
            $record->eventType = $eventType;
            $record->dateCreated = $date;

            if (!$record->save(false)) {
                $this->fail('Could not seed event');
            }
        }
    }

    protected function countEvents(int $experimentId, string $eventType): int
    {
        return (int)EventRecord::find()
            ->where(['experimentId' => $experimentId, 'eventType' => $eventType])
            ->count();
    }
}
