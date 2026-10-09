<?php

namespace justinholtweb\wink\tests\unit;

use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\services\AssignmentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the server's assignments for a fixed set of visitors and experiments to
 * `tests/js/fixtures/bucketing.json`, which `tests/js/bucketing.test.js` holds the browser
 * runtime (`wink-delivery.js`) to. Between them, a change to either side's hashing, enrollment or
 * weighting fails a test instead of quietly splitting server- and browser-assigned visitors.
 *
 * Regenerate the fixture (only when the algorithm is meant to change, which re-buckets every live
 * visitor) with `WINK_WRITE_FIXTURE=1 vendor/bin/phpunit --filter BucketingParityTest`.
 */
final class BucketingParityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../js/fixtures/bucketing.json';

    /**
     * Shapes the client config takes from DeliveryService::getClientConfig().
     *
     * @return array<int, array{id: int, traffic: int, variants: array<int, array{h: string, w: int, c: bool}>}>
     */
    private static function experiments(): array
    {
        $v = static fn(string $h, int $w, bool $c = false) => ['h' => $h, 'w' => $w, 'c' => $c];

        return [
            ['id' => 1, 'traffic' => 100, 'variants' => [$v('control', 50, true), $v('variant-a', 50)]],
            ['id' => 42, 'traffic' => 100, 'variants' => [$v('a', 34), $v('control', 33, true), $v('b', 33)]],
            ['id' => 7, 'traffic' => 30, 'variants' => [$v('control', 50, true), $v('challenger', 50)]],
            ['id' => 1234, 'traffic' => 100, 'variants' => [$v('control', 0, true), $v('all-in', 100)]],
            ['id' => 99, 'traffic' => 100, 'variants' => [$v('first', 0), $v('second', 0, true)]],
            ['id' => 5, 'traffic' => 60, 'variants' => [$v('x', 70), $v('y', 20), $v('z', 10)]],
            ['id' => 31337, 'traffic' => 1, 'variants' => [$v('control', 1, true), $v('rare', 99)]],
        ];
    }

    /**
     * @return string[]
     */
    private static function visitors(): array
    {
        $ids = ['existing-visitor-id', 'ABCDEFGH', str_repeat('f', 64), '00000000-0000-4000-8000-000000000000'];
        for ($i = 0; $i < 250; $i++) {
            $hex = md5("wink-fixture-$i");
            $ids[] = sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
        }

        return $ids;
    }

    private function experiment(array $config): Experiment
    {
        /** @var Experiment $experiment */
        $experiment = (new ReflectionClass(Experiment::class))->newInstanceWithoutConstructor();
        $experiment->id = $config['id'];
        $experiment->trafficPercent = $config['traffic'];

        $variants = [];
        foreach ($config['variants'] as $i => $spec) {
            /** @var Variant $variant */
            $variant = (new ReflectionClass(Variant::class))->newInstanceWithoutConstructor();
            $variant->id = $i + 1;
            $variant->handle = $spec['h'];
            $variant->weight = $spec['w'];
            $variant->isControl = $spec['c'];
            $variants[] = $variant;
        }
        $experiment->setVariants($variants);

        return $experiment;
    }

    /**
     * @return array{experiments: array, visitors: string[], assignments: array<string, array<string, string>>}
     */
    private function compute(): array
    {
        $service = new AssignmentService();
        $assignments = [];

        foreach (self::experiments() as $config) {
            $experiment = $this->experiment($config);
            foreach (self::visitors() as $visitorId) {
                $assignments[(string)$config['id']][$visitorId] = $service->assignVariant($visitorId, $experiment)?->handle;
            }
        }

        return ['experiments' => self::experiments(), 'visitors' => self::visitors(), 'assignments' => $assignments];
    }

    public function testServerAssignmentsMatchTheSharedFixture(): void
    {
        $computed = $this->compute();

        if (getenv('WINK_WRITE_FIXTURE')) {
            file_put_contents(self::FIXTURE, json_encode($computed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        $this->assertFileExists(self::FIXTURE);
        $this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($computed), true));
    }

    public function testFixtureExercisesEveryBranch(): void
    {
        $assignments = $this->compute()['assignments'];

        // Both arms of a 50/50 split, and all three of a three-way one.
        $this->assertEqualsCanonicalizing(['control', 'variant-a'], array_values(array_unique($assignments['1'])));
        $this->assertEqualsCanonicalizing(['a', 'b', 'control'], array_values(array_unique($assignments['42'])));
        // Zero weight never wins; all-zero falls back to the first variant.
        $this->assertSame(['all-in'], array_values(array_unique($assignments['1234'])));
        $this->assertSame(['first'], array_values(array_unique($assignments['99'])));
        // 1% traffic: nearly everyone is outside the experiment and sees the control.
        $counts = array_count_values($assignments['31337']);
        $this->assertGreaterThan(200, $counts['control']);
    }
}
