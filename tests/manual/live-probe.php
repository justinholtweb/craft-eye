<?php
// Live network probe — not part of the check suite (it needs the internet).
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\Plugin;

$plugin = Plugin::getInstance();
$s = $plugin->getSettings();
$s->proxyEnabled = true;
$s->allowedHosts = ['example.com', 'github.com', '*.wikipedia.org', 'httpbin.org'];

echo "--- framability probes (no allowlist needed) ---\n";
foreach (['https://example.com/', 'https://github.com/', 'https://en.wikipedia.org/wiki/Iframe'] as $url) {
    try {
        $r = $plugin->framability->check($url, false);
        printf("%-45s %-12s %s | %s\n", $url, $r->status, $r->header ?: '(no header)', substr($r->message, 0, 70));
    } catch (Throwable $e) {
        printf("%-45s ERROR %s\n", $url, $e->getMessage());
    }
}

echo "\n--- refusals ---\n";
foreach ([
    'http://169.254.169.254/latest/meta-data/' => 'cloud metadata',
    'http://127.0.0.1/' => 'loopback',
    'https://not-on-the-list.example/' => 'off allowlist',
] as $url => $why) {
    try {
        $plugin->fetcher->fetch($url);
        printf("  !! %-20s FETCHED — %s\n", $why, $url);
    } catch (Throwable $e) {
        printf("  ok %-20s refused: %s\n", $why, $e->getMessage());
    }
}

echo "\n--- real proxy fetch + extract ---\n";
try {
    $result = $plugin->proxy->document('https://example.com/', EmbedOptions::fromArray(['extract' => 'h1, p']));
    printf("  title: %s\n  extracted nodes: %d\n  bytes: %d\n", $result->title, $result->extracted, strlen($result->html));
    printf("  has <base>: %s | has <script>: %s\n",
        str_contains($result->html, '<base href') ? 'yes' : 'no',
        str_contains($result->html, '<script') ? 'yes' : 'no');
    echo "  body excerpt: " . trim(preg_replace('/\s+/', ' ', strip_tags($result->html))) . "\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- inline fragment ---\n";
try {
    $result = $plugin->proxy->fragment('https://example.com/', EmbedOptions::fromArray(['extract' => 'h1']));
    echo "  " . trim($result->html) . "\n";
} catch (Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- redirect handling (allowlist re-checked per hop) ---\n";
// wikipedia.org redirects to en.wikipedia.org, so this is a real cross-host hop.
$s->allowedHosts = ['*.wikipedia.org', 'wikipedia.org'];
try {
    $r = $plugin->fetcher->fetch('https://wikipedia.org/wiki/Iframe');
    echo "  ok followed a cross-host redirect to an allowed host: $r->url (status $r->status)\n";
} catch (Throwable $e) {
    echo "  !! " . $e->getMessage() . "\n";
}

// Now allow only the apex. The hop to en.wikipedia.org must be refused.
$s->allowedHosts = ['wikipedia.org'];
try {
    $r = $plugin->fetcher->fetch('https://wikipedia.org/wiki/Iframe');
    echo "  !! followed a redirect to a host that is not allowed: $r->url\n";
} catch (Throwable $e) {
    echo "  ok redirect to a disallowed host refused: " . $e->getMessage() . "\n";
}
$s->allowedHosts = ['example.com'];

echo "\n--- size cap ---\n";
$s->proxyMaxBytes = 500;
try {
    $plugin->proxy->forget('https://example.com/', new EmbedOptions());
    $plugin->fetcher->fetch('https://example.com/');
    echo "  !! oversized response was accepted\n";
} catch (Throwable $e) {
    echo "  ok " . $e->getMessage() . "\n";
}
