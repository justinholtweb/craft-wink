<?php

namespace jholt\wink\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $experimentId
 * @property string $handle
 * @property string $title
 * @property string|null $content
 * @property int $weight
 * @property int $sortOrder
 * @property bool $isControl
 */
class VariantRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%wink_variants}}';
    }
}
