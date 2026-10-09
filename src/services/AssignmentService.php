<?php

namespace justinholtweb\wink\services;

use Craft;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use yii\base\Component;

class AssignmentService extends Component
{
    /**
     * The visitor ID resolved for the current request.
     */
    private ?string $_visitorId = null;

    /**
     * What a visitor ID may look like. Wink mints UUIDs on the server and in the browser; anything
     * else in the cookie is ignored and replaced, because from 5.1.0 the cookie is not signed.
     */
    public const VISITOR_ID_PATTERN = '/^[A-Za-z0-9\-]{8,64}$/';

    /**
     * Get or create a visitor ID from cookies.
     *
     * The result is memoized for the request: a newly generated ID only exists
     * on the *response* cookie, so re-reading the request cookie would mint a
     * fresh ID on every call. That would scatter a single visitor's
     * assignments and impressions across several throwaway IDs whenever a page
     * resolves more than one experiment.
     *
     * Since 5.1.0 the cookie is a plain value the browser can read, so a page delivered cache-safe
     * (see {@see DeliveryService}) assigns the visitor the browser has, with the same hash. A signed
     * cookie from an earlier version is still honoured, and rewritten in the new form.
     */
    public function getVisitorId(): string
    {
        if ($this->_visitorId !== null) {
            return $this->_visitorId;
        }

        $cookieName = Plugin::getInstance()->getSettings()->cookieName;
        $request = Craft::$app->getRequest();
        $visitorId = null;

        if ($request instanceof \craft\web\Request) {
            $raw = $request->getRawCookies()->getValue($cookieName);
            if (is_string($raw) && self::isValidVisitorId($raw)) {
                $visitorId = $raw;
            } else {
                $signed = $request->getCookies()->getValue($cookieName);
                if (is_string($signed) && self::isValidVisitorId($signed)) {
                    $visitorId = $signed;
                    // Only a validated request has signed cookies to move off.
                    if ($request->enableCookieValidation) {
                        $this->setVisitorCookie($visitorId);
                    }
                }
            }
        }

        if ($visitorId === null) {
            $visitorId = $this->generateVisitorId();
            $this->setVisitorCookie($visitorId);
        }

        return $this->_visitorId = $visitorId;
    }

    public static function isValidVisitorId(string $visitorId): bool
    {
        return (bool)preg_match(self::VISITOR_ID_PATTERN, $visitorId);
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

    /**
     * The visitor cookie is written raw — unsigned, and readable by script — so the cache-safe
     * runtime can read the ID the server issued and write one the server will accept. It is a
     * random token that only groups tracking events; it grants nothing.
     */
    private function setVisitorCookie(string $visitorId): void
    {
        $response = Craft::$app->getResponse();
        if (!$response instanceof \craft\web\Response) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();

        $response->getRawCookies()->add(new \yii\web\Cookie(Craft::cookieConfig([
            'name' => $settings->cookieName,
            'value' => $visitorId,
            'expire' => time() + ($settings->cookieDuration * 86400),
            'httpOnly' => false,
            'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
        ])));
    }
}
