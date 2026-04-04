<?php

namespace justinholtweb\wink\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $experimentId
 * @property int $variantId
 * @property int|null $goalId
 * @property string $visitorId
 * @property string $eventType
 * @property string|null $url
 * @property string|null $referrer
 * @property string|null $userAgent
 * @property string|null $ipAddress
 * @property array|null $metadata
 * @property string $dateCreated
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%wink_events}}';
    }
}
