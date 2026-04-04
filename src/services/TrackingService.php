<?php

namespace justinholtweb\wink\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\records\EventRecord;
use yii\base\Component;

class TrackingService extends Component
{
    /**
     * Record an impression event.
     */
    public function recordImpression(int $experimentId, int $variantId, string $visitorId, array $context = []): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableTracking) {
            return false;
        }

        // Deduplicate: one impression per visitor per experiment per day
        $today = DateTimeHelper::currentUTCDateTime()->format('Y-m-d');
        $exists = (new Query())
            ->from('{{%wink_events}}')
            ->where([
                'experimentId' => $experimentId,
                'visitorId' => $visitorId,
                'eventType' => 'impression',
            ])
            ->andWhere(['>=', 'dateCreated', $today . ' 00:00:00'])
            ->andWhere(['<=', 'dateCreated', $today . ' 23:59:59'])
            ->exists();

        if ($exists) {
            return false;
        }

        return $this->recordEvent($experimentId, $variantId, null, $visitorId, 'impression', $context);
    }

    /**
     * Record a conversion event.
     */
    public function recordConversion(int $experimentId, int $variantId, ?int $goalId, string $visitorId, array $context = []): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableTracking) {
            return false;
        }

        return $this->recordEvent($experimentId, $variantId, $goalId, $visitorId, 'conversion', $context);
    }

    /**
     * Get impression count for an experiment/variant.
     */
    public function getImpressionCount(int $experimentId, ?int $variantId = null): int
    {
        $query = (new Query())
            ->from('{{%wink_events}}')
            ->where([
                'experimentId' => $experimentId,
                'eventType' => 'impression',
            ]);

        if ($variantId !== null) {
            $query->andWhere(['variantId' => $variantId]);
        }

        return (int)$query->count();
    }

    /**
     * Get conversion count for an experiment/variant.
     */
    public function getConversionCount(int $experimentId, ?int $variantId = null, ?int $goalId = null): int
    {
        $query = (new Query())
            ->from('{{%wink_events}}')
            ->where([
                'experimentId' => $experimentId,
                'eventType' => 'conversion',
            ]);

        if ($variantId !== null) {
            $query->andWhere(['variantId' => $variantId]);
        }

        if ($goalId !== null) {
            $query->andWhere(['goalId' => $goalId]);
        }

        return (int)$query->count();
    }

    /**
     * Get unique visitor count for an experiment/variant.
     */
    public function getUniqueVisitorCount(int $experimentId, ?int $variantId = null): int
    {
        $query = (new Query())
            ->from('{{%wink_events}}')
            ->where([
                'experimentId' => $experimentId,
                'eventType' => 'impression',
            ]);

        if ($variantId !== null) {
            $query->andWhere(['variantId' => $variantId]);
        }

        return (int)$query->select('visitorId')->distinct()->count('visitorId');
    }

    /**
     * Purge events older than retention period.
     */
    public function purgeOldEvents(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $cutoff = DateTimeHelper::currentUTCDateTime()
            ->modify("-{$settings->retentionDays} days")
            ->format('Y-m-d H:i:s');

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%wink_events}}', ['<', 'dateCreated', $cutoff])
            ->execute();
    }

    private function recordEvent(
        int $experimentId,
        int $variantId,
        ?int $goalId,
        string $visitorId,
        string $eventType,
        array $context = [],
    ): bool {
        $settings = Plugin::getInstance()->getSettings();
        $request = Craft::$app->getRequest();

        $ipAddress = null;
        if (!$settings->anonymizeIp && !$request->getIsConsoleRequest()) {
            $ipAddress = $request->getUserIP();
        } elseif (!$request->getIsConsoleRequest()) {
            // Anonymize by zeroing last octet
            $ip = $request->getUserIP();
            if ($ip && str_contains($ip, '.')) {
                $parts = explode('.', $ip);
                $parts[3] = '0';
                $ipAddress = implode('.', $parts);
            }
        }

        $record = new EventRecord();
        $record->experimentId = $experimentId;
        $record->variantId = $variantId;
        $record->goalId = $goalId;
        $record->visitorId = $visitorId;
        $record->eventType = $eventType;
        $record->url = $context['url'] ?? ($request->getIsConsoleRequest() ? null : $request->getAbsoluteUrl());
        $record->referrer = $context['referrer'] ?? ($request->getIsConsoleRequest() ? null : $request->getReferrer());
        $record->userAgent = $context['userAgent'] ?? ($request->getIsConsoleRequest() ? null : $request->getUserAgent());
        $record->ipAddress = $ipAddress;
        $record->metadata = !empty($context['metadata']) ? $context['metadata'] : null;
        $record->dateCreated = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

        return $record->save(false);
    }
}
