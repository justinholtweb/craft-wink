<?php

namespace justinholtweb\wink\tests\integration;

use Craft;
use justinholtweb\wink\Plugin;

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

    public function testTrackingScriptIncludesActiveClickGoals(): void
    {
        $experiment = $this->createExperiment(['handle' => 'click-goals', 'experimentStatus' => 'running']);
        $this->addGoals($experiment, [
            ['handle' => 'cta', 'goalType' => \justinholtweb\wink\enums\GoalType::Click, 'goalTarget' => '.cta-button'],
            ['handle' => 'view', 'goalType' => \justinholtweb\wink\enums\GoalType::Pageview, 'goalTarget' => '/thanks'],
        ]);

        $output = $this->render('{{ winkTrackingScript() }}');

        $this->assertStringContainsString('.cta-button', $output);
        $this->assertStringNotContainsString('/thanks', $output, 'Only click goals belong in the click list');
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
