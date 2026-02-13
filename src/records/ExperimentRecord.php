<?php

namespace jholt\wink\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property string|null $description
 * @property string $experimentStatus
 * @property int $trafficPercent
 * @property string|null $startDate
 * @property string|null $endDate
 * @property int|null $winnerVariantId
 */
class ExperimentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%wink_experiments}}';
    }
}
