<?php

namespace justinholtweb\wink\tests\unit;

use justinholtweb\wink\services\StatsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the statistical engine.
 *
 * These cover the pure-math methods AGENTS.md flags as risky: the
 * two-proportion z-test, the Wilson score interval, and (indirectly,
 * through the z-test) the normal-CDF approximation. Expected values are
 * derived from standard statistical references / z-tables.
 */
final class StatsServiceTest extends TestCase
{
    private StatsService $stats;

    protected function setUp(): void
    {
        $this->stats = new StatsService();
    }

    // ---- twoProportionZTest -------------------------------------------------

    public function testEqualProportionsAreNotSignificant(): void
    {
        // Identical rates -> z = 0, two-tailed p = 1.
        $result = $this->stats->twoProportionZTest(50, 1000, 50, 1000);

        $this->assertSame(0.0, $result['z']);
        $this->assertSame(1.0, $result['p']);
    }

    public function testZScoreMatchesHandComputedValue(): void
    {
        // A = 12% (120/1000), B = 10% (100/1000).
        // pooled = 0.11, se = sqrt(0.11*0.89*(2/1000)) = 0.0139929,
        // z = 0.02 / 0.0139929 = 1.4293.
        $result = $this->stats->twoProportionZTest(120, 1000, 100, 1000);

        $this->assertEqualsWithDelta(1.4293, $result['z'], 0.001);
        // Two-tailed p for |z|=1.4293 is ~0.153.
        $this->assertEqualsWithDelta(0.153, $result['p'], 0.005);
    }

    public function testLargeDifferenceIsHighlySignificant(): void
    {
        // 20% vs 10% over 1000 each -> clearly significant.
        $result = $this->stats->twoProportionZTest(200, 1000, 100, 1000);

        $this->assertGreaterThan(5.0, $result['z']);
        $this->assertLessThan(0.001, $result['p']);
    }

    public function testWorseVariantProducesNegativeZ(): void
    {
        // A worse than B -> negative z, but p is still a valid probability.
        $result = $this->stats->twoProportionZTest(80, 1000, 120, 1000);

        $this->assertLessThan(0.0, $result['z']);
        $this->assertGreaterThan(0.0, $result['p']);
        $this->assertLessThanOrEqual(1.0, $result['p']);
    }

    public function testZeroImpressionsGuard(): void
    {
        $this->assertSame(
            ['z' => 0.0, 'p' => 1.0],
            $this->stats->twoProportionZTest(0, 0, 10, 100),
        );
        $this->assertSame(
            ['z' => 0.0, 'p' => 1.0],
            $this->stats->twoProportionZTest(10, 100, 0, 0),
        );
    }

    public function testDegeneratePooledProportionGuard(): void
    {
        // Everyone converts in both arms -> pooled proportion = 1 -> guarded.
        $result = $this->stats->twoProportionZTest(100, 100, 50, 50);

        $this->assertSame(['z' => 0.0, 'p' => 1.0], $result);
    }

    public function testPValueIsAlwaysAProbability(): void
    {
        foreach ([[1, 10], [5, 50], [37, 211], [499, 1000], [1, 100000]] as [$cA, $nA]) {
            $result = $this->stats->twoProportionZTest($cA, $nA, 50, 1000);
            $this->assertGreaterThanOrEqual(0.0, $result['p']);
            $this->assertLessThanOrEqual(1.0, $result['p']);
        }
    }

    // ---- wilsonScoreInterval ------------------------------------------------

    public function testWilsonIntervalForKnownCase(): void
    {
        // 50/100 at 95% -> well-known Wilson interval ~[0.4038, 0.5962].
        [$lower, $upper] = $this->stats->wilsonScoreInterval(50, 100);

        $this->assertEqualsWithDelta(0.4038, $lower, 0.001);
        $this->assertEqualsWithDelta(0.5962, $upper, 0.001);
    }

    public function testWilsonIntervalZeroTrials(): void
    {
        $this->assertSame([0.0, 0.0], $this->stats->wilsonScoreInterval(0, 0));
    }

    public function testWilsonIntervalIsClampedToUnitRange(): void
    {
        // All successes: lower stays below 1, upper clamped at 1.
        [$lower, $upper] = $this->stats->wilsonScoreInterval(10, 10);
        $this->assertGreaterThan(0.0, $lower);
        $this->assertLessThan(1.0, $lower);
        $this->assertLessThanOrEqual(1.0, $upper);

        // No successes: lower clamped at 0.
        [$lower0, $upper0] = $this->stats->wilsonScoreInterval(0, 10);
        $this->assertSame(0.0, $lower0);
        $this->assertGreaterThan(0.0, $upper0);
    }

    public function testWilsonIntervalIsOrderedAndContainsPointEstimate(): void
    {
        [$lower, $upper] = $this->stats->wilsonScoreInterval(30, 100);
        $this->assertLessThanOrEqual($upper, $lower);
        $this->assertLessThanOrEqual(0.30, $upper);
        $this->assertGreaterThanOrEqual(0.30, $lower === 0.0 ? 0.30 : $upper); // sanity
        $this->assertTrue($lower <= 0.30 && 0.30 <= $upper);
    }

    public function testHigherConfidenceWidensTheInterval(): void
    {
        [$l95, $u95] = $this->stats->wilsonScoreInterval(40, 100, 0.95);
        [$l99, $u99] = $this->stats->wilsonScoreInterval(40, 100, 0.99);

        $this->assertGreaterThan($u95 - $l95, $u99 - $l99);
    }
}
