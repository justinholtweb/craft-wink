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

        // A conversion belongs to someone who saw the experiment. Before 5.0.6 one could be
        // posted for a visitor with no impression at all. (Repeat conversions by the same
        // visitor are still recorded — a second purchase is real — but the report's rates count
        // each converted visitor once; see StatsService.)
        $sawIt = (new Query())->from('{{%wink_events}}')->where([
            'experimentId' => $experimentId,
            'visitorId' => $visitorId,
            'eventType' => 'impression',
        ])->exists();

        if (!$sawIt) {
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
     * How many distinct visitors converted, for an experiment/variant and optionally one goal.
     *
     * This, not the event count, is what a conversion *rate* is made of: conversions are
     * repeatable, so counting events lets a returning buyer — or one forged visitor posting the
     * same conversion again and again — push a variant past 100% and win.
     */
    public function getConvertedVisitorCount(int $experimentId, ?int $variantId = null, ?int $goalId = null): int
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

        return (int)$query->select('visitorId')->distinct()->count('visitorId');
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

    /**
     * Whether this address may send another tracking event this minute.
     *
     * Craft trusts every host by default (`trustedHosts` is `['any']`), which makes
     * `X-Forwarded-For` whatever the client says — a new value per request and the budget never
     * runs out. So the forwarded address is believed only once a site has set `trustedHosts`.
     * IPv6 is charged by its /64.
     */
    public function withinBudget(): bool
    {
        $limit = Plugin::getInstance()->getSettings()->trackingBudgetPerMinute;
        $request = Craft::$app->getRequest();

        if ($limit <= 0 || $request->getIsConsoleRequest()) {
            return true;
        }

        $trusted = Craft::$app->getConfig()->getGeneral()->trustedHosts;
        $ip = (string)($trusted === ['any'] ? $request->getRemoteIP() : $request->getUserIP());
        if (str_contains($ip, ':') && ($packed = @inet_pton($ip)) !== false) {
            $ip = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        $key = sprintf('wink:budget:%s:%d', sha1($ip), intdiv(time(), 60));
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 2)) {
            return false;
        }

        try {
            $cache = Craft::$app->getCache();
            $count = (int)$cache->get($key);
            if ($count >= $limit) {
                return false;
            }
            $cache->set($key, $count + 1, 120);

            return true;
        } finally {
            $mutex->release($key);
        }
    }

    /**
     * The address stored with an event. With `anonymizeIp` on, IPv4 loses its last octet and IPv6
     * keeps only its /48 — before 5.0.6 IPv6 addresses were stored whole whatever the setting said.
     */
    public static function storedAddress(?string $ip, bool $anonymize): ?string
    {
        if ($ip === null || $ip === '' || !$anonymize) {
            return $ip ?: null;
        }

        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);

            return $packed !== false ? inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) : null;
        }

        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }
        $parts[3] = '0';

        return implode('.', $parts);
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

        $ipAddress = $request->getIsConsoleRequest()
            ? null
            : self::storedAddress($request->getUserIP(), $settings->anonymizeIp);

        // Client-supplied values, capped to the columns (500) and, for metadata, to a small JSON
        // document of scalars — before 5.0.6 an oversized value failed the insert outright, and
        // metadata could be any size.
        $cap = static fn(?string $value): ?string => $value !== null ? mb_substr($value, 0, 500) : null;
        $metadata = $context['metadata'] ?? null;
        if ($metadata !== null) {
            $metadata = is_array($metadata) || is_scalar($metadata) ? $metadata : null;
            $encoded = $metadata !== null ? json_encode($metadata) : false;
            $metadata = $encoded !== false && strlen($encoded) <= 2048 ? $metadata : null;
        }

        $record = new EventRecord();
        $record->experimentId = $experimentId;
        $record->variantId = $variantId;
        $record->goalId = $goalId;
        $record->visitorId = $visitorId;
        $record->eventType = $eventType;
        $record->url = $cap($context['url'] ?? ($request->getIsConsoleRequest() ? null : $request->getAbsoluteUrl()));
        $record->referrer = $cap($context['referrer'] ?? ($request->getIsConsoleRequest() ? null : $request->getReferrer()));
        $record->userAgent = $cap($context['userAgent'] ?? ($request->getIsConsoleRequest() ? null : $request->getUserAgent()));
        $record->ipAddress = $ipAddress;
        $record->metadata = !empty($metadata) ? $metadata : null;
        $record->dateCreated = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

        return $record->save(false);
    }
}
