<?php

namespace jholt\wink\services;

use Craft;
use jholt\wink\elements\Experiment;
use jholt\wink\models\Variant;
use jholt\wink\Plugin;
use yii\base\Component;

class AssignmentService extends Component
{
    /**
     * Get or create a visitor ID from cookies.
     */
    public function getVisitorId(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $cookieName = $settings->cookieName;

        $visitorId = Craft::$app->getRequest()->getCookies()->getValue($cookieName);

        if (!$visitorId) {
            $visitorId = $this->generateVisitorId();
            $this->setVisitorCookie($visitorId);
        }

        return $visitorId;
    }

    /**
     * Check if a visitor is enrolled in an experiment based on traffic allocation.
     */
    public function isEnrolled(string $visitorId, Experiment $experiment): bool
    {
        $hash = crc32($visitorId . $experiment->id . 'enrollment');
        $bucket = abs($hash) % 100;
        return $bucket < $experiment->trafficPercent;
    }

    /**
     * Deterministically assign a visitor to a variant.
     */
    public function assignVariant(string $visitorId, Experiment $experiment): ?Variant
    {
        $variants = $experiment->getVariants();
        if (empty($variants)) {
            return null;
        }

        // Check enrollment
        if (!$this->isEnrolled($visitorId, $experiment)) {
            // Not enrolled - return control variant
            foreach ($variants as $variant) {
                if ($variant->isControl) {
                    return $variant;
                }
            }
            return $variants[0];
        }

        // Deterministic assignment based on weights
        $totalWeight = array_sum(array_map(fn(Variant $v) => $v->weight, $variants));
        if ($totalWeight <= 0) {
            return $variants[0];
        }

        $hash = crc32($visitorId . $experiment->id);
        $bucket = abs($hash) % $totalWeight;

        $cumulative = 0;
        foreach ($variants as $variant) {
            $cumulative += $variant->weight;
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        return $variants[0];
    }

    /**
     * Get assignment for an experiment by handle (convenience method).
     * Returns the assigned variant or null if experiment not found/not running.
     */
    public function getAssignment(string $experimentHandle): ?Variant
    {
        $experiment = Plugin::getInstance()->experiments->getRunningExperiment($experimentHandle);
        if (!$experiment) {
            return null;
        }

        $visitorId = $this->getVisitorId();
        return $this->assignVariant($visitorId, $experiment);
    }

    private function generateVisitorId(): string
    {
        // UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function setVisitorCookie(string $visitorId): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $cookie = new \yii\web\Cookie([
            'name' => $settings->cookieName,
            'value' => $visitorId,
            'expire' => time() + ($settings->cookieDuration * 86400),
            'httpOnly' => true,
            'secure' => Craft::$app->getRequest()->getIsSecureConnection(),
            'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
        ]);

        Craft::$app->getResponse()->getCookies()->add($cookie);
    }
}
