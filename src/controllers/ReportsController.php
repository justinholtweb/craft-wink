<?php

namespace jholt\wink\controllers;

use Craft;
use craft\web\Controller;
use jholt\wink\elements\Experiment;
use jholt\wink\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReportsController extends Controller
{
    public function actionIndex(): Response
    {
        $experiments = Experiment::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        // Generate summary reports for each experiment
        $reports = [];
        foreach ($experiments as $experiment) {
            $reports[$experiment->id] = Plugin::getInstance()->stats->getExperimentReport($experiment);
        }

        return $this->renderTemplate('wink/reports/_index', [
            'experiments' => $experiments,
            'reports' => $reports,
        ]);
    }

    public function actionDetail(int $experimentId): Response
    {
        $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);
        if (!$experiment) {
            throw new NotFoundHttpException('Experiment not found');
        }

        $goalId = Craft::$app->getRequest()->getQueryParam('goalId');
        $report = Plugin::getInstance()->stats->getExperimentReport(
            $experiment,
            $goalId ? (int)$goalId : null,
        );

        return $this->renderTemplate('wink/reports/_detail', [
            'experiment' => $experiment,
            'report' => $report,
            'goals' => $experiment->getGoals(),
            'selectedGoalId' => $goalId,
        ]);
    }

    /**
     * AJAX endpoint for chart data.
     */
    public function actionChartData(): Response
    {
        $this->requireAcceptsJson();

        $experimentId = Craft::$app->getRequest()->getRequiredQueryParam('experimentId');
        $goalId = Craft::$app->getRequest()->getQueryParam('goalId');

        $experiment = Plugin::getInstance()->experiments->getExperimentById((int)$experimentId);
        if (!$experiment) {
            throw new NotFoundHttpException('Experiment not found');
        }

        $report = Plugin::getInstance()->stats->getExperimentReport(
            $experiment,
            $goalId ? (int)$goalId : null,
        );

        // Build Chart.js-compatible datasets
        $dates = [];
        $datasets = [];

        foreach ($report->variants as $vr) {
            foreach ($vr->timeSeries as $point) {
                if (!in_array($point['date'], $dates)) {
                    $dates[] = $point['date'];
                }
            }
        }

        sort($dates);

        $colors = ['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6'];

        foreach ($report->variants as $i => $vr) {
            $timeMap = [];
            foreach ($vr->timeSeries as $point) {
                $timeMap[$point['date']] = $point;
            }

            $conversionRates = [];
            foreach ($dates as $date) {
                $point = $timeMap[$date] ?? ['impressions' => 0, 'conversions' => 0];
                $rate = $point['impressions'] > 0
                    ? round($point['conversions'] / $point['impressions'] * 100, 2)
                    : 0;
                $conversionRates[] = $rate;
            }

            $color = $colors[$i % count($colors)];

            $datasets[] = [
                'label' => $vr->variantTitle,
                'data' => $conversionRates,
                'borderColor' => $color,
                'backgroundColor' => $color . '20',
                'fill' => false,
                'tension' => 0.3,
            ];
        }

        return $this->asJson([
            'labels' => $dates,
            'datasets' => $datasets,
        ]);
    }

    /**
     * Declare a winner and complete the experiment.
     */
    public function actionDeclareWinner(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $experimentId = $request->getRequiredBodyParam('experimentId');
        $winnerVariantId = $request->getRequiredBodyParam('winnerVariantId');

        $experiment = Plugin::getInstance()->experiments->getExperimentById((int)$experimentId);
        if (!$experiment) {
            throw new NotFoundHttpException('Experiment not found');
        }

        $success = Plugin::getInstance()->experiments->completeExperiment(
            $experiment,
            (int)$winnerVariantId,
        );

        if (!$success) {
            return $this->asFailure(Craft::t('wink', 'Could not declare winner.'));
        }

        return $this->asSuccess(Craft::t('wink', 'Winner declared and experiment completed.'));
    }
}
