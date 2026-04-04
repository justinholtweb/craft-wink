<?php

namespace justinholtweb\wink\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\wink\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('wink/settings/_index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();
        $request = Craft::$app->getRequest();

        $settings->enableTracking = (bool)$request->getBodyParam('enableTracking');
        $settings->respectDnt = (bool)$request->getBodyParam('respectDnt');
        $settings->anonymizeIp = (bool)$request->getBodyParam('anonymizeIp');
        $settings->cookieName = $request->getBodyParam('cookieName', '_wink_vid');
        $settings->cookieDuration = (int)$request->getBodyParam('cookieDuration', 365);

        $settings->enableGa4 = (bool)$request->getBodyParam('enableGa4');
        $settings->ga4MeasurementId = $request->getBodyParam('ga4MeasurementId', '');
        $settings->enableGtm = (bool)$request->getBodyParam('enableGtm');
        $settings->gtmEventName = $request->getBodyParam('gtmEventName', 'wink_experiment');

        $settings->batchInterval = (int)$request->getBodyParam('batchInterval', 5);
        $settings->retentionDays = (int)$request->getBodyParam('retentionDays', 90);

        $settings->significanceThreshold = (int)$request->getBodyParam('significanceThreshold', 95);
        $settings->minimumSampleSize = (int)$request->getBodyParam('minimumSampleSize', 100);

        if (!Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('wink', 'Couldn't save settings.'));
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('wink', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
