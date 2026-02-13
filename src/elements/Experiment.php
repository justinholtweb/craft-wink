<?php

namespace jholt\wink\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use jholt\wink\elements\db\ExperimentQuery;
use jholt\wink\enums\ExperimentStatus;
use jholt\wink\models\Goal;
use jholt\wink\models\Variant;
use jholt\wink\Plugin;
use jholt\wink\records\ExperimentRecord;

class Experiment extends Element
{
    public static function displayName(): string
    {
        return Craft::t('wink', 'Experiment');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('wink', 'Experiments');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('wink', 'experiment');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('wink', 'experiments');
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        $statuses = [];
        foreach (ExperimentStatus::cases() as $status) {
            $statuses[$status->value] = [
                'label' => $status->label(),
                'color' => $status->color(),
            ];
        }
        return $statuses;
    }

    public static function find(): ExperimentQuery
    {
        return new ExperimentQuery(static::class);
    }

    public static function refHandle(): ?string
    {
        return 'experiment';
    }

    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('wink', 'All Experiments'),
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        foreach (ExperimentStatus::cases() as $status) {
            $sources[] = [
                'key' => 'status:' . $status->value,
                'label' => $status->label(),
                'criteria' => ['experimentStatus' => $status->value],
                'defaultSort' => ['dateCreated', 'desc'],
            ];
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => Craft::t('wink', 'Handle'),
            'experimentStatus' => Craft::t('wink', 'Status'),
            'trafficPercent' => Craft::t('wink', 'Traffic %'),
            'variantCount' => Craft::t('wink', 'Variants'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'experimentStatus', 'trafficPercent', 'variantCount', 'dateCreated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'handle' => Craft::t('wink', 'Handle'),
            'experimentStatus' => Craft::t('wink', 'Status'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['handle', 'description'];
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            Delete::class,
            Restore::class,
        ];
    }

    // Properties

    public string $handle = '';
    public ?string $description = null;
    public string $experimentStatus = 'draft';
    public int $trafficPercent = 100;
    public ?string $startDate = null;
    public ?string $endDate = null;
    public ?int $winnerVariantId = null;

    /** @var Variant[]|null */
    private ?array $_variants = null;

    /** @var Goal[]|null */
    private ?array $_goals = null;

    public function getStatus(): ?string
    {
        return $this->experimentStatus;
    }

    public function getExperimentStatusEnum(): ExperimentStatus
    {
        return ExperimentStatus::from($this->experimentStatus);
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('wink/experiments/' . $this->id);
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('wink/experiments');
    }

    public function canView(\craft\elements\User $user): bool
    {
        return $user->can('accessPlugin-wink');
    }

    public function canSave(\craft\elements\User $user): bool
    {
        return $user->can('accessPlugin-wink');
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        return $user->can('accessPlugin-wink');
    }

    // Variants

    /**
     * @return Variant[]
     */
    public function getVariants(): array
    {
        if ($this->_variants === null && $this->id) {
            $this->_variants = Plugin::getInstance()->experiments->getVariantsByExperimentId($this->id);
        }
        return $this->_variants ?? [];
    }

    public function setVariants(array $variants): void
    {
        $this->_variants = $variants;
    }

    public function getVariantCount(): int
    {
        return count($this->getVariants());
    }

    // Goals

    /**
     * @return Goal[]
     */
    public function getGoals(): array
    {
        if ($this->_goals === null && $this->id) {
            $this->_goals = Plugin::getInstance()->experiments->getGoalsByExperimentId($this->id);
        }
        return $this->_goals ?? [];
    }

    public function setGoals(array $goals): void
    {
        $this->_goals = $goals;
    }

    public function getPrimaryGoal(): ?Goal
    {
        foreach ($this->getGoals() as $goal) {
            if ($goal->isPrimary) {
                return $goal;
            }
        }
        $goals = $this->getGoals();
        return $goals[0] ?? null;
    }

    // Table attribute values

    protected function tableAttributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'experimentStatus' => sprintf(
                '<span class="status %s"></span>%s',
                $this->getExperimentStatusEnum()->color(),
                $this->getExperimentStatusEnum()->label(),
            ),
            'trafficPercent' => $this->trafficPercent . '%',
            'variantCount' => (string)$this->getVariantCount(),
            default => parent::tableAttributeHtml($attribute),
        };
    }

    // Element CRUD

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'string', 'max' => 255];
        $rules[] = [
            ['handle'],
            'match',
            'pattern' => '/^[a-z][a-z0-9\-]*$/',
            'message' => Craft::t('wink', 'Handle must start with a letter and contain only lowercase letters, numbers, and hyphens.'),
        ];
        $rules[] = [['trafficPercent'], 'integer', 'min' => 1, 'max' => 100];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new ExperimentRecord();
            $record->id = $this->id;
        } else {
            $record = ExperimentRecord::findOne($this->id);
            if (!$record) {
                $record = new ExperimentRecord();
                $record->id = $this->id;
            }
        }

        $record->handle = $this->handle;
        $record->description = $this->description;
        $record->experimentStatus = $this->experimentStatus;
        $record->trafficPercent = $this->trafficPercent;
        $record->startDate = Db::prepareDateForDb($this->startDate);
        $record->endDate = Db::prepareDateForDb($this->endDate);
        $record->winnerVariantId = $this->winnerVariantId;

        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
    }
}
