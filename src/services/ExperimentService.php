<?php

namespace justinholtweb\wink\services;

use Craft;
use craft\helpers\DateTimeHelper;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\ExperimentStatus;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Goal;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\records\GoalRecord;
use justinholtweb\wink\records\VariantRecord;
use yii\base\Component;

class ExperimentService extends Component
{
    /**
     * Get an experiment by ID.
     */
    public function getExperimentById(int $id): ?Experiment
    {
        return Experiment::find()->id($id)->one();
    }

    /**
     * Get an experiment by handle.
     */
    public function getExperimentByHandle(string $handle): ?Experiment
    {
        return Experiment::find()->handle($handle)->one();
    }

    /**
     * Get running experiment by handle.
     */
    public function getRunningExperiment(string $handle): ?Experiment
    {
        return Experiment::find()
            ->handle($handle)
            ->experimentStatus(ExperimentStatus::Running)
            ->one();
    }

    /**
     * Save an experiment element.
     */
    public function saveExperiment(Experiment $experiment): bool
    {
        return Craft::$app->getElements()->saveElement($experiment);
    }

    /**
     * Delete an experiment element.
     */
    public function deleteExperiment(Experiment $experiment): bool
    {
        return Craft::$app->getElements()->deleteElement($experiment);
    }

    // Status transitions

    /**
     * Start an experiment.
     */
    public function startExperiment(Experiment $experiment): bool
    {
        $current = ExperimentStatus::from($experiment->experimentStatus);
        if (!$current->canTransitionTo(ExperimentStatus::Running)) {
            return false;
        }

        $experiment->experimentStatus = ExperimentStatus::Running->value;
        $experiment->startDate = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

        return $this->saveExperiment($experiment);
    }

    /**
     * Pause an experiment.
     */
    public function pauseExperiment(Experiment $experiment): bool
    {
        $current = ExperimentStatus::from($experiment->experimentStatus);
        if (!$current->canTransitionTo(ExperimentStatus::Paused)) {
            return false;
        }

        $experiment->experimentStatus = ExperimentStatus::Paused->value;
        return $this->saveExperiment($experiment);
    }

    /**
     * Complete an experiment.
     */
    public function completeExperiment(Experiment $experiment, ?int $winnerVariantId = null): bool
    {
        $current = ExperimentStatus::from($experiment->experimentStatus);
        if (!$current->canTransitionTo(ExperimentStatus::Completed)) {
            return false;
        }

        $experiment->experimentStatus = ExperimentStatus::Completed->value;
        $experiment->endDate = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
        $experiment->winnerVariantId = $winnerVariantId;

        return $this->saveExperiment($experiment);
    }

    /**
     * Archive an experiment.
     */
    public function archiveExperiment(Experiment $experiment): bool
    {
        $current = ExperimentStatus::from($experiment->experimentStatus);
        if (!$current->canTransitionTo(ExperimentStatus::Archived)) {
            return false;
        }

        $experiment->experimentStatus = ExperimentStatus::Archived->value;
        return $this->saveExperiment($experiment);
    }

    // Variants

    /**
     * @return Variant[]
     */
    public function getVariantsByExperimentId(int $experimentId): array
    {
        $records = VariantRecord::find()
            ->where(['experimentId' => $experimentId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return array_map(fn(VariantRecord $record) => new Variant([
            'id' => $record->id,
            'experimentId' => $record->experimentId,
            'handle' => $record->handle,
            'title' => $record->title,
            'content' => $record->content,
            'weight' => $record->weight,
            'sortOrder' => $record->sortOrder,
            'isControl' => (bool)$record->isControl,
        ]), $records);
    }

    public function getVariantById(int $id): ?Variant
    {
        $record = VariantRecord::findOne($id);
        if (!$record) {
            return null;
        }

        return new Variant([
            'id' => $record->id,
            'experimentId' => $record->experimentId,
            'handle' => $record->handle,
            'title' => $record->title,
            'content' => $record->content,
            'weight' => $record->weight,
            'sortOrder' => $record->sortOrder,
            'isControl' => (bool)$record->isControl,
        ]);
    }

    /**
     * Save variants for an experiment. Replaces all existing variants.
     * @param Variant[] $variants
     */
    public function saveVariants(int $experimentId, array $variants): bool
    {
        // Get existing variant IDs
        $existingIds = VariantRecord::find()
            ->where(['experimentId' => $experimentId])
            ->select('id')
            ->column();

        $keepIds = [];

        foreach ($variants as $i => $variant) {
            $variant->experimentId = $experimentId;
            $variant->sortOrder = $i;

            if ($variant->id && in_array($variant->id, $existingIds)) {
                $record = VariantRecord::findOne($variant->id);
                $keepIds[] = $variant->id;
            } else {
                $record = new VariantRecord();
                $record->experimentId = $experimentId;
            }

            $record->handle = $variant->handle;
            $record->title = $variant->title;
            $record->content = $variant->content;
            $record->weight = $variant->weight;
            $record->sortOrder = $variant->sortOrder;
            $record->isControl = $variant->isControl;

            if (!$record->save()) {
                return false;
            }

            $variant->id = $record->id;
        }

        // Delete removed variants
        $removeIds = array_diff($existingIds, $keepIds);
        if (!empty($removeIds)) {
            VariantRecord::deleteAll(['id' => $removeIds]);
        }

        return true;
    }

    // Goals

    /**
     * @return Goal[]
     */
    public function getGoalsByExperimentId(int $experimentId): array
    {
        $records = GoalRecord::find()
            ->where(['experimentId' => $experimentId])
            ->all();

        return array_map(fn(GoalRecord $record) => new Goal([
            'id' => $record->id,
            'experimentId' => $record->experimentId,
            'name' => $record->name,
            'handle' => $record->handle,
            'goalType' => GoalType::from($record->goalType),
            'goalTarget' => $record->goalTarget,
            'isPrimary' => (bool)$record->isPrimary,
        ]), $records);
    }

    public function getGoalById(int $id): ?Goal
    {
        $record = GoalRecord::findOne($id);
        if (!$record) {
            return null;
        }

        return new Goal([
            'id' => $record->id,
            'experimentId' => $record->experimentId,
            'name' => $record->name,
            'handle' => $record->handle,
            'goalType' => GoalType::from($record->goalType),
            'goalTarget' => $record->goalTarget,
            'isPrimary' => (bool)$record->isPrimary,
        ]);
    }

    public function getGoalByHandle(int $experimentId, string $handle): ?Goal
    {
        $record = GoalRecord::findOne([
            'experimentId' => $experimentId,
            'handle' => $handle,
        ]);

        if (!$record) {
            return null;
        }

        return new Goal([
            'id' => $record->id,
            'experimentId' => $record->experimentId,
            'name' => $record->name,
            'handle' => $record->handle,
            'goalType' => GoalType::from($record->goalType),
            'goalTarget' => $record->goalTarget,
            'isPrimary' => (bool)$record->isPrimary,
        ]);
    }

    /**
     * Save goals for an experiment. Replaces all existing goals.
     * @param Goal[] $goals
     */
    public function saveGoals(int $experimentId, array $goals): bool
    {
        $existingIds = GoalRecord::find()
            ->where(['experimentId' => $experimentId])
            ->select('id')
            ->column();

        $keepIds = [];

        foreach ($goals as $goal) {
            $goal->experimentId = $experimentId;

            if ($goal->id && in_array($goal->id, $existingIds)) {
                $record = GoalRecord::findOne($goal->id);
                $keepIds[] = $goal->id;
            } else {
                $record = new GoalRecord();
                $record->experimentId = $experimentId;
            }

            $record->name = $goal->name;
            $record->handle = $goal->handle;
            $record->goalType = $goal->goalType->value;
            $record->goalTarget = $goal->goalTarget;
            $record->isPrimary = $goal->isPrimary;

            if (!$record->save()) {
                return false;
            }

            $goal->id = $record->id;
        }

        // Delete removed goals
        $removeIds = array_diff($existingIds, $keepIds);
        if (!empty($removeIds)) {
            GoalRecord::deleteAll(['id' => $removeIds]);
        }

        return true;
    }
}
