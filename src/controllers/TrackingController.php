<?php

namespace justinholtweb\wink\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\wink\Plugin;
use yii\web\Response;

class TrackingController extends Controller
{
    /** Events accepted in one request. The tracker batches every few seconds; a page sends a handful. */
    public const MAX_EVENTS = 25;

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

        // This endpoint is anonymous and its counts decide which variant wins, so it takes no
        // more than a page would send. Before 5.0.6 it took any number of events of any kind,
        // conversions included, and recorded each one.
        if (!is_array($events)) {
            return $this->asJson(['status' => 'ok', 'recorded' => 0]);
        }
        $events = array_slice(array_values(array_filter($events, 'is_array')), 0, self::MAX_EVENTS);

        $recorded = 0;
        $visitorId = Plugin::getInstance()->assignment->getVisitorId();

        foreach ($events as $event) {
            $experimentHandle = $event['experiment'] ?? null;
            $eventType = $event['type'] ?? 'impression';
            $goalHandle = $event['goal'] ?? null;

            if (!is_string($experimentHandle) || $experimentHandle === ''
                || !in_array($eventType, ['impression', 'conversion'], true)
                || ($goalHandle !== null && !is_string($goalHandle))) {
                continue;
            }

            if (!Plugin::getInstance()->tracking->withinBudget()) {
                break;
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
                'url' => is_string($event['url'] ?? null) ? $event['url'] : null,
                'referrer' => is_string($event['referrer'] ?? null) ? $event['referrer'] : null,
                'metadata' => $event['metadata'] ?? null,
            ];

            if ($eventType === 'conversion') {
                // A conversion counts toward a goal this experiment has. Before 5.0.6 an unknown
                // goal was recorded anyway, with no goal at all.
                $goal = $goalHandle ? Plugin::getInstance()->experiments->getGoalByHandle($experiment->id, $goalHandle) : null;
                if ($goal === null) {
                    continue;
                }
                if (Plugin::getInstance()->tracking->recordConversion($experiment->id, $variant->id, $goal->id, $visitorId, $context)) {
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
        if (is_string($experimentHandle) && $experimentHandle !== '' && Plugin::getInstance()->tracking->withinBudget()) {
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
