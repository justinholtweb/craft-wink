<?php

namespace justinholtweb\wink\elements\db;

use craft\elements\db\ElementQuery;
use justinholtweb\wink\enums\ExperimentStatus;

class ExperimentQuery extends ElementQuery
{
    public ?string $handle = null;
    public ?string $experimentStatus = null;
    public ?int $trafficPercent = null;

    public function handle(?string $value): self
    {
        $this->handle = $value;
        return $this;
    }

    public function experimentStatus(string|ExperimentStatus|null $value): self
    {
        if ($value instanceof ExperimentStatus) {
            $value = $value->value;
        }
        $this->experimentStatus = $value;
        return $this;
    }

    public function trafficPercent(?int $value): self
    {
        $this->trafficPercent = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('wink_experiments');

        $this->query->select([
            'wink_experiments.handle',
            'wink_experiments.description',
            'wink_experiments.experimentStatus',
            'wink_experiments.trafficPercent',
            'wink_experiments.startDate',
            'wink_experiments.endDate',
            'wink_experiments.winnerVariantId',
        ]);

        if ($this->handle !== null) {
            $this->subQuery->andWhere(['wink_experiments.handle' => $this->handle]);
        }

        if ($this->experimentStatus !== null) {
            $this->subQuery->andWhere(['wink_experiments.experimentStatus' => $this->experimentStatus]);
        }

        if ($this->trafficPercent !== null) {
            $this->subQuery->andWhere(['wink_experiments.trafficPercent' => $this->trafficPercent]);
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            ExperimentStatus::Draft->value => ['wink_experiments.experimentStatus' => 'draft'],
            ExperimentStatus::Running->value => ['wink_experiments.experimentStatus' => 'running'],
            ExperimentStatus::Paused->value => ['wink_experiments.experimentStatus' => 'paused'],
            ExperimentStatus::Completed->value => ['wink_experiments.experimentStatus' => 'completed'],
            ExperimentStatus::Archived->value => ['wink_experiments.experimentStatus' => 'archived'],
            default => parent::statusCondition($status),
        };
    }
}
