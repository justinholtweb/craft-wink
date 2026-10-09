<?php

namespace justinholtweb\wink\tests\integration;

use Craft;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\services\AssignmentService;

/**
 * Covers the parts of assignment that need a real request/response and
 * database — cookie handling and handle-based lookup.
 */
final class AssignmentIntegrationTest extends WinkTestCase
{
    private function assignment(): AssignmentService
    {
        return Plugin::getInstance()->assignment;
    }

    public function testGetVisitorIdGeneratesAUuidV4(): void
    {
        $visitorId = $this->assignment()->getVisitorId();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $visitorId,
            'Visitor IDs should be UUID v4',
        );
    }

    public function testGeneratedVisitorIdIsSetAsACookie(): void
    {
        $cookieName = Plugin::getInstance()->getSettings()->cookieName;

        $visitorId = $this->assignment()->getVisitorId();
        // Raw (unsigned) since 5.1.0, so the cache-safe runtime can read it.
        $cookie = Craft::$app->getResponse()->getRawCookies()->get($cookieName);

        $this->assertNotNull($cookie, 'A visitor cookie should have been queued on the response');
        $this->assertSame($visitorId, $cookie->value);
    }

    public function testExistingCookieIsReused(): void
    {
        $cookieName = Plugin::getInstance()->getSettings()->cookieName;

        $this->setRequestCookie($cookieName, 'existing-visitor-id');

        $this->assertSame('existing-visitor-id', $this->assignment()->getVisitorId());
    }

    public function testCookieValueWinkWouldNotMintIsReplaced(): void
    {
        $cookieName = Plugin::getInstance()->getSettings()->cookieName;

        $this->setRequestCookie($cookieName, '<script>');

        $this->assertNotSame('<script>', $this->assignment()->getVisitorId());
    }

    /**
     * A page can resolve several experiments in one request. Each call must
     * see the same visitor, otherwise assignments and impressions get recorded
     * against throwaway IDs that never reach the browser.
     */
    public function testVisitorIdIsStableWithinASingleRequest(): void
    {
        $first = $this->assignment()->getVisitorId();
        $second = $this->assignment()->getVisitorId();
        $third = $this->assignment()->getVisitorId();

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    /**
     * Not http-only since 5.1.0: a cache-safe page assigns in the browser and must read the ID the
     * server issued.
     */
    public function testCookieIsScriptReadableAndLaxSameSite(): void
    {
        $cookieName = Plugin::getInstance()->getSettings()->cookieName;

        $this->assignment()->getVisitorId();
        $cookie = Craft::$app->getResponse()->getRawCookies()->get($cookieName);

        $this->assertFalse($cookie->httpOnly);
        $this->assertSame(\yii\web\Cookie::SAME_SITE_LAX, $cookie->sameSite);
    }

    public function testGetAssignmentReturnsNullForUnknownHandle(): void
    {
        $this->assertNull($this->assignment()->getAssignment('no-such-experiment'));
    }

    public function testGetAssignmentReturnsNullForNonRunningExperiment(): void
    {
        $experiment = $this->createExperiment(['handle' => 'draft-assign']);
        $this->addVariants($experiment, [['handle' => 'control', 'isControl' => true]]);

        $this->assertNull($this->assignment()->getAssignment('draft-assign'));
    }

    public function testGetAssignmentReturnsAVariantForARunningExperiment(): void
    {
        $experiment = $this->createExperiment([
            'handle' => 'live-assign',
            'experimentStatus' => 'running',
        ]);
        $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'challenger'],
        ]);

        $variant = $this->assignment()->getAssignment('live-assign');

        $this->assertNotNull($variant);
        $this->assertContains($variant->handle, ['control', 'challenger']);
    }

    public function testAssignmentIsStableAcrossCallsForTheSameVisitor(): void
    {
        $experiment = $this->createExperiment([
            'handle' => 'stable-assign',
            'experimentStatus' => 'running',
        ]);
        $this->addVariants($experiment, [
            ['handle' => 'control', 'isControl' => true],
            ['handle' => 'challenger'],
        ]);

        $first = $this->assignment()->getAssignment('stable-assign');
        $second = $this->assignment()->getAssignment('stable-assign');

        $this->assertSame($first->id, $second->id);
    }

    public function testExperimentWithoutVariantsAssignsNothing(): void
    {
        $this->createExperiment(['handle' => 'variantless', 'experimentStatus' => 'running']);

        $this->assertNull($this->assignment()->getAssignment('variantless'));
    }
}
