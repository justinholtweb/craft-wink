<?php
/**
 * Cache-safe delivery, checked in the plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-wink/tests/harness/delivery.php
 *
 * Until 5.1.0 every experiment was chosen while the page rendered, from the visitor's cookie, so
 * behind Blitz or a CDN the first visitor's variant was cached and served to everyone, and every
 * impression was counted against whatever variant that visitor's cookie said. A cache-safe block
 * must be byte-for-byte the same for every visitor, touch no cookie and record nothing; the
 * browser assigns (see tests/js/bucketing.test.js for its parity with the server) and
 * `/wink/track` must count the variant the browser showed. Self-cleaning; settings changes stay
 * in memory.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use justinholtweb\wink\elements\Experiment;
use justinholtweb\wink\enums\GoalType;
use justinholtweb\wink\events\DetectPageCacheEvent;
use justinholtweb\wink\models\Goal;
use justinholtweb\wink\models\Settings;
use justinholtweb\wink\models\Variant;
use justinholtweb\wink\Plugin;
use justinholtweb\wink\services\AssignmentService;
use justinholtweb\wink\services\DeliveryService;
use yii\base\Event;

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
$settings = $wink->getSettings();
$settingsBefore = $settings->toArray();

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$cleanup = [];

register_shutdown_function(function() use (&$cleanup, $settings, $settingsBefore) {
    foreach ($cleanup as $experiment) {
        Craft::$app->getDb()->createCommand()->delete('{{%wink_events}}', ['experimentId' => $experiment->id])->execute();
        Craft::$app->getElements()->deleteElement($experiment, true);
    }
    foreach ($settingsBefore as $key => $value) {
        $settings->$key = $value;
    }
    clearBudget();
});

function clearBudget(): void
{
    foreach (['127.0.0.1', '::1', '::/64', gethostbyname(gethostname())] as $ip) {
        Craft::$app->getCache()->delete(sprintf('wink:budget:%s:%d', sha1($ip), intdiv(time(), 60)));
    }
}

$makeExperiment = static function(string $handle, ?string $deliveryMode = null) use ($wink, &$cleanup): Experiment {
    $experiment = new Experiment([
        'title' => "Wink delivery $handle",
        'handle' => $handle,
        'experimentStatus' => 'running',
        'trafficPercent' => 100,
        'deliveryMode' => $deliveryMode,
    ]);
    Craft::$app->getElements()->saveElement($experiment) or throw new RuntimeException(json_encode($experiment->getErrors()));
    $wink->experiments->saveVariants($experiment->id, [
        new Variant(['handle' => 'control', 'title' => 'Control', 'weight' => 34, 'isControl' => true, 'content' => '<p>CP control</p>']),
        new Variant(['handle' => 'bold', 'title' => 'Bold', 'weight' => 33, 'content' => '<p>CP bold</p>']),
        new Variant(['handle' => 'quiet', 'title' => 'Quiet', 'weight' => 33, 'content' => '<p>CP quiet</p>']),
    ]);
    $wink->experiments->saveGoals($experiment->id, [
        new Goal(['name' => 'Signup', 'handle' => 'signup', 'goalType' => GoalType::CustomEvent, 'isPrimary' => true]),
    ]);

    return $cleanup[] = Experiment::find()->id($experiment->id)->status(null)->one();
};

/**
 * Make the next render a web request from a visitor carrying these cookies (raw, or signed as an
 * earlier Wink wrote them), with a fresh response and a fresh visitor memo.
 */
$asVisitor = static function(?string $raw = null, ?string $signed = null) use ($wink): void {
    $name = $wink->getSettings()->cookieName;
    $key = Craft::$app->getConfig()->getGeneral()->securityKey;
    $_COOKIE = [];
    if ($raw !== null) {
        $_COOKIE[$name] = $raw;
    }
    if ($signed !== null) {
        $_COOKIE[$name] = Craft::$app->getSecurity()->hashData(serialize([$name, $signed]), $key);
    }
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    $request = new craft\web\Request(['cookieValidationKey' => $key, 'enableCookieValidation' => true]);
    $request->setIsConsoleRequest(false);
    Craft::$app->set('request', $request);
    Craft::$app->set('response', new craft\web\Response());
    $wink->set('assignment', new AssignmentService());
};

$render = static function(string $template): string {
    return Craft::$app->getView()->renderString($template, [], View::TEMPLATE_MODE_SITE);
};

$events = static fn(Experiment $e, string $type = 'impression') => (new Query())->from('{{%wink_events}}')
    ->where(['experimentId' => $e->id, 'eventType' => $type])->all();

$responseCookie = static fn() => Craft::$app->getResponse()->getRawCookies()->get($wink->getSettings()->cookieName)
    ?? Craft::$app->getResponse()->getCookies()->get($wink->getSettings()->cookieName);

$experiment = $makeExperiment("wink-cs-$run");
$handle = $experiment->handle;
$tag = <<<TWIG
<main>{% experiment '$handle' %}
  {% variant 'control' %}<h1>Welcome {{ 1 + 1 }}</h1>{% endvariant %}
  {% variant 'bold' %}<h1><strong>Discover</strong></h1>{% endvariant %}
  {% variant 'quiet' %}<h1>Hello</h1>{% endvariant %}
  {% variant 'retired' %}<h1>Not in the experiment</h1>{% endvariant %}
{% endexperiment %}</main>
TWIG;

// Two visitors the server assigns differently, so a visitor-specific render would differ.
$visitorFor = static function(string $want, string $notVisitor = '') use ($wink, $experiment): string {
    for ($i = 0; $i < 1000; $i++) {
        $id = sprintf('%08x-0000-4000-8000-%012d', $i, $i);
        if ($id !== $notVisitor && $wink->assignment->assignVariant($id, $experiment)?->handle === $want) {
            return $id;
        }
    }
    throw new RuntimeException("no visitor for $want");
};
$alice = $visitorFor('bold');
$bob = $visitorFor('quiet');

// -------------------------------------------------------------------------------------------
echo "\nCache-safe rendering\n";

$settings->deliveryMode = Settings::DELIVERY_CACHE_SAFE;

$asVisitor($alice);
$aliceHtml = $render($tag);
$aliceCookie = $responseCookie();
$asVisitor($bob);
$bobHtml = $render($tag);
$asVisitor(null);
$strangerHtml = $render($tag);
$strangerCookie = $responseCookie();

check('two visitors with different variants, and one with no cookie, get byte-identical HTML', function() use ($aliceHtml, $bobHtml, $strangerHtml) {
    return $aliceHtml === $bobHtml && $bobHtml === $strangerHtml ?: "alice:\n$aliceHtml\nbob:\n$bobHtml";
});

check('…which holds every variant the experiment has, rendered, and not the one it doesn’t', function() use ($aliceHtml) {
    return str_contains($aliceHtml, '<h1>Welcome 2</h1>') && str_contains($aliceHtml, '<strong>Discover</strong>')
        && str_contains($aliceHtml, '<h1>Hello</h1>') && !str_contains($aliceHtml, 'Not in the experiment') ?: $aliceHtml;
});

check('…with nothing about the visitor in it, and no visitor chosen', function() use ($aliceHtml, $alice, $bob) {
    return !str_contains($aliceHtml, $alice) && !str_contains($aliceHtml, $bob)
        && !preg_match('~<div[^>]*(data-wink-variant=|data-wink-chosen|data-wink-vid)~', $aliceHtml) ?: 'visitor-specific markup found';
});

check('…hiding every variant until the runtime chooses, and showing the control without script', function() use ($aliceHtml) {
    $styleAt = strpos($aliceHtml, '[data-wink-option]{display:none}');
    $blockAt = strpos($aliceHtml, 'data-wink-delivery="cache-safe"');
    $scriptAt = strpos($aliceHtml, 'WinkDelivery.resolve(');

    return $styleAt !== false && $blockAt !== false && $scriptAt !== false && $styleAt < $blockAt && $blockAt < $scriptAt
        && preg_match('~<noscript><style>[^<]*\[data-wink-fallback\]\{display:contents\}~', $aliceHtml)
        && preg_match('~<div data-wink-option="control" data-wink-fallback>~', $aliceHtml)
        && substr_count($aliceHtml, 'data-wink-fallback>') === 1 ?: $aliceHtml;
});

check('…and carrying the config the runtime assigns from: experiment ID, traffic, weights in order, cookie', function() use ($aliceHtml, $experiment, $settings) {
    preg_match('~data-wink-config="([^"]+)"~', $aliceHtml, $m);
    $config = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
    $expected = [
        'id' => $experiment->id, 'traffic' => 100,
        'variants' => [['h' => 'control', 'w' => 34, 'c' => true], ['h' => 'bold', 'w' => 33, 'c' => false], ['h' => 'quiet', 'w' => 33, 'c' => false]],
        'cookie' => $settings->cookieName, 'days' => $settings->cookieDuration,
    ];

    return array_intersect_key($config ?? [], $expected) == $expected && ($config['track']['url'] ?? null) === '/wink/track' ?: json_encode($config);
});

check('a cache-safe render sets no cookie and records no impression', function() use ($aliceCookie, $strangerCookie, $events, $experiment) {
    return $aliceCookie === null && $strangerCookie === null && count($events($experiment)) === 0
        ?: 'cookie ' . json_encode([$aliceCookie?->value, $strangerCookie?->value]) . ', impressions ' . count($events($experiment));
});

check('the inlined runtime is the shipped file, and can’t close its own script tag', function() use ($wink, $aliceHtml) {
    $runtime = $wink->delivery->getRuntime();

    return str_contains($aliceHtml, $runtime) && str_contains($runtime, 'function assign(') && stripos($runtime, '</script') === false ?: 'runtime missing';
});

check('winkVariant() is cache-safe too: every variant’s content, the same for every visitor', function() use ($asVisitor, $render, $handle, $alice, $bob) {
    $asVisitor($alice);
    $a = $render("{{ winkVariant('$handle') }}");
    $asVisitor($bob);
    $b = $render("{{ winkVariant('$handle') }}");

    return $a === $b && str_contains($a, '<p>CP control</p>') && str_contains($a, '<p>CP bold</p>') && str_contains($a, '<p>CP quiet</p>') ?: $a;
});

check('variant handles and experiment handles are escaped in the markup', function() use ($wink, $experiment) {
    $hostile = clone $experiment;
    $hostile->handle = 'x" onmouseover="alert(1)';
    $hostile->setVariants([new Variant(['handle' => '"><img src=x onerror=alert(1)>', 'weight' => 1, 'isControl' => true])]);
    $html = $wink->delivery->renderCacheSafe($hostile, ['"><img src=x onerror=alert(1)>' => 'body']);

    return !str_contains($html, '<img') && !str_contains($html, '" onmouseover') && str_contains($html, '>body</div>') ?: $html;
});

// -------------------------------------------------------------------------------------------
echo "\nServer-side rendering (the comparison)\n";

$settings->deliveryMode = Settings::DELIVERY_SERVER;

check('server-side, the same two visitors get different HTML and an impression each', function() use ($asVisitor, $render, $tag, $alice, $bob, $events, $experiment) {
    $asVisitor($alice);
    $a = $render($tag);
    $asVisitor($bob);
    $b = $render($tag);
    $recorded = array_column($events($experiment), 'visitorId');

    return $a !== $b && str_contains($a, 'data-wink-variant="bold"') && str_contains($b, 'data-wink-variant="quiet"')
        && in_array($alice, $recorded, true) && in_array($bob, $recorded, true) ?: "a: $a\nb: $b";
});

check('server-side, a new visitor is issued a raw cookie script can read', function() use ($asVisitor, $render, $tag, $responseCookie) {
    $asVisitor(null);
    $render($tag);
    $cookie = $responseCookie();
    $raw = Craft::$app->getResponse()->getRawCookies()->get(Plugin::getInstance()->getSettings()->cookieName);

    return $raw !== null && $cookie === $raw && AssignmentService::isValidVisitorId($raw->value) && $raw->httpOnly === false
        && $raw->sameSite === yii\web\Cookie::SAME_SITE_LAX && $raw->path === '/' ?: json_encode($cookie);
});

check('a signed cookie from an earlier Wink is honoured, and rewritten raw', function() use ($asVisitor, $wink, $alice) {
    $asVisitor(null, $alice);
    $id = $wink->assignment->getVisitorId();
    $raw = Craft::$app->getResponse()->getRawCookies()->get($wink->getSettings()->cookieName);

    return $id === $alice && $raw?->value === $alice ?: "id $id, reissued " . json_encode($raw?->value);
});

check('a cookie value Wink wouldn’t mint is replaced, not trusted', function() use ($asVisitor, $wink) {
    $asVisitor('<script>alert(1)</script>');
    $id = $wink->assignment->getVisitorId();

    return AssignmentService::isValidVisitorId($id) && $id !== '<script>alert(1)</script>' ?: $id;
});

// -------------------------------------------------------------------------------------------
echo "\nChoosing the mode\n";

check('an experiment’s own choice beats the plugin setting, both ways', function() use ($makeExperiment, $run, $wink, $settings) {
    $forcedServer = $makeExperiment("wink-cs-$run-server", Settings::DELIVERY_SERVER);
    $forcedSafe = $makeExperiment("wink-cs-$run-safe", Settings::DELIVERY_CACHE_SAFE);

    $settings->deliveryMode = Settings::DELIVERY_CACHE_SAFE;
    $a = $wink->delivery->isCacheSafe($forcedServer);
    $settings->deliveryMode = Settings::DELIVERY_SERVER;
    $b = $wink->delivery->isCacheSafe($forcedSafe);

    return $a === false && $b === true && $forcedSafe->deliveryMode === 'cacheSafe' ?: json_encode([$a, $b, $forcedSafe->deliveryMode]);
});

check('an experiment won’t save with a delivery mode Wink doesn’t have', function() use ($experiment) {
    $copy = Experiment::find()->id($experiment->id)->status(null)->one();
    $copy->deliveryMode = 'sometimes';

    return !Craft::$app->getElements()->saveElement($copy) && $copy->hasErrors('deliveryMode') ?: 'saved';
});

check('…nor the plugin setting', function() {
    $model = new Settings(['deliveryMode' => 'sometimes']);

    return !$model->validate() && $model->hasErrors('deliveryMode') ?: 'valid';
});

check('automatic is server-side when no full-page cache is detected (Blitz isn’t installed here)', function() use ($wink, $settings, $experiment) {
    $settings->deliveryMode = Settings::DELIVERY_AUTO;
    $wink->set('delivery', new DeliveryService());

    return $wink->delivery->getPageCache() === null && $wink->delivery->isCacheSafe($experiment) === false
        && $wink->delivery->getServerSideWarning() === null ?: 'detected ' . $wink->delivery->getPageCache();
});

$withPageCache = static function(string $name) use ($wink): void {
    $wink->set('delivery', new DeliveryService());
    $wink->delivery->on(DeliveryService::EVENT_DETECT_PAGE_CACHE, function(DetectPageCacheEvent $event) use ($name) {
        $event->pageCache = $name;
    });
};

check('…and cache-safe when one is', function() use ($withPageCache, $wink, $settings, $experiment) {
    $settings->deliveryMode = Settings::DELIVERY_AUTO;
    $withPageCache('Blitz');

    return $wink->delivery->getPageCache() === 'Blitz' && $wink->delivery->isCacheSafe($experiment) === true ?: 'not cache-safe';
});

check('the control panel warns about server-side delivery behind a full-page cache, and only then', function() use ($withPageCache, $wink, $settings, $makeExperiment, $run, $experiment) {
    $withPageCache('Blitz');
    $settings->deliveryMode = Settings::DELIVERY_SERVER;
    $global = $wink->delivery->getServerSideWarning();
    $forced = $makeExperiment("wink-cs-$run-warn", Settings::DELIVERY_SERVER);
    $perExperiment = $wink->delivery->getServerSideWarning($forced);

    $settings->deliveryMode = Settings::DELIVERY_CACHE_SAFE;
    $quietGlobal = $wink->delivery->getServerSideWarning();
    $quietFollowing = $wink->delivery->getServerSideWarning($experiment);

    $wink->set('delivery', new DeliveryService());
    $settings->deliveryMode = Settings::DELIVERY_SERVER;
    $noCache = $wink->delivery->getServerSideWarning();

    return str_contains((string)$global, 'Blitz') && str_contains((string)$perExperiment, 'Blitz')
        && $quietGlobal === null && $quietFollowing === null && $noCache === null
        ?: json_encode(compact('global', 'perExperiment', 'quietGlobal', 'quietFollowing', 'noCache'));
});

// -------------------------------------------------------------------------------------------
echo "\nTracking a cache-safe visitor over HTTP\n";

// The harness's settings are as saved (Wink's default delivery is automatic, and there's no
// Blitz here) — the endpoint is the same in either mode, so it's exercised as the browser would.
$post = static function(?string $rawCookie, array $events, ?string $signed = null): array {
    $jar = new CookieJar();
    $name = Plugin::getInstance()->getSettings()->cookieName;
    if ($rawCookie !== null) {
        $jar->setCookie(new SetCookie(['Name' => $name, 'Value' => $rawCookie, 'Domain' => 'localhost', 'Path' => '/']));
    }
    if ($signed !== null) {
        $value = Craft::$app->getSecurity()->hashData(serialize([$name, $signed]), Craft::$app->getConfig()->getGeneral()->securityKey);
        // As setcookie() sends it: URL-encoded, since a serialized value carries `;` and `"`.
        $jar->setCookie(new SetCookie(['Name' => $name, 'Value' => rawurlencode($value), 'Domain' => 'localhost', 'Path' => '/']));
    }
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => $jar, 'http_errors' => false]);
    $response = $http->post('index.php?p=wink/track', [
        'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        'body' => json_encode(['events' => $events]),
    ]);

    return [(int)(json_decode((string)$response->getBody(), true)['recorded'] ?? -1), $response->getHeader('Set-Cookie')];
};

$variantHandle = static function(Experiment $e, string $visitorId): ?string {
    $row = (new Query())->from('{{%wink_events}} e')->innerJoin('{{%wink_variants}} v', '[[v.id]] = [[e.variantId]]')
        ->where(['e.experimentId' => $e->id, 'e.visitorId' => $visitorId, 'e.eventType' => 'impression'])->select('v.handle')->scalar();

    return $row === false ? null : $row;
};

$httpExperiment = $makeExperiment("wink-cs-$run-http", Settings::DELIVERY_CACHE_SAFE);
$carol = sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 0, 3), substr(bin2hex(random_bytes(2)), 0, 3), bin2hex(random_bytes(6)));

check('the impression the browser sends is counted against the variant the browser showed', function() use ($post, $httpExperiment, $carol, $wink, $variantHandle) {
    clearBudget();
    // What the runtime would reveal for this cookie (tests/js/bucketing.test.js holds it to this).
    $shown = $wink->assignment->assignVariant($carol, $httpExperiment)->handle;
    [$recorded] = $post($carol, [['experiment' => $httpExperiment->handle, 'variant' => $shown, 'vid' => $carol, 'type' => 'impression']]);

    return $recorded === 1 && $variantHandle($httpExperiment, $carol) === $shown ?: "recorded $recorded as " . $variantHandle($httpExperiment, $carol) . ", shown $shown";
});

check('…and the conversion after it', function() use ($post, $httpExperiment, $carol) {
    clearBudget();
    [$recorded] = $post($carol, [['experiment' => $httpExperiment->handle, 'type' => 'conversion', 'goal' => 'signup', 'vid' => $carol]]);

    return $recorded === 1 ?: "recorded $recorded";
});

check('an event assigned to a different visitor than the cookie names isn’t counted', function() use ($post, $httpExperiment, $carol) {
    clearBudget();
    $other = '11111111-2222-4333-8444-555555555555';
    [$recorded] = $post($other, [['experiment' => $httpExperiment->handle, 'type' => 'impression', 'vid' => $carol]]);
    [$noCookie] = $post(null, [['experiment' => $httpExperiment->handle, 'type' => 'impression', 'vid' => $carol]]);

    return $recorded === 0 && $noCookie === 0 ?: "recorded $recorded / $noCookie";
});

check('the endpoint hands a visitor without a cookie a raw one script can read', function() use ($post, $httpExperiment) {
    clearBudget();
    [, $cookies] = $post(null, [['experiment' => $httpExperiment->handle, 'type' => 'impression']]);
    $name = Plugin::getInstance()->getSettings()->cookieName;
    $ours = array_values(array_filter($cookies, fn($c) => str_starts_with($c, "$name=")));

    return count($ours) === 1 && preg_match("~^$name=[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12};~", $ours[0])
        && stripos($ours[0], 'httponly') === false && stripos($ours[0], 'samesite=lax') !== false ?: json_encode($cookies);
});

check('a visitor with an earlier Wink’s signed cookie keeps their ID, now raw', function() use ($post, $httpExperiment) {
    clearBudget();
    $dave = 'aaaaaaaa-bbbb-4ccc-8ddd-' . bin2hex(random_bytes(6));
    [$recorded, $cookies] = $post(null, [['experiment' => $httpExperiment->handle, 'type' => 'impression']], $dave);
    $name = Plugin::getInstance()->getSettings()->cookieName;

    return $recorded === 1 && in_array(true, array_map(fn($c) => str_starts_with($c, "$name=$dave;"), $cookies), true)
        && (new Query())->from('{{%wink_events}}')->where(['experimentId' => $httpExperiment->id, 'visitorId' => $dave])->exists()
        ?: "recorded $recorded, " . json_encode($cookies);
});

// -------------------------------------------------------------------------------------------
echo "\nControl panel\n";

$admin = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$csrf = static fn() => (string)(json_decode((string)$admin->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
$admin->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf()]]);

check('the settings page and the experiment editor both offer the delivery choice', function() use ($admin, $experiment) {
    $settingsPage = (string)$admin->get('index.php?p=admin/wink/settings')->getBody();
    $editPage = (string)$admin->get("index.php?p=admin/wink/experiments/{$experiment->id}")->getBody();

    return str_contains($settingsPage, 'name="deliveryMode"') && str_contains($settingsPage, 'value="cacheSafe"')
        && str_contains($editPage, 'name="deliveryMode"') && str_contains($editPage, 'Use the plugin setting')
        ?: 'field missing: ' . json_encode([str_contains($settingsPage, 'name="deliveryMode"'), str_contains($editPage, 'name="deliveryMode"'), substr(strip_tags($editPage), 0, 600)]);
});

$saveDelivery = static function(string $mode) use ($admin, $csrf, $experiment): ?string {
    $admin->post('index.php?p=admin/actions/wink/experiments/save', ['form_params' => [
        'experimentId' => $experiment->id, 'title' => $experiment->title, 'handle' => $experiment->handle,
        'trafficPercent' => 100, 'deliveryMode' => $mode, 'CRAFT_CSRF_TOKEN' => $csrf(),
    ]]);

    return Experiment::find()->id($experiment->id)->status(null)->one()?->deliveryMode;
};

check('an experiment saved from the editor keeps its delivery choice; a made-up one is refused', function() use ($saveDelivery) {
    $saved = $saveDelivery('cacheSafe');
    $bogus = $saveDelivery('sometimes');
    $cleared = $saveDelivery('');

    return $saved === 'cacheSafe' && $bogus === 'cacheSafe' && $cleared === null ?: json_encode([$saved, $bogus, $cleared]);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
