<?php

namespace justinholtweb\wink\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\wink\Plugin;
use yii\web\Response;

class TrackingController extends Controller
{
    // Allow anonymous access to tracking endpoints
    protected array|int|bool $allowAnonymous = ['track', 'pixel'];

    public function beforeAction($action): bool
    {
        if (in_array($action->id, ['track', 'pixel'])) {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    /**
     * POST endpoint for recording events (impressions/conversions).
     */
    public function actionTrack(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        // Respect DNT
        if ($settings->respectDnt && $request->getHeaders()->get('DNT') === '1') {
            return $this->asJson(['status' => 'dnt']);
        }

        $events = $request->getBodyParam('events', []);
        if (empty($events)) {
            // Single event
            $events = [$request->getBodyParams()];
        }

        $recorded = 0;
        $visitorId = Plugin::getInstance()->assignment->getVisitorId();

        foreach ($events as $event) {
            $experimentHandle = $event['experiment'] ?? null;
            $eventType = $event['type'] ?? 'impression';
            $goalHandle = $event['goal'] ?? null;

            if (!$experimentHandle) {
                continue;
            }

            $experiment = Plugin::getInstance()->experiments->getRunningExperiment($experimentHandle);
            if (!$experiment) {
                continue;
            }

            $variant = Plugin::getInstance()->assignment->assignVariant($visitorId, $experiment);
            if (!$variant) {
                continue;
            }

            $context = [
                'url' => $event['url'] ?? null,
                'referrer' => $event['referrer'] ?? null,
                'metadata' => $event['metadata'] ?? null,
            ];

            if ($eventType === 'conversion' && $goalHandle) {
                $goal = Plugin::getInstance()->experiments->getGoalByHandle($experiment->id, $goalHandle);
                $goalId = $goal?->id;
                if (Plugin::getInstance()->tracking->recordConversion($experiment->id, $variant->id, $goalId, $visitorId, $context)) {
                    $recorded++;
                }
            } else {
                if (Plugin::getInstance()->tracking->recordImpression($experiment->id, $variant->id, $visitorId, $context)) {
                    $recorded++;
                }
            }
        }

        return $this->asJson([
            'status' => 'ok',
            'recorded' => $recorded,
        ]);
    }

    /**
     * GET endpoint returning a 1x1 transparent GIF (pixel tracking).
     */
    public function actionPixel(): Response
    {
        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        // Respect DNT
        if ($settings->respectDnt && $request->getHeaders()->get('DNT') === '1') {
            return $this->_pixelResponse();
        }

        $experimentHandle = $request->getQueryParam('e');
        if ($experimentHandle) {
            $experiment = Plugin::getInstance()->experiments->getRunningExperiment($experimentHandle);
            if ($experiment) {
                $visitorId = Plugin::getInstance()->assignment->getVisitorId();
                $variant = Plugin::getInstance()->assignment->assignVariant($visitorId, $experiment);
                if ($variant) {
                    Plugin::getInstance()->tracking->recordImpression(
                        $experiment->id,
                        $variant->id,
                        $visitorId,
                    );
                }
            }
        }

        return $this->_pixelResponse();
    }

    private function _pixelResponse(): Response
    {
        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Content-Type', 'image/gif');
        $response->getHeaders()->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->getHeaders()->set('Pragma', 'no-cache');
        $response->getHeaders()->set('Expires', '0');

        // 1x1 transparent GIF
        $response->content = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $response->format = Response::FORMAT_RAW;

        return $response;
    }
}
