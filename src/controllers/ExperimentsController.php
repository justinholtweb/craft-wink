<?php

namespace jholt\wink\controllers;

use Craft;
use craft\web\Controller;
use jholt\wink\elements\Experiment;
use jholt\wink\enums\GoalType;
use jholt\wink\models\Goal;
use jholt\wink\models\Variant;
use jholt\wink\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ExperimentsController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('wink/experiments/_index');
    }

    public function actionEdit(?int $experimentId = null): Response
    {
        if ($experimentId) {
            $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);
            if (!$experiment) {
                throw new NotFoundHttpException('Experiment not found');
            }
            $title = $experiment->title;
        } else {
            $experiment = new Experiment();
            $experiment->experimentStatus = 'draft';
            $title = Craft::t('wink', 'New Experiment');
        }

        return $this->renderTemplate('wink/experiments/_edit', [
            'experiment' => $experiment,
            'title' => $title,
            'goalTypes' => GoalType::cases(),
            'isNew' => !$experiment->id,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $experimentId = $request->getBodyParam('experimentId');

        if ($experimentId) {
            $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);
            if (!$experiment) {
                throw new NotFoundHttpException('Experiment not found');
            }
        } else {
            $experiment = new Experiment();
        }

        $experiment->title = $request->getBodyParam('title');
        $experiment->handle = $request->getBodyParam('handle');
        $experiment->description = $request->getBodyParam('description');
        $experiment->trafficPercent = (int)($request->getBodyParam('trafficPercent') ?: 100);

        // Don't allow changing status through the save action directly
        if (!$experiment->id) {
            $experiment->experimentStatus = 'draft';
        }

        if (!Plugin::getInstance()->experiments->saveExperiment($experiment)) {
            Craft::$app->getSession()->setError(Craft::t('wink', 'Couldn't save experiment.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'experiment' => $experiment,
            ]);
            return null;
        }

        // Save variants
        $variantsData = $request->getBodyParam('variants', []);
        $variants = [];
        foreach ($variantsData as $i => $data) {
            $variant = new Variant();
            $variant->id = !empty($data['id']) ? (int)$data['id'] : null;
            $variant->handle = $data['handle'] ?? '';
            $variant->title = $data['title'] ?? '';
            $variant->content = $data['content'] ?? '';
            $variant->weight = (int)($data['weight'] ?? 50);
            $variant->isControl = (bool)($data['isControl'] ?? false);
            $variants[] = $variant;
        }

        if (!empty($variants)) {
            Plugin::getInstance()->experiments->saveVariants($experiment->id, $variants);
        }

        // Save goals
        $goalsData = $request->getBodyParam('goals', []);
        $goals = [];
        foreach ($goalsData as $data) {
            $goal = new Goal();
            $goal->id = !empty($data['id']) ? (int)$data['id'] : null;
            $goal->name = $data['name'] ?? '';
            $goal->handle = $data['handle'] ?? '';
            $goal->goalType = GoalType::from($data['goalType'] ?? 'pageview');
            $goal->goalTarget = $data['goalTarget'] ?? null;
            $goal->isPrimary = (bool)($data['isPrimary'] ?? false);
            $goals[] = $goal;
        }

        if (!empty($goals)) {
            Plugin::getInstance()->experiments->saveGoals($experiment->id, $goals);
        }

        Craft::$app->getSession()->setNotice(Craft::t('wink', 'Experiment saved.'));
        return $this->redirectToPostedUrl($experiment);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $experimentId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);

        if (!$experiment) {
            throw new NotFoundHttpException('Experiment not found');
        }

        Plugin::getInstance()->experiments->deleteExperiment($experiment);

        return $this->asSuccess(Craft::t('wink', 'Experiment deleted.'));
    }

    public function actionUpdateStatus(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $experimentId = $request->getRequiredBodyParam('id');
        $action = $request->getRequiredBodyParam('action');

        $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);
        if (!$experiment) {
            throw new NotFoundHttpException('Experiment not found');
        }

        $service = Plugin::getInstance()->experiments;

        $success = match ($action) {
            'start' => $service->startExperiment($experiment),
            'pause' => $service->pauseExperiment($experiment),
            'complete' => $service->completeExperiment(
                $experiment,
                $request->getBodyParam('winnerVariantId') ? (int)$request->getBodyParam('winnerVariantId') : null,
            ),
            'archive' => $service->archiveExperiment($experiment),
            default => false,
        };

        if (!$success) {
            return $this->asFailure(Craft::t('wink', 'Could not update experiment status.'));
        }

        return $this->asSuccess(Craft::t('wink', 'Experiment status updated.'));
    }
}
