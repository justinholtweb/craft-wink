<?php

namespace justinholtweb\wink;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\models\Settings;
use justinholtweb\wink\services\AssignmentService;
use justinholtweb\wink\services\ExperimentService;
use justinholtweb\wink\services\StatsService;
use justinholtweb\wink\services\TrackingService;
use justinholtweb\wink\twig\WinkTwigExtension;
use justinholtweb\wink\variables\WinkVariable;
use yii\base\Event;

/**
 * @property-read ExperimentService $experiments
 * @property-read TrackingService $tracking
 * @property-read StatsService $stats
 * @property-read AssignmentService $assignment
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'experiments' => ExperimentService::class,
                'tracking' => TrackingService::class,
                'stats' => StatsService::class,
                'assignment' => AssignmentService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerElementTypes();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerVariables();
        $this->_registerTwigExtension();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Wink';
        $item['subnav'] = [
            'experiments' => [
                'label' => Craft::t('wink', 'Experiments'),
                'url' => 'wink/experiments',
            ],
            'reports' => [
                'label' => Craft::t('wink', 'Reports'),
                'url' => 'wink/reports',
            ],
            'settings' => [
                'label' => Craft::t('wink', 'Settings'),
                'url' => 'wink/settings',
            ],
        ];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('wink/settings/_index', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = Experiment::class;
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['wink'] = 'wink/experiments/index';
                $event->rules['wink/experiments'] = 'wink/experiments/index';
                $event->rules['wink/experiments/new'] = 'wink/experiments/edit';
                $event->rules['wink/experiments/<experimentId:\d+>'] = 'wink/experiments/edit';
                $event->rules['wink/reports'] = 'wink/reports/index';
                $event->rules['wink/reports/<experimentId:\d+>'] = 'wink/reports/detail';
                $event->rules['wink/settings'] = 'wink/settings/index';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['wink/track'] = 'wink/tracking/track';
                $event->rules['wink/pixel.gif'] = 'wink/tracking/pixel';
            }
        );
    }

    private function _registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('wink', WinkVariable::class);
            }
        );
    }

    private function _registerTwigExtension(): void
    {
        Craft::$app->view->registerTwigExtension(new WinkTwigExtension());
    }
}
