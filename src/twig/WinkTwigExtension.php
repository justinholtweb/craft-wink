<?php

namespace justinholtweb\wink\twig;

use Craft;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\services\AssignmentService;
use justinholtweb\wink\web\assets\tracking\TrackingAsset;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class WinkTwigExtension extends AbstractExtension
{
    public function getTokenParsers(): array
    {
        return [new WinkTokenParser()];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('winkVariant', [$this, 'winkVariant'], ['is_safe' => ['html']]),
            new TwigFunction('winkRender', [$this, 'winkRender'], ['is_safe' => ['html']]),
            new TwigFunction('winkExperiment', [$this, 'winkExperiment']),
            new TwigFunction('winkTrackingScript', [$this, 'winkTrackingScript'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Returns the assigned variant's content for an experiment.
     * Usage: {{ winkVariant('headline-test') }}
     */
    public function winkVariant(string $handle): string
    {
        // Cache-safe: every variant's content goes in the page and the browser picks, like the tag.
        $experiment = Plugin::getInstance()->experiments->getRunningExperiment($handle);
        if ($experiment && Plugin::getInstance()->delivery->isCacheSafe($experiment)) {
            $bodies = [];
            foreach ($experiment->getVariants() as $variant) {
                $bodies[$variant->handle] = $variant->content ?? '';
            }

            return Plugin::getInstance()->delivery->renderCacheSafe($experiment, $bodies);
        }

        $assignment = Plugin::getInstance()->assignment;
        $variant = $assignment->getAssignment($handle);

        if (!$variant) {
            return '';
        }

        return $variant->content ?? '';
    }

    /**
     * Renders an experiment block from variant bodies the template has already rendered, like the
     * {% experiment %} tag: cache-safe or server-side as the experiment is delivered, and nothing
     * when the experiment isn't running. The bodies are output as they are, unescaped.
     *
     * Usage:
     *   {% set a %}{% for b in block.variantA.all() %}…{% endfor %}{% endset %}
     *   {{ winkRender(block.experimentHandle, { control: a, 'variant-b': b }) }}
     *
     * @param array<string, mixed> $bodies Rendered HTML keyed by variant handle.
     */
    public function winkRender(?string $handle, array $bodies): string
    {
        $experiment = $handle ? Plugin::getInstance()->experiments->getRunningExperiment($handle) : null;
        if (!$experiment) {
            return '';
        }

        return Plugin::getInstance()->delivery->render(
            $experiment,
            array_map(fn($body) => (string)$body, $bodies),
        );
    }

    /**
     * Returns an object with experiment and variant info.
     * Usage: {% set test = winkExperiment('headline-test') %}
     *
     * The visitor's variant is handed to the template, so this is always server-side, whatever the
     * experiment's delivery: don't use it on a page a full-page cache serves.
     */
    public function winkExperiment(string $handle): ?object
    {
        $experiment = Plugin::getInstance()->experiments->getRunningExperiment($handle);
        if (!$experiment) {
            return null;
        }

        $assignment = Plugin::getInstance()->assignment;
        $visitorId = $assignment->hasConsent() ? $assignment->getVisitorId() : null;
        $variant = $visitorId !== null
            ? $assignment->assignVariant($visitorId, $experiment)
            : $assignment->getFallbackVariant($experiment);

        if (!$variant) {
            return null;
        }

        // Record impression (not for a visitor who hasn't consented, who sees the control)
        if ($visitorId !== null) {
            Plugin::getInstance()->tracking->recordImpression(
                $experiment->id,
                $variant->id,
                $visitorId,
            );
        }

        return (object)[
            'experiment' => $experiment,
            'variant' => $variant,
            'handle' => $experiment->handle,
            'variantHandle' => $variant->handle,
            'content' => $variant->content ?? '',
            'isControl' => $variant->isControl,
        ];
    }

    /**
     * Renders the tracking script tag with configuration.
     * Usage: {{ winkTrackingScript() }}
     */
    public function winkTrackingScript(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enableTracking) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(TrackingAsset::class);

        $config = [
            'endpoint' => '/wink/track',
            'batchInterval' => $settings->batchInterval,
            'respectDnt' => $settings->respectDnt,
            'enableGa4' => $settings->enableGa4,
            'enableGtm' => $settings->enableGtm,
            'gtmEventName' => $settings->gtmEventName,
            'goals' => $this->_getActiveGoals(),
            'requireConsent' => $settings->requireConsent,
            'cookies' => [
                'consent' => AssignmentService::CONSENT_COOKIE,
                'visitor' => $settings->cookieName,
                'days' => $settings->cookieDuration,
                'domain' => (string)(Craft::cookieConfig()['domain'] ?? ''),
            ],
        ];

        // Goal targets and the GTM event name are written in the control panel; hex-escape them so
        // a `</script>` in one can't end this tag early.
        return '<script>window._winkConfig = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    }

    /**
     * The goals the tracker detects on its own, for every running experiment: page views (a URL
     * pattern), clicks and form submits (a CSS selector). Before 5.2.0 only clicks were passed,
     * so page view and form submit goals never converted. Custom event goals are sent by the
     * site's own script, with `Wink.convert()`.
     */
    private function _getActiveGoals(): array
    {
        $goals = [];
        $experiments = Experiment::find()
            ->experimentStatus('running')
            ->all();

        foreach ($experiments as $experiment) {
            foreach ($experiment->getGoals() as $goal) {
                if ($goal->goalType !== GoalType::CustomEvent && $goal->goalTarget) {
                    $goals[] = [
                        'type' => $goal->goalType->value,
                        'handle' => $goal->handle,
                        'target' => $goal->goalTarget,
                        'experiment' => $experiment->handle,
                    ];
                }
            }
        }

        return $goals;
    }
}
