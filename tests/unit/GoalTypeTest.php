<?php

namespace justinholtweb\wink\tests\unit;

use justinholtweb\wink\enums\GoalType;
use PHPUnit\Framework\TestCase;

final class GoalTypeTest extends TestCase
{
    public function testAllCasesArePresent(): void
    {
        $values = array_map(fn(GoalType $t) => $t->value, GoalType::cases());

        $this->assertSame(
            ['pageview', 'click', 'form_submit', 'custom_event'],
            $values,
        );
    }

    public function testEveryCaseHasCompleteUiMetadata(): void
    {
        foreach (GoalType::cases() as $type) {
            $this->assertNotSame('', $type->label(), "{$type->value} needs a label");
            $this->assertNotSame('', $type->targetLabel(), "{$type->value} needs a target label");
            $this->assertNotSame('', $type->targetPlaceholder(), "{$type->value} needs a placeholder");
        }
    }

    public function testFromStringRoundTrips(): void
    {
        foreach (GoalType::cases() as $type) {
            $this->assertSame($type, GoalType::from($type->value));
        }
    }

    public function testUnknownValueThrows(): void
    {
        $this->expectException(\ValueError::class);
        GoalType::from('not_a_goal_type');
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        $this->assertNull(GoalType::tryFrom('not_a_goal_type'));
    }

    /**
     * The frontend tracker keys click-goal wiring off this exact value,
     * so it must not drift.
     */
    public function testClickValueIsStable(): void
    {
        $this->assertSame('click', GoalType::Click->value);
    }
}
