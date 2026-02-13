<?php

namespace jholt\wink\enums;

enum ExperimentStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Running => 'Running',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'white',
            self::Running => 'green',
            self::Paused => 'orange',
            self::Completed => 'blue',
            self::Archived => 'grey',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Running, self::Archived]),
            self::Running => in_array($target, [self::Paused, self::Completed]),
            self::Paused => in_array($target, [self::Running, self::Completed, self::Archived]),
            self::Completed => in_array($target, [self::Archived]),
            self::Archived => false,
        };
    }
}
