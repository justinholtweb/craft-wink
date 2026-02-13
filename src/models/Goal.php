<?php

namespace jholt\wink\models;

use craft\base\Model;
use jholt\wink\enums\GoalType;

class Goal extends Model
{
    public ?int $id = null;
    public ?int $experimentId = null;
    public string $name = '';
    public string $handle = '';
    public GoalType $goalType = GoalType::Pageview;
    public ?string $goalTarget = null;
    public bool $isPrimary = false;

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'experimentId'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['goalTarget'], 'string', 'max' => 500],
        ];
    }
}
