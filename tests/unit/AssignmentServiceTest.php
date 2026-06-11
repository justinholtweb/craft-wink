<?php

namespace justinholtweb\wink\tests\unit;

use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\services\AssignmentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for deterministic variant assignment.
 *
 * The Experiment element is mocked without its constructor so these run as
 * pure unit tests with no Craft application boot. Only getVariants() is
 * stubbed; id and trafficPercent are real public properties.
 */
final class AssignmentServiceTest extends TestCase
{
    private AssignmentService $assignment;

    protected function setUp(): void
    {
        $this->assignment = new AssignmentService();
    }

    private function makeVariant(int $id, int $weight, bool $isControl): Variant
    {
        /** @var Variant $v */
        $v = (new ReflectionClass(Variant::class))->newInstanceWithoutConstructor();
        $v->id = $id;
        $v->weight = $weight;
        $v->isControl = $isControl;
        return $v;
    }

    /**
     * @param Variant[] $variants
     */
    private function makeExperiment(int $id, int $trafficPercent, array $variants): Experiment
    {
        $exp = $this->getMockBuilder(Experiment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getVariants'])
            ->getMock();
        $exp->method('getVariants')->willReturn($variants);
        $exp->id = $id;
        $exp->trafficPercent = $trafficPercent;
        return $exp;
    }

    // ---- assignVariant ------------------------------------------------------

    public function testAssignmentIsDeterministic(): void
    {
        $exp = $this->makeExperiment(1, 100, [
            $this->makeVariant(10, 50, true),
            $this->makeVariant(11, 50, false),
        ]);

        $first = $this->assignment->assignVariant('visitor-abc', $exp);
        $this->assertNotNull($first);

        for ($i = 0; $i < 50; $i++) {
            $again = $this->assignment->assignVariant('visitor-abc', $exp);
            $this->assertSame($first->id, $again->id, 'Same visitor must always get the same variant');
        }
    }

    public function testEmptyVariantsReturnsNull(): void
    {
        $exp = $this->makeExperiment(1, 100, []);
        $this->assertNull($this->assignment->assignVariant('visitor-abc', $exp));
    }

    public function testZeroTotalWeightFallsBackToFirstVariant(): void
    {
        $variants = [
            $this->makeVariant(10, 0, true),
            $this->makeVariant(11, 0, false),
        ];
        $exp = $this->makeExperiment(1, 100, $variants);

        $assigned = $this->assignment->assignVariant('visitor-abc', $exp);
        $this->assertSame($variants[0]->id, $assigned->id);
    }

    public function testNotEnrolledReturnsControlVariant(): void
    {
        // 0% traffic -> no one is enrolled -> always the control.
        $exp = $this->makeExperiment(1, 0, [
            $this->makeVariant(10, 50, false),
            $this->makeVariant(11, 50, true), // control
        ]);

        foreach (range(1, 25) as $i) {
            $assigned = $this->assignment->assignVariant("visitor-$i", $exp);
            $this->assertSame(11, $assigned->id, 'Unenrolled visitors get the control variant');
        }
    }

    public function testAssignmentRespectsWeighting(): void
    {
        $heavy = $this->makeVariant(10, 90, true);
        $light = $this->makeVariant(11, 10, false);
        $exp = $this->makeExperiment(1, 100, [$heavy, $light]);

        $counts = [10 => 0, 11 => 0];
        $n = 4000;
        for ($i = 0; $i < $n; $i++) {
            $assigned = $this->assignment->assignVariant("visitor-$i", $exp);
            $counts[$assigned->id]++;
        }

        $heavyShare = $counts[10] / $n;
        // 90/10 split; crc32 is roughly uniform, so heavy should dominate.
        $this->assertGreaterThan(0.82, $heavyShare);
        $this->assertLessThan(0.98, $heavyShare);
        $this->assertGreaterThan(0, $counts[11], 'The light variant must still receive some traffic');
    }

    public function testEvenWeightingIsRoughlyBalanced(): void
    {
        $a = $this->makeVariant(10, 50, true);
        $b = $this->makeVariant(11, 50, false);
        $exp = $this->makeExperiment(1, 100, [$a, $b]);

        $counts = [10 => 0, 11 => 0];
        $n = 4000;
        for ($i = 0; $i < $n; $i++) {
            $assigned = $this->assignment->assignVariant("visitor-$i", $exp);
            $counts[$assigned->id]++;
        }

        $shareA = $counts[10] / $n;
        $this->assertEqualsWithDelta(0.5, $shareA, 0.08, 'A 50/50 split should be roughly balanced');
    }

    // ---- isEnrolled ---------------------------------------------------------

    public function testFullTrafficEnrollsEveryone(): void
    {
        $exp = $this->makeExperiment(7, 100, []);
        foreach (range(1, 100) as $i) {
            $this->assertTrue($this->assignment->isEnrolled("visitor-$i", $exp));
        }
    }

    public function testZeroTrafficEnrollsNoOne(): void
    {
        $exp = $this->makeExperiment(7, 0, []);
        foreach (range(1, 100) as $i) {
            $this->assertFalse($this->assignment->isEnrolled("visitor-$i", $exp));
        }
    }

    public function testEnrollmentIsDeterministic(): void
    {
        $exp = $this->makeExperiment(7, 50, []);
        $first = $this->assignment->isEnrolled('visitor-xyz', $exp);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, $this->assignment->isEnrolled('visitor-xyz', $exp));
        }
    }

    public function testEnrollmentRateApproximatesTrafficPercent(): void
    {
        $exp = $this->makeExperiment(7, 30, []);
        $enrolled = 0;
        $n = 4000;
        for ($i = 0; $i < $n; $i++) {
            if ($this->assignment->isEnrolled("visitor-$i", $exp)) {
                $enrolled++;
            }
        }
        $this->assertEqualsWithDelta(0.30, $enrolled / $n, 0.05);
    }
}
