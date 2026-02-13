<?php

namespace jholt\wink\twig;

use Craft;
use jholt\wink\elements\Experiment;
use jholt\wink\Plugin;
use jholt\wink\web\assets\tracking\TrackingAsset;
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
        $assignment = Plugin::getInstance()->assignment;
        $variant = $assignment->getAssignment($handle);

        if (!$variant) {
            return '';
        }

        return $variant->content ?? '';
    }

    /**
     * Returns an object with experiment and variant info.
     * Usage: {% set test = winkExperiment('headline-test') %}
     */
    public function winkExperiment(string $handle): ?object
    {
        $experiment = Plugin::getInstance()->experiments->getRunningExperiment($handle);
        if (!$experiment) {
            return null;
        }

        $visitorId = Plugin::getInstance()->assignment->getVisitorId();
        $variant = Plugin::getInstance()->assignment->assignVariant($visitorId, $experiment);

        if (!$variant) {
            return null;
        }

        // Record impression
        Plugin::getInstance()->tracking->recordImpression(
            $experiment->id,
            $variant->id,
            $visitorId,
        );

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
            'clickGoals' => $this->_getActiveClickGoals(),
        ];

        return '<script>window._winkConfig = ' . json_encode($config) . ';</script>';
    }

    private function _getActiveClickGoals(): array
    {
        $goals = [];
        $experiments = Experiment::find()
            ->experimentStatus('running')
            ->all();

        foreach ($experiments as $experiment) {
            foreach ($experiment->getGoals() as $goal) {
                if ($goal->goalType->value === 'click' && $goal->goalTarget) {
                    $goals[] = [
                        'handle' => $goal->handle,
                        'selector' => $goal->goalTarget,
                        'experiment' => $experiment->handle,
                    ];
                }
            }
        }

        return $goals;
    }
}
