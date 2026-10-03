<?php
/**
 * What a limited CP user and an anonymous visitor can reach in Eye — checked over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-eye/tests/integration/trust.php
 *
 * The Embed field calls `resolve`, `check` and `preview` from an entry's edit screen, where the
 * author may have no access to the embed library, so those answer any CP user; the library itself
 * does not. And the public proxy route is rate limited per address. Each refusal is paired with
 * something the same user *is* allowed, so a pass means "refused", not "broken".
 *
 * Idempotent and self-cleaning: the user and the rate-limit counters are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\eye\controllers\ProxyController;
use justinholtweb\eye\Plugin;

Craft::$app->getPlugins()->loadPlugins();

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

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'eye-' . bin2hex(random_bytes(12));
$user = null;
$embed = null;

// The keys RateLimit uses for this minute, for whichever loopback address the web side sees.
$rateKeys = static fn() => array_map(
    static fn(string $ip) => sprintf('eye:rate:proxy:%s:%d', sha1($ip), intdiv(time(), 60)),
    ['127.0.0.1', '::1'],
);

register_shutdown_function(function() use (&$user, &$embed, $rateKeys) {
    if ($embed !== null) {
        Craft::$app->getElements()->deleteElement($embed, true);
    }

    foreach ($rateKeys() as $key) {
        Craft::$app->getCache()->delete($key);
    }

    Craft::$app->getCache()->delete(sprintf('eye:rate:proxy:*:%d', intdiv(time(), 60)));

    if ($user !== null) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

$user = new User();
$user->username = "eye-author-$run";
$user->email = "eye-author-$run@example.com";
$user->newPassword = $password;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp']);

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$login = $http->post('index.php?p=actions/users/login', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
]);

if ($login->getStatusCode() !== 200) {
    echo "Could not sign in as the test user: {$login->getStatusCode()}\n";
    exit(1);
}

$post = static fn(string $action, array $params = []) => $http->post("index.php?p=admin/actions/eye/embeds/$action", [
    'headers' => ['Accept' => 'application/json'],
    'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
]);

echo "\nAn author with CP access and nothing of Eye's\n";

check('the Embed field can resolve a URL', function() use ($post) {
    $response = $post('resolve', ['url' => 'https://youtu.be/dQw4w9WgXcQ']);
    $data = json_decode((string)$response->getBody(), true);

    return $response->getStatusCode() === 200 && ($data['provider'] ?? null) === 'youtube'
        ?: 'status ' . $response->getStatusCode();
});

check('the Embed field can preview, and the preview is sanitised', function() use ($post) {
    $response = $post('preview', [
        'url' => 'https://example.com/',
        'options' => ['fallback' => '<b>ok</b><img src=x onerror=alert(1)>'],
    ]);
    $html = (string)(json_decode((string)$response->getBody(), true)['html'] ?? '');

    return $response->getStatusCode() === 200 && str_contains($html, '<b>ok</b>') && !str_contains($html, 'onerror')
        ?: 'status ' . $response->getStatusCode() . ', html ' . substr($html, 0, 200);
});

check('the embed library stays closed', function() use ($post, $http) {
    $list = $post('list')->getStatusCode();
    $index = $http->get('admin/eye/embeds')->getStatusCode();

    return $list === 403 && $index === 403 ?: "list $list, index $index";
});

check('creating a library embed is refused', function() use ($post) {
    $status = $post('quick-create', ['url' => 'https://example.com/eye-trust'])->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('a limited author cannot preview a proxied embed', function() use ($post) {
    $response = $post('preview', ['url' => 'https://example.com/', 'options' => ['mode' => 'proxy', 'injectCss' => 'p{}']]);
    $data = json_decode((string)$response->getBody(), true);

    return $response->getStatusCode() === 200 && ($data['html'] ?? null) === '' && !empty($data['error'])
        ?: 'status ' . $response->getStatusCode() . ', html ' . substr((string)($data['html'] ?? ''), 0, 200);
});

echo "\nThe same author, given read access to the library\n";

check('an existing embed opens for editing', function() use ($http, $user, $run, &$embed) {
    // A Twig error on this screen once only showed for embeds that already existed — a new one
    // skipped the branch — so this opens a saved one.
    $embed = Plugin::getInstance()->embeds->createFromUrl('https://vimeo.com/76979871', ['handle' => "eye-trust-$run"]);
    Craft::$app->getElements()->saveElement($embed, false);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', 'accessplugin-eye', strtolower(Plugin::PERMISSION_VIEW)]);

    $response = $http->get("admin/eye/embeds/$embed->id");
    $body = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($body, 'id="eye-preview"') && str_contains($body, $embed->embedCode)
        ?: 'status ' . $response->getStatusCode() . (str_contains($body, 'Twig') ? ', a Twig error' : '');
});

echo "\nThe public proxy route\n";

check('an address over its budget is refused before anything else happens', function() use ($rateKeys) {
    foreach ($rateKeys() as $key) {
        Craft::$app->getCache()->set($key, ProxyController::REQUESTS_PER_MINUTE, 120);
    }

    $status = (new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]))
        ->get('eye/proxy/00000000-0000-0000-0000-000000000000')->getStatusCode();

    return $status === 429 ?: "status $status";
});

check('a forged X-Forwarded-For does not buy a fresh budget', function() use ($rateKeys) {
    foreach ($rateKeys() as $key) {
        Craft::$app->getCache()->set($key, ProxyController::REQUESTS_PER_MINUTE, 120);
    }

    $status = (new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]))
        ->get('eye/proxy/00000000-0000-0000-0000-000000000000', ['headers' => ['X-Forwarded-For' => '203.0.113.' . random_int(1, 254)]])
        ->getStatusCode();

    return $status === 429 ?: "status $status";
});

check('an address under its budget gets the ordinary answer', function() use ($rateKeys) {
    foreach ($rateKeys() as $key) {
        Craft::$app->getCache()->delete($key);
    }

    // 403 with the proxy off, 404 for a uid that does not exist — either way, not 429.
    $status = (new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]))
        ->get('eye/proxy/00000000-0000-0000-0000-000000000000')->getStatusCode();

    return in_array($status, [403, 404], true) ?: "status $status";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
