<?php

namespace jholt\wink\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $experimentId
 * @property string $name
 * @property string $handle
 * @property string $goalType
 * @property string|null $goalTarget
 * @property bool $isPrimary
 */
class GoalRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%wink_goals}}';
    }
}
