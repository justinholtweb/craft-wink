<?php

namespace justinholtweb\wink\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Goal;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ExperimentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('wink/experiments/_index');
    }

    public function actionEdit(?int $experimentId = null, ?Experiment $experiment = null): Response
    {
        if ($experiment === null && $experimentId) {
            $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);
            if (!$experiment) {
                throw new NotFoundHttpException('Experiment not found');
            }
        } elseif ($experiment === null) {
            $experiment = new Experiment();
            $experiment->experimentStatus = 'draft';
            $experiment->setVariants([
                new Variant(['handle' => 'control', 'title' => Craft::t('wink', 'Control'), 'weight' => 50, 'isControl' => true]),
                new Variant(['handle' => 'variant-a', 'title' => Craft::t('wink', 'Variant A'), 'weight' => 50]),
            ]);
            $experiment->setGoals([
                new Goal(['name' => Craft::t('wink', 'Primary Goal'), 'handle' => 'primary-goal', 'goalType' => GoalType::Pageview, 'isPrimary' => true]),
            ]);
        }

        $title = $experiment->id ? $experiment->title : Craft::t('wink', 'New Experiment');

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
        $deliveryMode = $request->getBodyParam('deliveryMode');
        $experiment->deliveryMode = is_string($deliveryMode) && $deliveryMode !== '' ? $deliveryMode : null;

        // Don't allow changing status through the save action directly
        if (!$experiment->id) {
            $experiment->experimentStatus = 'draft';
        }

        // Variants and goals are replaced only when posted; a request with neither leaves them be.
        $variants = $request->getBodyParam('variants') !== null ? $this->_postedVariants() : null;
        $goals = $request->getBodyParam('goals') !== null ? $this->_postedGoals() : null;

        // Everything is checked before anything is saved: before 5.2.0 the experiment was saved
        // first, and a variant or goal that failed afterwards was dropped without a word.
        $service = Plugin::getInstance()->experiments;
        $valid = $experiment->validate();
        if ($variants !== null) {
            $experiment->setVariants($variants);
            $valid = $service->validateVariants($experiment, $variants) && $valid;
        }
        if ($goals !== null) {
            $experiment->setGoals($goals);
            $valid = $service->validateGoals($experiment, $goals) && $valid;
        }

        if ($valid) {
            $isNew = !$experiment->id;
            $transaction = Craft::$app->getDb()->beginTransaction();
            try {
                $valid = $service->saveExperiment($experiment)
                    && ($variants === null || $service->saveVariants($experiment->id, $variants))
                    && ($goals === null || $service->saveGoals($experiment->id, $goals));
                $valid ? $transaction->commit() : $transaction->rollBack();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
            // A rolled-back new experiment must not come back to the form with the ID it never kept.
            if (!$valid && $isNew) {
                $experiment->id = null;
            }
        }

        if (!$valid) {
            Craft::$app->getSession()->setError(Craft::t('wink', 'Couldn\'t save experiment.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'experiment' => $experiment,
            ]);
            return null;
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
        // Not `action`: Craft reserves that body param for routing, and Craft.sendActionRequest()
        // strips it from the data, so before 5.2.0 every status button answered 400.
        $transition = $request->getRequiredBodyParam('transition');

        $experiment = Plugin::getInstance()->experiments->getExperimentById($experimentId);
        if (!$experiment) {
            throw new NotFoundHttpException('Experiment not found');
        }

        $service = Plugin::getInstance()->experiments;

        $success = match ($transition) {
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

    /**
     * The variant rows as posted, in order. The control is a radio (`controlVariant`) naming one
     * row's key; a per-row `isControl` is still read for anything posting the old form.
     *
     * @return Variant[]
     */
    private function _postedVariants(): array
    {
        $request = Craft::$app->getRequest();
        $rows = $request->getBodyParam('variants', []);
        $controlKey = $request->getBodyParam('controlVariant');
        $variants = [];

        foreach (is_array($rows) ? $rows : [] as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $variant = new Variant();
            $variant->id = !empty($data['id']) ? (int)$data['id'] : null;
            $variant->handle = trim((string)($data['handle'] ?? ''));
            $variant->title = trim((string)($data['title'] ?? ''));
            $variant->content = (string)($data['content'] ?? '');
            $variant->weight = (int)($data['weight'] ?? 0);
            $variant->isControl = $controlKey !== null
                ? (string)$key === (string)$controlKey
                : (bool)($data['isControl'] ?? false);
            $variants[] = $variant;
        }

        return $variants;
    }

    /**
     * The goal rows as posted, in order. The primary goal is a radio (`primaryGoal`); with none
     * chosen, the first goal is primary.
     *
     * @return Goal[]
     */
    private function _postedGoals(): array
    {
        $request = Craft::$app->getRequest();
        $rows = $request->getBodyParam('goals', []);
        $primaryKey = $request->getBodyParam('primaryGoal');
        $goals = [];

        foreach (is_array($rows) ? $rows : [] as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $goal = new Goal();
            $goal->id = !empty($data['id']) ? (int)$data['id'] : null;
            $goal->name = trim((string)($data['name'] ?? ''));
            $goal->handle = trim((string)($data['handle'] ?? ''));
            $goal->goalType = GoalType::tryFrom((string)($data['goalType'] ?? '')) ?? GoalType::Pageview;
            $target = trim((string)($data['goalTarget'] ?? ''));
            $goal->goalTarget = $target !== '' ? $target : null;
            $goal->isPrimary = $primaryKey !== null
                ? (string)$key === (string)$primaryKey
                : (bool)($data['isPrimary'] ?? false);
            $goals[] = $goal;
        }

        if ($goals && !array_filter($goals, fn(Goal $goal) => $goal->isPrimary)) {
            $goals[0]->isPrimary = true;
        }

        return $goals;
    }
}
