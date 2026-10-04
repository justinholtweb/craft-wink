<?php
/**
 * Who can run experiments, and what the anonymous tracking endpoint will take — checked over HTTP
 * in the plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-wink/tests/harness/security.php
 *
 * Until 5.0.6 Wink registered no permissions, so every control panel user could create, start,
 * end and delete experiments, read their reports and change the settings; and `/wink/track` took
 * any number of events of any kind — conversions for goals that don't exist, from visitors who had
 * never seen the experiment — with no limit per address. Each refusal is paired with what is still
 * allowed. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Goal;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();
$wink = Plugin::getInstance();

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'wk-' . bin2hex(random_bytes(12));
$projectConfig = Craft::$app->getProjectConfig();
$settingsBefore = $projectConfig->get('plugins.wink.settings');
$cleanup = ['users' => [], 'experiments' => []];

register_shutdown_function(function() use (&$cleanup, $settingsBefore, $projectConfig) {
    foreach ($cleanup['experiments'] as $experiment) {
        Craft::$app->getDb()->createCommand()->delete('{{%wink_events}}', ['experimentId' => $experiment->id])->execute();
        Craft::$app->getElements()->deleteElement($experiment, true);
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    clearBudget();

    // The admin's settings save ran in the web process: reload, then put back what was there.
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    $projectConfig->reset();
    if ($projectConfig->get('plugins.wink.settings') !== $settingsBefore) {
        $settingsBefore === null
            ? $projectConfig->remove('plugins.wink.settings')
            : $projectConfig->set('plugins.wink.settings', $settingsBefore);
        $projectConfig->saveModifiedConfigData();
        $projectConfig->writeYamlFiles(true);
    }
});

function clearBudget(): void
{
    // Requests come from inside the container; clear every address they might be charged to.
    foreach (['127.0.0.1', '::1', '::/64', gethostbyname(gethostname())] as $ip) {
        Craft::$app->getCache()->delete(sprintf('wink:budget:%s:%d', sha1($ip), intdiv(time(), 60)));
    }
}

// -------------------------------------------------------------------------------------------
// A running experiment with two variants and a goal.

$makeExperiment = static function(string $handle) use ($wink, &$cleanup): Experiment {
    $experiment = new Experiment(['title' => "Wink security $handle", 'handle' => $handle, 'experimentStatus' => 'running', 'trafficPercent' => 100]);
    Craft::$app->getElements()->saveElement($experiment) or throw new RuntimeException(json_encode($experiment->getErrors()));
    $wink->experiments->saveVariants($experiment->id, [
        new Variant(['handle' => 'control', 'title' => 'Control', 'weight' => 50, 'isControl' => true]),
        new Variant(['handle' => 'challenger', 'title' => 'Challenger', 'weight' => 50]),
    ]);
    $wink->experiments->saveGoals($experiment->id, [
        new Goal(['name' => 'Signup', 'handle' => 'signup', 'goalType' => GoalType::CustomEvent, 'isPrimary' => true]),
    ]);

    return $cleanup['experiments'][] = $experiment;
};

$experiment = $makeExperiment("wink-sec-$run");
$events = static fn(string $type) => (int)(new Query())->from('{{%wink_events}}')->where(['experimentId' => $experiment->id, 'eventType' => $type])->count();

// -------------------------------------------------------------------------------------------
// Users.

$makeUser = static function(string $who, array $permissions) use ($run, $password, &$cleanup): User {
    $user = new User(['username' => "wink-$who-$run", 'email' => "wink-$who-$run@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($user, false);
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);

    return $cleanup['users'][] = $user;
};

$base = ['accesscp', 'accessplugin-wink'];
$nobody = $makeUser('nobody', $base);
$reporter = $makeUser('reporter', array_merge($base, ['wink:viewreports']));
$manager = $makeUser('manager', array_merge($base, ['wink:manageexperiments']));

/** @return array{0: Client, 1: callable} */
function client(?string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);
        if ($login->getStatusCode() !== 200) {
            echo "Could not sign in as $username\n";
            exit(1);
        }
    }

    $post = static function(string $action, array $params, bool $asJson = true) use ($http, $json, $csrf): int {
        return $http->post("index.php?p=admin/actions/$action", [
            'headers' => $asJson ? $json : [],
            'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
        ])->getStatusCode();
    };

    return [$http, $post];
}

[$nobodyHttp, $nobodyPost] = client($nobody->username, $password);
[$reporterHttp, $reporterPost] = client($reporter->username, $password);
[$managerHttp, $managerPost] = client($manager->username, $password);
[$adminHttp, $adminPost] = client('admin', 'claudepassword');

$status = static fn() => Experiment::find()->id($experiment->id)->status(null)->one()?->experimentStatus;
$statusValue = static fn($s) => is_object($s) ? $s->value : (string)$s;

// -------------------------------------------------------------------------------------------
echo "\nControl panel\n";

check('a control panel user with no Wink permission can’t open experiments, reports or settings', function() use ($nobodyHttp, $experiment) {
    $codes = [];
    foreach (['wink/experiments', "wink/experiments/{$experiment->id}", 'wink/reports', "wink/reports/{$experiment->id}", 'wink/settings'] as $path) {
        $codes[$path] = $nobodyHttp->get("index.php?p=admin/$path")->getStatusCode();
    }

    return array_unique(array_values($codes)) === [403] ?: json_encode($codes);
});

check('…or pause, end or delete an experiment', function() use ($nobodyPost, $experiment, $status, $statusValue) {
    $codes = [
        $nobodyPost('wink/experiments/update-status', ['id' => $experiment->id, 'action' => 'pause']),
        $nobodyPost('wink/reports/declare-winner', ['experimentId' => $experiment->id, 'winnerVariantId' => 1]),
        $nobodyPost('wink/experiments/delete', ['id' => $experiment->id]),
    ];

    return array_unique($codes) === [403] && $statusValue($status()) === 'running' ?: json_encode($codes) . ' ' . $statusValue($status());
});

check('…and the element itself refuses them, so the element index can’t either', function() use ($experiment, $nobody) {
    return !Craft::$app->getElements()->canDelete($experiment, $nobody) && !Craft::$app->getElements()->canSave($experiment, $nobody) ?: 'allowed';
});

check('someone with the reports permission reads reports', function() use ($reporterHttp, $experiment) {
    return ($s = $reporterHttp->get("index.php?p=admin/wink/reports/{$experiment->id}")->getStatusCode()) === 200 ?: "status $s";
});

check('…but can’t end the experiment from them, or edit it', function() use ($reporterPost, $reporterHttp, $experiment, $status, $statusValue) {
    $declare = $reporterPost('wink/reports/declare-winner', ['experimentId' => $experiment->id, 'winnerVariantId' => 1]);
    $edit = $reporterHttp->get("index.php?p=admin/wink/experiments/{$experiment->id}")->getStatusCode();

    return $declare === 403 && $edit === 403 && $statusValue($status()) === 'running' ?: "declare $declare, edit $edit";
});

check('someone who manages experiments can pause and restart one', function() use ($managerPost, $experiment, $status, $statusValue) {
    $pause = $managerPost('wink/experiments/update-status', ['id' => $experiment->id, 'action' => 'pause']);
    $paused = $statusValue($status());
    $start = $managerPost('wink/experiments/update-status', ['id' => $experiment->id, 'action' => 'start']);

    return $pause === 200 && $paused === 'paused' && $start === 200 && $statusValue($status()) === 'running' ?: "pause $pause ($paused), start $start";
});

check('…but not change the settings, which take an admin', function() use ($managerPost) {
    return ($s = $managerPost('wink/settings/save', ['cookieName' => '_wink_vid', 'batchInterval' => 9], false)) === 403 ?: "status $s";
});

check('an admin can, and a bad value is refused rather than saved', function() use ($adminPost, $projectConfig) {
    $adminPost('wink/settings/save', ['enableTracking' => 1, 'respectDnt' => 1, 'cookieName' => '_wink_vid', 'batchInterval' => 7, 'retentionDays' => 90, 'significanceThreshold' => 95, 'minimumSampleSize' => 100, 'trackingBudgetPerMinute' => 120], false);
    $adminPost('wink/settings/save', ['enableTracking' => 1, 'respectDnt' => 1, 'cookieName' => 'bad name; Path=/', 'batchInterval' => 8, 'retentionDays' => 90, 'significanceThreshold' => 95, 'minimumSampleSize' => 100], false);

    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    $projectConfig->reset();
    $stored = $projectConfig->get('plugins.wink.settings') ?? [];

    return (int)($stored['batchInterval'] ?? 0) === 7 && ($stored['cookieName'] ?? '') === '_wink_vid' ?: json_encode(array_intersect_key($stored, ['batchInterval' => 1, 'cookieName' => 1]));
});

// -------------------------------------------------------------------------------------------
echo "\nThe tracking endpoint\n";

/** Post events as an anonymous visitor with their own cookie jar. */
$visitor = static function() {
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false]);

    return static fn(array $events, array $headers = []) => (int)(json_decode((string)$http->post('index.php?p=wink/track', [
        'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'] + $headers,
        'body' => json_encode(['events' => $events]),
    ])->getBody(), true)['recorded'] ?? -1);
};
$handle = $experiment->handle;

check('a visitor’s impression, then their conversion, both count', function() use ($visitor, $handle) {
    clearBudget();
    $send = $visitor();

    return $send([['experiment' => $handle, 'type' => 'impression']]) === 1
        && $send([['experiment' => $handle, 'type' => 'conversion', 'goal' => 'signup']]) === 1 ?: 'not recorded';
});

check('a conversion from someone who never saw the experiment doesn’t', function() use ($visitor, $handle) {
    clearBudget();

    return ($r = $visitor()([['experiment' => $handle, 'type' => 'conversion', 'goal' => 'signup']])) === 0 ?: "recorded $r";
});

check('…nor one for a goal the experiment doesn’t have', function() use ($visitor, $handle) {
    clearBudget();
    $send = $visitor();
    $send([['experiment' => $handle, 'type' => 'impression']]);

    return ($r = $send([['experiment' => $handle, 'type' => 'conversion', 'goal' => 'no-such-goal']])) === 0 ?: "recorded $r";
});

check('…nor an event of a type Wink doesn’t have', function() use ($visitor, $handle) {
    clearBudget();

    return ($r = $visitor()([['experiment' => $handle, 'type' => 'purchase-9000']])) === 0 ?: "recorded $r";
});

check('one visitor converting over and over still counts once in the rate', function() use ($visitor, $handle, $wink, $experiment) {
    clearBudget();
    $send = $visitor();
    $send([['experiment' => $handle, 'type' => 'impression']]);
    $send(array_fill(0, 20, ['experiment' => $handle, 'type' => 'conversion', 'goal' => 'signup']));

    $report = $wink->stats->getExperimentReport(Experiment::find()->id($experiment->id)->one());
    $rates = array_map(fn($vr) => round($vr->conversionRate, 3), $report->variants);

    return max($rates) <= 1.0 ?: 'rates ' . json_encode($rates);
});

check('a request carries at most 25 events', function() use ($visitor, $makeExperiment, $run) {
    clearBudget();
    // Thirty experiments, one impression each: every one would record, if it were let through.
    $handles = [];
    for ($i = 0; $i < 30; $i++) {
        $handles[] = $makeExperiment("wink-sec-$run-$i")->handle;
    }

    return ($r = $visitor()(array_map(fn($h) => ['experiment' => $h, 'type' => 'impression'], $handles))) === 25 ?: "recorded $r";
});

check('one address runs out of budget, and a forged X-Forwarded-For doesn’t buy more', function() use ($visitor, $handle) {
    clearBudget();
    // 120 a minute by default; five full requests from one address spend it.
    $send = $visitor();
    for ($i = 0; $i < 5; $i++) {
        $send(array_fill(0, 25, ['experiment' => $handle, 'type' => 'impression']));
    }

    $fresh = $visitor()([['experiment' => $handle, 'type' => 'impression']], ['X-Forwarded-For' => '203.0.113.' . random_int(1, 254)]);
    clearBudget();
    $afterReset = $visitor()([['experiment' => $handle, 'type' => 'impression']]);

    return $fresh === 0 && $afterReset === 1 ?: "over budget recorded $fresh, after reset $afterReset";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
