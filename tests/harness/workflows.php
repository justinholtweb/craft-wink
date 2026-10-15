<?php
/**
 * The 5.2.0 fixes, checked over HTTP in the plugin-testing harness: the control panel's status
 * buttons, editor and report chart, conversions away from the experiment, and the consent gate.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-wink/tests/harness/workflows.php
 *
 * Until 5.2.0 the status buttons answered 400 (#1); only click goals ever converted (#2); a
 * conversion sent from a page without the experiment was dropped (#3); the report chart asked for
 * the dashboard instead of its data (#4); the editor couldn't add or remove a variant or goal, nor
 * checked the weights (#5); and there was no way to wait for consent before setting the visitor
 * cookie (#6). Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\models\Goal;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\services\AssignmentService;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
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
$projectConfig = Craft::$app->getProjectConfig();
$settingsBefore = $projectConfig->get('plugins.wink.settings');
$cleanup = ['experiments' => []];

register_shutdown_function(function() use (&$cleanup, $settingsBefore, $projectConfig) {
    foreach ($cleanup['experiments'] as $id) {
        Craft::$app->getDb()->createCommand()->delete('{{%wink_events}}', ['experimentId' => $id])->execute();
        if ($experiment = Experiment::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($experiment, true);
        }
    }
    clearBudget();

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
    foreach (['127.0.0.1', '::1', '::/64', gethostbyname(gethostname())] as $ip) {
        Craft::$app->getCache()->delete(sprintf('wink:budget:%s:%d', sha1($ip), intdiv(time(), 60)));
    }
}

/** Change the plugin's settings for the web process (restored at shutdown). */
function setSettings(array $settings): void
{
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, $settings + $plugin->getSettings()->toArray());
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->saveModifiedConfigData();
    $projectConfig->writeYamlFiles(true);
}

$makeExperiment = static function(string $handle, array $goals, string $status = 'running') use ($wink, &$cleanup): Experiment {
    $experiment = new Experiment(['title' => "Wink workflows $handle", 'handle' => $handle, 'experimentStatus' => $status, 'trafficPercent' => 100]);
    Craft::$app->getElements()->saveElement($experiment) or throw new RuntimeException(json_encode($experiment->getErrors()));
    $wink->experiments->saveVariants($experiment->id, [
        new Variant(['handle' => 'control', 'title' => 'Control', 'weight' => 50, 'isControl' => true]),
        new Variant(['handle' => 'challenger', 'title' => 'Challenger', 'weight' => 50]),
    ]);
    $wink->experiments->saveGoals($experiment->id, $goals);
    $cleanup['experiments'][] = $experiment->id;

    return $experiment;
};

$events = static fn(int $experimentId, string $type) => (int)(new Query())->from('{{%wink_events}}')->where(['experimentId' => $experimentId, 'eventType' => $type])->count();

// A browser with its own cookie jar, sending tracker batches.
$browser = static function(array $cookies = []): array {
    $jar = new CookieJar();
    foreach ($cookies as $name => $value) {
        $jar->setCookie(new SetCookie(['Name' => $name, 'Value' => $value, 'Domain' => 'localhost', 'Path' => '/']));
    }
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => $jar, 'http_errors' => false]);
    $send = static fn(array $events) => json_decode((string)$http->post('index.php?p=wink/track', [
        'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        'body' => json_encode(['events' => $events]),
    ])->getBody(), true) ?? [];

    return [$send, $jar];
};

// The admin, signed in to the control panel.
$admin = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$json = ['Accept' => 'application/json'];
$csrf = static fn() => (string)(json_decode((string)$admin->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
$login = $admin->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf()]]);
if ($login->getStatusCode() !== 200) {
    echo "Could not sign in as admin\n";
    exit(1);
}

$status = static fn(int $id) => (string)(new Query())->select('experimentStatus')->from('{{%wink_experiments}}')->where(['id' => $id])->scalar();

// -------------------------------------------------------------------------------------------
echo "Status buttons (#1)\n";

$lifecycle = $makeExperiment("wink-wf-life-$run", [new Goal(['name' => 'Signup', 'handle' => 'signup', 'goalType' => GoalType::CustomEvent])], 'draft');

check('the editor’s buttons send `transition`, which survives Craft.sendActionRequest()', function() use ($admin, $lifecycle) {
    $html = (string)$admin->get("index.php?p=admin/wink/experiments/{$lifecycle->id}")->getBody();

    return str_contains($html, 'data-transition="start"') && str_contains($html, 'transition: this.dataset.transition')
        && !str_contains($html, 'action: action') ?: 'editor still posts `action`';
});

check('start, pause, start and complete move the experiment through its lifecycle', function() use ($admin, $csrf, $json, $lifecycle, $status) {
    $seen = [];
    foreach (['start', 'pause', 'start', 'complete'] as $transition) {
        // Exactly what sendActionRequest() sends: JSON, with no `action` key in the body.
        $response = $admin->post('index.php?p=admin/actions/wink/experiments/update-status', [
            'headers' => $json + ['X-CSRF-Token' => $csrf(), 'Content-Type' => 'application/json'],
            'body' => json_encode(['id' => $lifecycle->id, 'transition' => $transition]),
        ]);
        $seen[] = $response->getStatusCode() . ':' . $status($lifecycle->id);
    }

    return $seen === ['200:running', '200:paused', '200:running', '200:completed'] ?: implode(', ', $seen);
});

// -------------------------------------------------------------------------------------------
echo "Editor (#5)\n";

check('the editor offers add and remove for variants and goals, and loads its script', function() use ($admin) {
    $html = (string)$admin->get('index.php?p=admin/wink/experiments/new')->getBody();
    $missing = array_filter([
        'add variant' => 'data-wink-add="variant"',
        'add goal' => 'data-wink-add="goal"',
        'variant template' => 'id="wink-variant-template"',
        'goal template' => 'id="wink-goal-template"',
        'remove' => 'data-wink-remove',
        'control radio' => 'name="controlVariant"',
        'cp script' => 'wink-cp.js',
    ], fn($needle) => !str_contains($html, $needle));

    return $missing === [] ?: 'missing: ' . implode(', ', array_keys($missing));
});

// Posted to the editor's own URL, as its form is, so a refused save re-renders the editor.
$editorPost = static function(array $params) use ($admin, $csrf): array {
    $response = $admin->post('index.php?p=admin/wink/experiments/new', ['form_params' => $params + [
        'action' => 'wink/experiments/save',
        'CRAFT_CSRF_TOKEN' => $csrf(), 'redirect' => Craft::$app->getSecurity()->hashData('wink/experiments/{id}'),
    ]]);

    return [$response->getStatusCode(), (string)$response->getBody()];
};

$editorHandle = "wink-wf-editor-$run";
$threeVariants = [
    'v0' => ['title' => 'Control', 'handle' => 'control', 'weight' => 40, 'content' => 'A'],
    'v1' => ['title' => 'B', 'handle' => 'b', 'weight' => 30, 'content' => 'B'],
    'n0' => ['title' => 'C', 'handle' => 'c', 'weight' => 30, 'content' => 'C'],
];
$twoGoals = [
    'g0' => ['name' => 'Thanks', 'handle' => 'thanks', 'goalType' => 'pageview', 'goalTarget' => '/thanks*'],
    'n1' => ['name' => 'Signup', 'handle' => 'signup', 'goalType' => 'form_submit', 'goalTarget' => '#signup'],
];

check('weights that don’t add up to 100 are refused, and nothing is saved', function() use ($editorPost, $editorHandle, $threeVariants, $twoGoals) {
    $variants = $threeVariants;
    $variants['n0']['weight'] = 50;
    [$code, $html] = $editorPost(['title' => 'Editor', 'handle' => $editorHandle, 'variants' => $variants, 'controlVariant' => 'v0', 'goals' => $twoGoals, 'primaryGoal' => 'g0']);

    // The editor comes back with the error and the rows as posted, including the added one.
    return $code === 200 && str_contains($html, 'they add up to 120') && preg_match('~name="variants\[v2\]\[handle\]"[^>]*value="c"~', $html)
        && Experiment::find()->handle($editorHandle)->status(null)->one() === null ?: "status $code, no error shown, or it was saved";
});

check('a new experiment saves with three variants and two goals', function() use ($editorPost, $editorHandle, $threeVariants, $twoGoals, $wink, &$cleanup) {
    [$code] = $editorPost(['title' => 'Editor', 'handle' => $editorHandle, 'variants' => $threeVariants, 'controlVariant' => 'v0', 'goals' => $twoGoals, 'primaryGoal' => 'n1']);
    $experiment = Experiment::find()->handle($editorHandle)->status(null)->one();
    if (!$experiment) {
        return "not saved (status $code)";
    }
    $cleanup['experiments'][] = $experiment->id;

    $variants = array_map(fn($v) => [$v->handle, $v->weight, $v->isControl], $wink->experiments->getVariantsByExperimentId($experiment->id));
    $goals = array_map(fn($g) => [$g->handle, $g->goalType->value, $g->isPrimary], $wink->experiments->getGoalsByExperimentId($experiment->id));

    return $variants === [['control', 40, true], ['b', 30, false], ['c', 30, false]]
        && $goals === [['thanks', 'pageview', false], ['signup', 'form_submit', true]] ?: json_encode([$variants, $goals]);
});

check('a variant and a goal can be removed again', function() use ($editorPost, $editorHandle, $wink) {
    $experiment = Experiment::find()->handle($editorHandle)->status(null)->one();
    [$control, $b] = $wink->experiments->getVariantsByExperimentId($experiment->id);
    [$thanks] = $wink->experiments->getGoalsByExperimentId($experiment->id);
    $editorPost([
        'experimentId' => $experiment->id, 'title' => 'Editor', 'handle' => $editorHandle,
        'variants' => [
            'v0' => ['id' => $control->id, 'title' => 'Control', 'handle' => 'control', 'weight' => 50],
            'v1' => ['id' => $b->id, 'title' => 'B', 'handle' => 'b', 'weight' => 50],
        ],
        'controlVariant' => 'v0',
        'goals' => ['g0' => ['id' => $thanks->id, 'name' => 'Thanks', 'handle' => 'thanks', 'goalType' => 'pageview', 'goalTarget' => '/thanks*']],
    ]);

    $variants = array_map(fn($v) => $v->handle, $wink->experiments->getVariantsByExperimentId($experiment->id));
    $goals = array_map(fn($g) => [$g->handle, $g->isPrimary], $wink->experiments->getGoalsByExperimentId($experiment->id));

    return $variants === ['control', 'b'] && $goals === [['thanks', true]] ?: json_encode([$variants, $goals]);
});

// -------------------------------------------------------------------------------------------
echo "Conversions (#2, #3)\n";

$shop = $makeExperiment("wink-wf-shop-$run", [
    new Goal(['name' => 'Purchase', 'handle' => "purchase-$run", 'goalType' => GoalType::CustomEvent, 'isPrimary' => true]),
    new Goal(['name' => 'Thanks', 'handle' => "thanks-$run", 'goalType' => GoalType::Pageview, 'goalTarget' => '/thanks*']),
]);
$other = $makeExperiment("wink-wf-other-$run", [
    new Goal(['name' => 'Purchase', 'handle' => "purchase-$run", 'goalType' => GoalType::CustomEvent, 'isPrimary' => true]),
]);

check('the tracker is given page view and form submit goals, not just clicks', function() use ($shop, $run) {
    $html = Craft::$app->getView()->renderString('{{ winkTrackingScript() }}', [], 'site');
    preg_match('~window\._winkConfig = (.*);</script>~s', $html, $m);
    $goals = array_filter(json_decode($m[1] ?? '{}', true)['goals'] ?? [], fn($g) => $g['experiment'] === $shop->handle);

    return array_values(array_map(fn($g) => $g['type'] . ' ' . $g['target'], $goals)) === ['pageview /thanks*'] ?: json_encode($goals);
});

check('Wink.convert() from a page without the experiment counts, for each experiment the visitor saw', function() use ($browser, $shop, $other, $events, $run) {
    clearBudget();
    [$send] = $browser();
    $send([['experiment' => $shop->handle, 'type' => 'impression']]);           // saw the shop test…
    $result = $send([['type' => 'conversion', 'goal' => "purchase-$run"]]);      // …converts on /thank-you

    return ($result['recorded'] ?? null) === 1 && $events($shop->id, 'conversion') === 1 && $events($other->id, 'conversion') === 0
        ?: json_encode([$result, $events($shop->id, 'conversion'), $events($other->id, 'conversion')]);
});

check('…not from a visitor who never saw it, nor for a goal no running experiment has', function() use ($browser, $run) {
    clearBudget();
    [$send] = $browser();
    $stranger = $send([['type' => 'conversion', 'goal' => "purchase-$run"]])['recorded'] ?? null;
    $unknown = $send([['type' => 'conversion', 'goal' => "nobody-$run"]])['recorded'] ?? null;
    $impression = $send([['type' => 'impression']])['recorded'] ?? null;

    return [$stranger, $unknown, $impression] === [0, 0, 0] ?: json_encode([$stranger, $unknown, $impression]);
});

check('a detected page view goal names its experiment, and counts once the visitor has seen it', function() use ($browser, $shop, $events, $run) {
    clearBudget();
    [$send] = $browser();
    $before = $events($shop->id, 'conversion');
    $result = $send([
        ['experiment' => $shop->handle, 'type' => 'impression'],
        ['experiment' => $shop->handle, 'type' => 'conversion', 'goal' => "thanks-$run"],
    ]);

    return ($result['recorded'] ?? null) === 2 && $events($shop->id, 'conversion') === $before + 1 ?: json_encode($result);
});

// -------------------------------------------------------------------------------------------
echo "Report chart (#4)\n";

check('the report loads Chart.js from the plugin and asks for the chart by action', function() use ($admin, $shop) {
    $html = (string)$admin->get("index.php?p=admin/wink/reports/{$shop->id}")->getBody();

    return str_contains($html, 'chart.umd.min.js') && !str_contains($html, 'cdn.jsdelivr.net/npm/chart.js')
        && str_contains($html, "sendActionRequest('GET', 'wink/reports/chart-data'") ?: 'old chart loader';
});

check('the chart data comes back as Chart.js datasets', function() use ($admin, $shop) {
    $response = $admin->get('index.php', ['headers' => ['Accept' => 'application/json'], 'query' => [
        'p' => 'admin/actions/wink/reports/chart-data', 'experimentId' => $shop->id, 'goalId' => '',
    ]]);
    $data = json_decode((string)$response->getBody(), true);

    return $response->getStatusCode() === 200 && count($data['labels'] ?? []) >= 1
        && array_column($data['datasets'] ?? [], 'label') === ['Control', 'Challenger'] ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

// -------------------------------------------------------------------------------------------
echo "Consent (#6)\n";

setSettings(['requireConsent' => true]);
$page = static fn(Client $http) => $http->get('index.php?p=wink-wf-consent');
$cookieName = $wink->getSettings()->cookieName;

check('without consent, /wink/track records nothing and sets no cookie', function() use ($browser, $shop, $events, $cookieName) {
    clearBudget();
    [$send, $jar] = $browser();
    $before = $events($shop->id, 'impression');
    $result = $send([['experiment' => $shop->handle, 'type' => 'impression']]);

    return ($result['status'] ?? null) === 'consent' && $events($shop->id, 'impression') === $before && $jar->getCookieByName($cookieName) === null
        ?: json_encode([$result, $jar->toArray()]);
});

check('with the consent cookie, it records and issues the visitor cookie', function() use ($browser, $shop, $events, $cookieName) {
    clearBudget();
    [$send, $jar] = $browser([AssignmentService::CONSENT_COOKIE => '1']);
    $before = $events($shop->id, 'impression');
    $result = $send([['experiment' => $shop->handle, 'type' => 'impression']]);

    return ($result['recorded'] ?? null) === 1 && $events($shop->id, 'impression') === $before + 1 && $jar->getCookieByName($cookieName) !== null
        ?: json_encode([$result, $jar->toArray()]);
});

check('the pixel waits for consent too', function() use ($shop, $events) {
    clearBudget();
    $before = $events($shop->id, 'impression');
    $jar = new CookieJar();
    (new Client(['base_uri' => 'http://localhost/', 'cookies' => $jar, 'http_errors' => false]))->get('index.php', ['query' => ['p' => 'wink/pixel.gif', 'e' => $shop->handle]]);

    return $events($shop->id, 'impression') === $before && $jar->count() === 0 ?: 'recorded or set a cookie';
});

check('the tracker and the cache-safe runtime are told consent is required', function() use ($shop) {
    $script = Craft::$app->getView()->renderString('{{ winkTrackingScript() }}', [], 'site');
    $config = Plugin::getInstance()->delivery->getClientConfig($shop);

    return str_contains($script, '"requireConsent":true') && $config['consent'] === AssignmentService::CONSENT_COOKIE ?: 'not passed on';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
