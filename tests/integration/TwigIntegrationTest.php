<?php

namespace justinholtweb\wink\tests\integration;

use Craft;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Settings;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\services\AssignmentService;

/**
 * Renders templates through Craft's view so the {% experiment %} tag and the
 * Twig functions are exercised end to end, including impression recording.
 */
final class TwigIntegrationTest extends WinkTestCase
{
    private function render(string $template, array $variables = []): string
    {
        return Craft::$app->getView()->renderString($template, $variables);
    }

    private function runningExperiment(string $handle = 'twig-test'): object
    {
        $experiment = $this->createExperiment([
            'handle' => $handle,
            'experimentStatus' => 'running',
        ]);
        $this->addVariants($experiment, [
            ['handle' => 'control', 'title' => 'Control', 'content' => 'Welcome', 'isControl' => true],
            ['handle' => 'challenger', 'title' => 'Challenger', 'content' => 'Discover'],
        ]);

        return $experiment;
    }

    // ---- {% experiment %} tag ----------------------------------------------

    public function testExperimentTagRendersOneVariantWrappedInATrackingDiv(): void
    {
        $this->runningExperiment();

        $html = $this->render(<<<'TWIG'
            {% experiment 'twig-test' %}
              {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
              {% variant 'challenger' %}<h1>Discover</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringContainsString('data-wink-experiment="twig-test"', $html);
        $this->assertMatchesRegularExpression('/data-wink-variant="(control|challenger)"/', $html);

        // Exactly one of the two bodies should be present.
        $rendered = (int)str_contains($html, 'Welcome') + (int)str_contains($html, 'Discover');
        $this->assertSame(1, $rendered, 'Exactly one variant body should render');
    }

    public function testExperimentTagRecordsAnImpression(): void
    {
        $experiment = $this->runningExperiment();

        $this->render(<<<'TWIG'
            {% experiment 'twig-test' %}
              {% variant 'control' %}A{% endvariant %}
              {% variant 'challenger' %}B{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertSame(1, Plugin::getInstance()->tracking->getImpressionCount($experiment->id));
    }

    /**
     * Regression: two experiments on one page must be attributed to the same
     * visitor. Previously each tag minted its own visitor ID, so a first-time
     * visitor was bucketed inconsistently and impressions were recorded
     * against IDs that never reached the browser.
     */
    public function testTwoExperimentsOnOnePageShareOneVisitor(): void
    {
        $first = $this->runningExperiment('page-exp-a');
        $second = $this->runningExperiment('page-exp-b');

        $this->render(<<<'TWIG'
            {% experiment 'page-exp-a' %}
              {% variant 'control' %}A1{% endvariant %}
              {% variant 'challenger' %}A2{% endvariant %}
            {% endexperiment %}
            {% experiment 'page-exp-b' %}
              {% variant 'control' %}B1{% endvariant %}
              {% variant 'challenger' %}B2{% endvariant %}
            {% endexperiment %}
            TWIG);

        $tracking = Plugin::getInstance()->tracking;

        $this->assertSame(1, $tracking->getImpressionCount($first->id));
        $this->assertSame(1, $tracking->getImpressionCount($second->id));

        $visitorIds = \justinholtweb\wink\records\EventRecord::find()
            ->select('visitorId')
            ->distinct()
            ->column();

        $this->assertCount(1, $visitorIds, 'Both experiments should record the same visitor');
    }

    public function testExperimentTagRendersNothingForAnUnknownExperiment(): void
    {
        $html = $this->render(<<<'TWIG'
            {% experiment 'does-not-exist' %}
              {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringNotContainsString('Welcome', $html);
        $this->assertStringNotContainsString('data-wink-experiment', $html);
    }

    public function testExperimentTagRendersNothingForANonRunningExperiment(): void
    {
        $experiment = $this->createExperiment(['handle' => 'paused-twig', 'experimentStatus' => 'paused']);
        $this->addVariants($experiment, [['handle' => 'control', 'content' => 'Hi', 'isControl' => true]]);

        $html = $this->render(<<<'TWIG'
            {% experiment 'paused-twig' %}
              {% variant 'control' %}<h1>Hi</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringNotContainsString('Hi', $html);
    }

    public function testUnmatchedVariantBlockRendersOnlyTheWrapper(): void
    {
        $this->runningExperiment('partial-blocks');

        // Only one of the two assigned handles has a body defined.
        $html = $this->render(<<<'TWIG'
            {% experiment 'partial-blocks' %}
              {% variant 'nonexistent-handle' %}<h1>Never</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringContainsString('data-wink-experiment="partial-blocks"', $html);
        $this->assertStringNotContainsString('Never', $html);
    }

    // ---- dynamic handles and winkRender() (#7) ------------------------------

    public function testExperimentTagTakesAHandleFromAVariable(): void
    {
        $this->runningExperiment('from-field');

        $html = $this->render(<<<'TWIG'
            {% experiment block.experimentHandle %}
              {% variant 'control' %}Welcome{% endvariant %}
              {% variant 'challenger' %}Discover{% endvariant %}
            {% endexperiment %}
            TWIG, ['block' => ['experimentHandle' => 'from-field']]);

        $this->assertStringContainsString('data-wink-experiment="from-field"', $html);
        $this->assertSame(1, (int)str_contains($html, 'Welcome') + (int)str_contains($html, 'Discover'));
    }

    public function testExperimentTagWithAnEmptyHandleRendersNothing(): void
    {
        $this->runningExperiment();

        $html = $this->render(<<<'TWIG'
            {% experiment block.experimentHandle %}{% variant 'control' %}Welcome{% endvariant %}{% endexperiment %}
            TWIG, ['block' => ['experimentHandle' => null]]);

        $this->assertSame('', trim($html));
    }

    /**
     * An experiment inside another's variant, cache-safe: each block must carry its own
     * experiment, where shared compiled variables made the outer block render as the inner one.
     */
    public function testNestedCacheSafeExperimentsKeepTheirOwnExperiment(): void
    {
        $this->runningExperiment('outer');
        $this->runningExperiment('inner');
        $this->withSettings(['deliveryMode' => Settings::DELIVERY_CACHE_SAFE]);

        $html = $this->render(<<<'TWIG'
            {% experiment 'outer' %}
              {% variant 'control' %}{% experiment 'inner' %}{% variant 'control' %}In-A{% endvariant %}{% variant 'challenger' %}In-B{% endvariant %}{% endexperiment %}{% endvariant %}
              {% variant 'challenger' %}Out-B{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertSame(1, substr_count($html, 'data-wink-experiment="outer"'));
        $this->assertSame(1, substr_count($html, 'data-wink-experiment="inner"'));
        $this->assertLessThan(strpos($html, 'data-wink-experiment="inner"'), strpos($html, 'data-wink-experiment="outer"'));
        foreach (['In-A', 'In-B', 'Out-B'] as $body) {
            $this->assertStringContainsString($body, $html);
        }
    }

    public function testWinkRenderServerSideShowsOneBodyAndRecordsAnImpression(): void
    {
        $experiment = $this->runningExperiment();
        $this->withSettings(['deliveryMode' => Settings::DELIVERY_SERVER]);

        $html = $this->render(<<<'TWIG'
            {% set a %}<p>Welcome</p>{% endset %}
            {{ winkRender(handle, { control: a, challenger: '<p>Discover</p>' }) }}
            TWIG, ['handle' => 'twig-test']);

        $this->assertMatchesRegularExpression('~<div data-wink-experiment="twig-test" data-wink-variant="(control|challenger)"><p>(Welcome|Discover)</p></div>~', $html);
        $this->assertSame(1, (int)str_contains($html, 'Welcome') + (int)str_contains($html, 'Discover'));
        $this->assertSame(1, Plugin::getInstance()->tracking->getImpressionCount($experiment->id));
    }

    public function testWinkRenderCacheSafeShowsEveryBodyAndRecordsNothing(): void
    {
        $experiment = $this->runningExperiment();
        $this->withSettings(['deliveryMode' => Settings::DELIVERY_CACHE_SAFE]);

        $html = $this->render("{{ winkRender('twig-test', { control: '<p>Welcome</p>', challenger: '<p>Discover</p>', unknown: 'Never' }) }}");

        $this->assertStringContainsString('data-wink-delivery="cache-safe"', $html);
        $this->assertStringContainsString('<div data-wink-option="control" data-wink-fallback><p>Welcome</p></div>', $html);
        $this->assertStringContainsString('<div data-wink-option="challenger"><p>Discover</p></div>', $html);
        $this->assertStringNotContainsString('Never', $html);
        $this->assertSame(0, Plugin::getInstance()->tracking->getImpressionCount($experiment->id));
    }

    public function testWinkRenderMatchesTheTagWhenCacheSafe(): void
    {
        $this->runningExperiment();
        $this->withSettings(['deliveryMode' => Settings::DELIVERY_CACHE_SAFE]);

        $tag = $this->render("{% experiment 'twig-test' %}{% variant 'control' %}<p>Welcome</p>{% endvariant %}{% variant 'challenger' %}<p>Discover</p>{% endvariant %}{% endexperiment %}");
        $function = $this->render("{{ winkRender('twig-test', { control: '<p>Welcome</p>', challenger: '<p>Discover</p>' }) }}");

        $this->assertSame($tag, $function);
    }

    public function testWinkRenderRendersNothingWithoutARunningExperiment(): void
    {
        $this->assertSame('', trim($this->render("{{ winkRender('nope', { control: 'Welcome' }) }}")));
        $this->assertSame('', trim($this->render('{{ winkRender(null, { control: "Welcome" }) }}')));
    }

    // ---- winkVariant() ------------------------------------------------------

    public function testWinkVariantReturnsAssignedContent(): void
    {
        $this->runningExperiment();

        $output = trim($this->render("{{ winkVariant('twig-test') }}"));

        $this->assertContains($output, ['Welcome', 'Discover']);
    }

    public function testWinkVariantReturnsEmptyStringForUnknownExperiment(): void
    {
        $this->assertSame('', trim($this->render("{{ winkVariant('nope') }}")));
    }

    // ---- winkExperiment() ---------------------------------------------------

    public function testWinkExperimentExposesAssignmentDetails(): void
    {
        $this->runningExperiment();

        $output = $this->render(<<<'TWIG'
            {% set t = winkExperiment('twig-test') %}{{ t.handle }}|{{ t.variantHandle }}|{{ t.content }}
            TWIG);

        [$handle, $variantHandle, $content] = explode('|', trim($output));

        $this->assertSame('twig-test', $handle);
        $this->assertContains($variantHandle, ['control', 'challenger']);
        $this->assertContains($content, ['Welcome', 'Discover']);
    }

    public function testWinkExperimentReturnsNullForUnknownExperiment(): void
    {
        $output = $this->render("{{ winkExperiment('nope') is null ? 'null' : 'not-null' }}");

        $this->assertSame('null', trim($output));
    }

    public function testWinkExperimentRecordsAnImpression(): void
    {
        $experiment = $this->runningExperiment();

        $this->render("{% set t = winkExperiment('twig-test') %}");

        $this->assertSame(1, Plugin::getInstance()->tracking->getImpressionCount($experiment->id));
    }

    // ---- winkTrackingScript() ----------------------------------------------

    public function testTrackingScriptEmitsConfig(): void
    {
        $output = $this->render('{{ winkTrackingScript() }}');

        $this->assertStringContainsString('window._winkConfig', $output);
        $this->assertStringContainsString('"endpoint":"\/wink\/track"', $output);
    }

    public function testTrackingScriptIsEmptyWhenTrackingIsDisabled(): void
    {
        $this->withSettings(['enableTracking' => false]);

        $this->assertSame('', trim($this->render('{{ winkTrackingScript() }}')));
    }

    /**
     * Regression (#2): only click goals reached the tracker, so page view and form submit goals
     * never converted. Every goal the tracker can detect goes in; custom events don't.
     */
    public function testTrackingScriptIncludesEveryDetectableGoal(): void
    {
        $experiment = $this->createExperiment(['handle' => 'auto-goals', 'experimentStatus' => 'running']);
        $this->addGoals($experiment, [
            ['handle' => 'cta', 'goalType' => GoalType::Click, 'goalTarget' => '.cta-button'],
            ['handle' => 'view', 'goalType' => GoalType::Pageview, 'goalTarget' => '/thanks*'],
            ['handle' => 'signup', 'goalType' => GoalType::FormSubmit, 'goalTarget' => '#signup'],
            ['handle' => 'custom', 'goalType' => GoalType::CustomEvent, 'goalTarget' => 'purchase'],
        ]);
        $draft = $this->createExperiment(['handle' => 'draft-goals']);
        $this->addGoals($draft, [['handle' => 'draft-view', 'goalType' => GoalType::Pageview, 'goalTarget' => '/draft']]);

        $config = $this->trackerConfig();
        $goals = array_column($config['goals'], null, 'handle');

        $this->assertSame(['cta', 'view', 'signup'], array_keys($goals));
        $this->assertSame(['type' => 'pageview', 'handle' => 'view', 'target' => '/thanks*', 'experiment' => 'auto-goals'], $goals['view']);
        $this->assertSame('form_submit', $goals['signup']['type']);
        $this->assertSame('#signup', $goals['signup']['target']);
    }

    public function testTrackingScriptCarriesTheConsentSettings(): void
    {
        $this->assertFalse($this->trackerConfig()['requireConsent']);

        $this->withSettings(['requireConsent' => true, 'cookieName' => '_my_vid']);
        $config = $this->trackerConfig();

        $this->assertTrue($config['requireConsent']);
        $this->assertSame(AssignmentService::CONSENT_COOKIE, $config['cookies']['consent']);
        $this->assertSame('_my_vid', $config['cookies']['visitor']);
    }

    private function trackerConfig(): array
    {
        $output = $this->render('{{ winkTrackingScript() }}');
        $this->assertMatchesRegularExpression('~^<script>window\._winkConfig = (.*);</script>$~s', trim($output));
        preg_match('~window\._winkConfig = (.*);</script>~s', $output, $m);

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testAClickGoalSelectorCannotCloseTheScriptTag(): void
    {
        $experiment = $this->createExperiment(['handle' => 'click-escape', 'experimentStatus' => 'running']);
        $this->addGoals($experiment, [
            ['handle' => 'cta', 'goalType' => \justinholtweb\wink\enums\GoalType::Click, 'goalTarget' => '.a</script><script>alert(1)</script>'],
        ]);

        $output = $this->render('{{ winkTrackingScript() }}');

        // One closing tag — the script's own.
        $this->assertSame(1, substr_count(strtolower($output), '</script'));
        $this->assertStringNotContainsString('<script>alert', $output);
    }

    // ---- consent (#6) -------------------------------------------------------

    public function testWithoutConsentTheTagShowsTheControlAndRecordsNothing(): void
    {
        $experiment = $this->runningExperiment();
        $this->withSettings(['requireConsent' => true]);
        $this->setClientIp('203.0.113.7');

        $html = $this->render(<<<'TWIG'
            {% experiment 'twig-test' %}
              {% variant 'control' %}Welcome{% endvariant %}
              {% variant 'challenger' %}Discover{% endvariant %}
            {% endexperiment %}
            {{ winkVariant('twig-test') }}|{{ craft.wink.variant('twig-test') }}|{{ winkExperiment('twig-test').variantHandle }}
            TWIG);

        $this->assertStringContainsString('data-wink-variant="control"', $html);
        $this->assertStringNotContainsString('Discover', $html);
        $this->assertStringContainsString('Welcome|control|control', $html);
        $this->assertSame(0, Plugin::getInstance()->tracking->getImpressionCount($experiment->id));
        $this->assertFalse(
            Craft::$app->getResponse()->getRawCookies()->has(Plugin::getInstance()->getSettings()->cookieName),
            'No visitor cookie may be set before consent',
        );
    }

    public function testWithConsentTheTagAssignsAndRecords(): void
    {
        $experiment = $this->runningExperiment();
        $this->withSettings(['requireConsent' => true]);
        $this->setClientIp('203.0.113.7');
        $this->setRawRequestCookies([AssignmentService::CONSENT_COOKIE => '1']);
        Craft::$app->getRequest()->setUrl('/landing'); // a web request records the page URL

        $this->assertTrue(Plugin::getInstance()->assignment->hasConsent());

        $this->render(<<<'TWIG'
            {% experiment 'twig-test' %}
              {% variant 'control' %}A{% endvariant %}
              {% variant 'challenger' %}B{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertSame(1, Plugin::getInstance()->tracking->getImpressionCount($experiment->id));
    }

    public function testConsentIsOnlyAskedForWhenTheSettingIsOn(): void
    {
        $this->setClientIp('203.0.113.7');
        $this->assertTrue(Plugin::getInstance()->assignment->hasConsent());

        $this->withSettings(['requireConsent' => true]);
        $this->assertFalse(Plugin::getInstance()->assignment->hasConsent());

        $this->setRawRequestCookies([AssignmentService::CONSENT_COOKIE => '0']);
        $this->assertFalse(Plugin::getInstance()->assignment->hasConsent());
    }

    public function testCacheSafeConfigNamesTheConsentCookieOnlyWhenRequired(): void
    {
        $experiment = $this->runningExperiment();

        $this->assertFalse(Plugin::getInstance()->delivery->getClientConfig($experiment)['consent']);

        $this->withSettings(['requireConsent' => true]);
        $this->assertSame(AssignmentService::CONSENT_COOKIE, Plugin::getInstance()->delivery->getClientConfig($experiment)['consent']);
    }

    // ---- craft.wink variable ------------------------------------------------

    public function testCraftWinkVariantReturnsTheAssignedHandle(): void
    {
        $this->runningExperiment();

        $output = trim($this->render("{{ craft.wink.variant('twig-test') }}"));

        $this->assertContains($output, ['control', 'challenger']);
    }

    public function testCraftWinkExperimentQueryIsUsable(): void
    {
        $this->runningExperiment('query-me');

        $output = trim($this->render("{{ craft.wink.experiments.experimentStatus('running').count() }}"));

        $this->assertSame('1', $output);
    }

    public function testCraftWinkIsTrackingEnabledReflectsSettings(): void
    {
        $this->assertSame('1', trim($this->render('{{ craft.wink.isTrackingEnabled() ? 1 : 0 }}')));

        $this->withSettings(['enableTracking' => false]);
        $this->assertSame('0', trim($this->render('{{ craft.wink.isTrackingEnabled() ? 1 : 0 }}')));
    }

    public function testCraftWinkExperimentReturnsRunningExperimentOnly(): void
    {
        $this->createExperiment(['handle' => 'draft-var']);

        $output = trim($this->render("{{ craft.wink.experiment('draft-var') is null ? 'null' : 'found' }}"));

        $this->assertSame('null', $output);
    }
}
