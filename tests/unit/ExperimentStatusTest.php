<?php

namespace justinholtweb\wink\tests\unit;

use justinholtweb\wink\enums\ExperimentStatus;
use PHPUnit\Framework\TestCase;

/**
 * The status lifecycle is enforced entirely by the enum, so the full
 * transition matrix is asserted here rather than through the service.
 */
final class ExperimentStatusTest extends TestCase
{
    public function testAllCasesArePresent(): void
    {
        $values = array_map(fn(ExperimentStatus $s) => $s->value, ExperimentStatus::cases());

        $this->assertSame(
            ['draft', 'running', 'paused', 'completed', 'archived'],
            $values,
        );
    }

    public function testEveryCaseHasALabelAndColor(): void
    {
        foreach (ExperimentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label(), "{$status->value} needs a label");
            $this->assertNotSame('', $status->color(), "{$status->value} needs a color");
        }
    }

    /**
     * The complete transition matrix from the documented lifecycle.
     *
     * @return array<string, array{ExperimentStatus, ExperimentStatus, bool}>
     */
    public static function transitionProvider(): array
    {
        $allowed = [
            'draft' => ['running', 'archived'],
            'running' => ['paused', 'completed'],
            'paused' => ['running', 'completed', 'archived'],
            'completed' => ['archived'],
            'archived' => [],
        ];

        $cases = [];
        foreach (ExperimentStatus::cases() as $from) {
            foreach (ExperimentStatus::cases() as $to) {
                $expected = in_array($to->value, $allowed[$from->value], true);
                $cases["{$from->value} -> {$to->value}"] = [$from, $to, $expected];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider transitionProvider
     */
    public function testTransitionMatrix(ExperimentStatus $from, ExperimentStatus $to, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $from->canTransitionTo($to),
            sprintf('%s -> %s should be %s', $from->value, $to->value, $expected ? 'allowed' : 'rejected'),
        );
    }

    public function testArchivedIsTerminal(): void
    {
        foreach (ExperimentStatus::cases() as $target) {
            $this->assertFalse(
                ExperimentStatus::Archived->canTransitionTo($target),
                'Archived must be a terminal state',
            );
        }
    }

    public function testNoStatusCanTransitionToItself(): void
    {
        foreach (ExperimentStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} should not transition to itself",
            );
        }
    }

    public function testRunningCannotBeArchivedDirectly(): void
    {
        // Archiving a running experiment must go through pause or complete first,
        // otherwise live traffic would be stranded mid-test.
        $this->assertFalse(ExperimentStatus::Running->canTransitionTo(ExperimentStatus::Archived));
    }

    public function testDraftCannotSkipStraightToCompleted(): void
    {
        $this->assertFalse(ExperimentStatus::Draft->canTransitionTo(ExperimentStatus::Completed));
    }
}
