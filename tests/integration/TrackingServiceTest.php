<?php

namespace justinholtweb\wink\tests\integration;

use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\records\EventRecord;
use justinholtweb\wink\services\TrackingService;

final class TrackingServiceTest extends WinkTestCase
{
    private Experiment $experiment;
    /** @var Variant[] */
    private array $variants;

    private function tracking(): TrackingService
    {
        return Plugin::getInstance()->tracking;
    }

    private function fixture(): void
    {
        $this->experiment = $this->createExperiment([
            'handle' => 'tracking-test',
            'experimentStatus' => 'running',
        ]);
        $this->variants = $this->addVariants($this->experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'challenger'],
        ]);
    }

    // ---- impressions --------------------------------------------------------

    public function testRecordImpressionPersistsAnEvent(): void
    {
        $this->fixture();

        $this->assertTrue($this->tracking()->recordImpression(
            $this->experiment->id,
            $this->variants[0]->id,
            'visitor-1',
        ));

        $this->assertSame(1, $this->tracking()->getImpressionCount($this->experiment->id));
    }

    public function testImpressionsAreDedupedPerVisitorPerDay(): void
    {
        $this->fixture();

        $first = $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');
        $second = $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');
        $third = $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');

        $this->assertTrue($first);
        $this->assertFalse($second, 'A repeat impression on the same day must be suppressed');
        $this->assertFalse($third);
        $this->assertSame(1, $this->tracking()->getImpressionCount($this->experiment->id));
    }

    public function testDedupIsPerVisitor(): void
    {
        $this->fixture();

        $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');
        $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-2');

        $this->assertSame(2, $this->tracking()->getImpressionCount($this->experiment->id));
    }

    public function testDedupIsPerExperiment(): void
    {
        $this->fixture();
        $other = $this->createExperiment(['handle' => 'other-tracking', 'experimentStatus' => 'running']);
        $otherVariants = $this->addVariants($other, [['handle' => 'control', 'isControl' => true]]);

        $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');
        $this->tracking()->recordImpression($other->id, $otherVariants[0]->id, 'visitor-1');

        $this->assertSame(1, $this->tracking()->getImpressionCount($this->experiment->id));
        $this->assertSame(1, $this->tracking()->getImpressionCount($other->id));
    }

    public function testYesterdaysImpressionDoesNotSuppressTodays(): void
    {
        $this->fixture();

        // Seed an impression dated yesterday for the same visitor.
        $record = new EventRecord();
        $record->experimentId = $this->experiment->id;
        $record->variantId = $this->variants[0]->id;
        $record->visitorId = 'visitor-1';
        $record->eventType = 'impression';
        $record->dateCreated = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
        $record->save(false);

        $this->assertTrue(
            $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1'),
            'A new day should allow a fresh impression',
        );
        $this->assertSame(2, $this->tracking()->getImpressionCount($this->experiment->id));
    }

    // ---- conversions --------------------------------------------------------

    public function testRecordConversionPersistsAnEvent(): void
    {
        $this->fixture();
        $goals = $this->addGoals($this->experiment, [['handle' => 'signup', 'isPrimary' => true]]);

        $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');

        $this->assertTrue($this->tracking()->recordConversion(
            $this->experiment->id,
            $this->variants[0]->id,
            $goals[0]->id,
            'visitor-1',
        ));

        $this->assertSame(1, $this->tracking()->getConversionCount($this->experiment->id));
    }

    public function testConversionsAreNotDeduped(): void
    {
        $this->fixture();

        // Conversions are intentionally repeatable — a visitor can convert more
        // than once (e.g. multiple purchases). The report's rates count each
        // converted visitor once; see testRepeatConversionsCountOnceInTheRate.
        $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');
        $this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, null, 'visitor-1');
        $this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, null, 'visitor-1');

        $this->assertSame(2, $this->tracking()->getConversionCount($this->experiment->id));
    }

    public function testAConversionWithoutAnImpressionIsRefused(): void
    {
        $this->fixture();

        $this->assertFalse($this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, null, 'never-saw-it'));
        $this->assertSame(0, $this->tracking()->getConversionCount($this->experiment->id));
    }

    public function testRepeatConversionsCountOnceInTheRate(): void
    {
        $this->fixture();

        $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, 'visitor-1');
        for ($i = 0; $i < 5; $i++) {
            $this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, null, 'visitor-1');
        }

        $this->assertSame(5, $this->tracking()->getConversionCount($this->experiment->id));
        $this->assertSame(1, $this->tracking()->getConvertedVisitorCount($this->experiment->id));

        $report = \justinholtweb\wink\Plugin::getInstance()->stats->getExperimentReport($this->experiment);
        foreach ($report->variants as $vr) {
            $this->assertLessThanOrEqual(1.0, $vr->conversionRate, $vr->variantHandle);
        }
    }

    public function testAnonymizedAddressesKeepNoHostBits(): void
    {
        $stored = [\justinholtweb\wink\services\TrackingService::class, 'storedAddress'];

        $this->assertSame('203.0.113.0', $stored('203.0.113.77', true));
        $this->assertSame('2001:db8:85a3::', $stored('2001:db8:85a3:8d3:1319:8a2e:370:7348', true));
        $this->assertSame('2001:db8:85a3:8d3:1319:8a2e:370:7348', $stored('2001:db8:85a3:8d3:1319:8a2e:370:7348', false));
    }

    public function testConversionCountFiltersByGoal(): void
    {
        $this->fixture();
        $goals = $this->addGoals($this->experiment, [
            ['handle' => 'signup', 'isPrimary' => true],
            ['handle' => 'purchase'],
        ]);

        foreach (['v1', 'v2', 'v3'] as $visitor) {
            $this->tracking()->recordImpression($this->experiment->id, $this->variants[0]->id, $visitor);
        }

        $this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, $goals[0]->id, 'v1');
        $this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, $goals[1]->id, 'v2');
        $this->tracking()->recordConversion($this->experiment->id, $this->variants[0]->id, $goals[1]->id, 'v3');

        $this->assertSame(3, $this->tracking()->getConversionCount($this->experiment->id));
        $this->assertSame(1, $this->tracking()->getConversionCount($this->experiment->id, null, $goals[0]->id));
        $this->assertSame(2, $this->tracking()->getConversionCount($this->experiment->id, null, $goals[1]->id));
    }

    public function testCountsFilterByVariant(): void
    {
        $this->fixture();

        $this->seedEvents($this->experiment->id, $this->variants[0]->id, 'impression', 5);
        $this->seedEvents($this->experiment->id, $this->variants[1]->id, 'impression', 3);

        $this->assertSame(8, $this->tracking()->getImpressionCount($this->experiment->id));
        $this->assertSame(5, $this->tracking()->getImpressionCount($this->experiment->id, $this->variants[0]->id));
        $this->assertSame(3, $this->tracking()->getImpressionCount($this->experiment->id, $this->variants[1]->id));
    }

    public function testImpressionAndConversionCountsDoNotBleed(): void
    {
        $this->fixture();

        $this->seedEvents($this->experiment->id, $this->variants[0]->id, 'impression', 4);
        $this->seedEvents($this->experiment->id, $this->variants[0]->id, 'conversion', 2);

        $this->assertSame(4, $this->tracking()->getImpressionCount($this->experiment->id));
        $this->assertSame(2, $this->tracking()->getConversionCount($this->experiment->id));
    }

    // ---- unique visitors ----------------------------------------------------

    public function testUniqueVisitorCountDeduplicates(): void
    {
        $this->fixture();

        foreach (['a', 'a', 'b', 'c', 'c', 'c'] as $i => $visitor) {
            $record = new EventRecord();
            $record->experimentId = $this->experiment->id;
            $record->variantId = $this->variants[0]->id;
            $record->visitorId = $visitor;
            $record->eventType = 'impression';
            $record->dateCreated = gmdate('Y-m-d H:i:s');
            $record->save(false);
        }

        $this->assertSame(3, $this->tracking()->getUniqueVisitorCount($this->experiment->id));
    }

    // ---- settings gating ----------------------------------------------------

    public function testTrackingDisabledSuppressesImpressions(): void
    {
        $this->fixture();
        $this->withSettings(['enableTracking' => false]);

        $this->assertFalse($this->tracking()->recordImpression(
            $this->experiment->id,
            $this->variants[0]->id,
            'visitor-1',
        ));
        $this->assertSame(0, $this->tracking()->getImpressionCount($this->experiment->id));
    }

    public function testTrackingDisabledSuppressesConversions(): void
    {
        $this->fixture();
        $this->withSettings(['enableTracking' => false]);

        $this->assertFalse($this->tracking()->recordConversion(
            $this->experiment->id,
            $this->variants[0]->id,
            null,
            'visitor-1',
        ));
        $this->assertSame(0, $this->tracking()->getConversionCount($this->experiment->id));
    }

    // ---- IP handling --------------------------------------------------------

    public function testAnonymizeIpZeroesTheLastOctet(): void
    {
        $this->fixture();
        $this->setClientIp('203.0.113.45');
        $this->withSettings(['anonymizeIp' => true]);

        // Supply the URL explicitly: there is no real request URI under CLI.
        $this->tracking()->recordImpression(
            $this->experiment->id,
            $this->variants[0]->id,
            'visitor-1',
            ['url' => 'https://example.com/landing'],
        );

        $record = EventRecord::find()->where(['experimentId' => $this->experiment->id])->one();

        $this->assertSame('203.0.113.0', $record->ipAddress, 'The final octet should be zeroed');
    }

    public function testFullIpIsStoredWhenAnonymisationIsOff(): void
    {
        $this->fixture();
        $this->setClientIp('203.0.113.45');
        $this->withSettings(['anonymizeIp' => false]);

        // Supply the URL explicitly: there is no real request URI under CLI.
        $this->tracking()->recordImpression(
            $this->experiment->id,
            $this->variants[0]->id,
            'visitor-1',
            ['url' => 'https://example.com/landing'],
        );

        $record = EventRecord::find()->where(['experimentId' => $this->experiment->id])->one();

        $this->assertSame('203.0.113.45', $record->ipAddress);
    }

    // ---- context ------------------------------------------------------------

    public function testContextOverridesArePersisted(): void
    {
        $this->fixture();

        $this->tracking()->recordImpression(
            $this->experiment->id,
            $this->variants[0]->id,
            'visitor-1',
            [
                'url' => 'https://example.com/landing',
                'referrer' => 'https://google.com/',
                'userAgent' => 'TestAgent/1.0',
            ],
        );

        $record = EventRecord::find()->where(['experimentId' => $this->experiment->id])->one();

        $this->assertSame('https://example.com/landing', $record->url);
        $this->assertSame('https://google.com/', $record->referrer);
        $this->assertSame('TestAgent/1.0', $record->userAgent);
    }

    // ---- retention ----------------------------------------------------------

    public function testPurgeDeletesEventsBeyondRetention(): void
    {
        $this->fixture();
        $this->withSettings(['retentionDays' => 30]);

        $this->seedEvents(
            $this->experiment->id,
            $this->variants[0]->id,
            'impression',
            3,
            null,
            gmdate('Y-m-d H:i:s', strtotime('-60 days')),
            'old',
        );
        $this->seedEvents(
            $this->experiment->id,
            $this->variants[0]->id,
            'impression',
            2,
            null,
            gmdate('Y-m-d H:i:s'),
            'new',
        );

        $deleted = $this->tracking()->purgeOldEvents();

        $this->assertSame(3, $deleted);
        $this->assertSame(2, $this->tracking()->getImpressionCount($this->experiment->id));
    }

    public function testPurgeKeepsEventsInsideRetention(): void
    {
        $this->fixture();
        $this->withSettings(['retentionDays' => 90]);

        $this->seedEvents(
            $this->experiment->id,
            $this->variants[0]->id,
            'impression',
            4,
            null,
            gmdate('Y-m-d H:i:s', strtotime('-10 days')),
        );

        $this->assertSame(0, $this->tracking()->purgeOldEvents());
        $this->assertSame(4, $this->tracking()->getImpressionCount($this->experiment->id));
    }
}
