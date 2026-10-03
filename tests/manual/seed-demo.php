<?php

// Sets up demo embeds and turns the proxy on in the harness. Not part of the check suite.
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\Plugin;

$plugin = Plugin::getInstance();

Craft::$app->getPlugins()->savePluginSettings($plugin, [
    'proxyEnabled' => true,
    'allowedHosts' => ['example.com', '*.wikipedia.org'],
    'privacyMode' => true,
    'checkFramability' => true,
]);

// Project config writes are buffered until the request ends, and a bare console script has no
// request end — so without this the settings silently vanish while the call returns true.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$demos = [
    ['handle' => 'demo-video', 'title' => 'Demo video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'options' => []],
    ['handle' => 'demo-consent', 'title' => 'Demo consent video', 'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'options' => ['loading' => 'click', 'rememberConsent' => true]],
    ['handle' => 'demo-map', 'title' => 'Demo map', 'url' => 'https://www.openstreetmap.org/#map=15/51.5074/-0.1278', 'options' => ['caption' => 'Central London']],
    ['handle' => 'demo-proxy', 'title' => 'Demo proxied page', 'url' => 'https://example.com/', 'options' => ['mode' => 'proxy', 'minHeight' => 200]],
    ['handle' => 'demo-inline', 'title' => 'Demo inlined fragment', 'url' => 'https://example.com/', 'options' => ['mode' => 'inline', 'extract' => 'h1, p']],
    ['handle' => 'demo-blocked', 'title' => 'Demo blocked page', 'url' => 'https://github.com/', 'options' => ['timeout' => 4000]],
];

foreach ($demos as $demo) {
    $embed = Embed::find()->handle($demo['handle'])->status(null)->one() ?? new Embed();
    $embed->handle = $demo['handle'];
    $embed->title = $demo['title'];
    $embed->url = $demo['url'];
    $embed->setOptions($plugin->getSettings()->getDefaultEmbedOptions()->merge($demo['options']));

    if ($plugin->embeds->saveEmbed($embed)) {
        printf("  %-16s %-8s %s\n", $embed->handle, $embed->mode, $embed->uid);
    } else {
        printf("  %-16s FAILED %s\n", $demo['handle'], json_encode($embed->getErrors()));
    }
}

echo "\nframability:\n";
foreach ($plugin->embeds->checkAll(0) as $status => $count) {
    echo "  $status: $count\n";
}
