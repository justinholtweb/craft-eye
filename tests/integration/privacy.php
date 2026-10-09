<?php
/**
 * Consent-manager integration and self-hosted posters.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-eye/tests/integration/privacy.php
 *
 * The two halves of "nothing reaches the third party before the reader agrees": the consent card
 * shows a poster served from this site (or none), and it opens by itself only when the site's
 * consent manager says so. The browser half — the runtime's adapters — is covered by
 * `node --test tests/js/runtime.test.mjs`; this covers everything the server decides.
 *
 * No network needed: poster downloads are pinned to the harness's own web server, which serves
 * test images written into the web root for the duration of the run.
 *
 * Idempotent and self-cleaning: embeds, assets, the test folder, poster rows, queued jobs and the
 * web-root files are removed on the way out, and settings are only ever changed in memory.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\jobs\DownloadPoster;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\Settings;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\records\PosterRecord;
use justinholtweb\eye\services\Consent;
use justinholtweb\eye\services\Fetcher;
use justinholtweb\eye\services\Posters;

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

function section(string $title): void
{
    echo "\n$title\n";
}

/** The runtime config a rendered embed hands the front end. */
function runtimeConfig(string $html): array
{
    if (!preg_match('/data-eye="([^"]*)"/', $html, $m)) {
        return [];
    }

    return json_decode(html_entity_decode($m[1], ENT_QUOTES), true) ?: [];
}

/** The markup a reader's browser acts on before consent: everything outside the inert `<template>`. */
function liveMarkup(string $html): string
{
    return (string)preg_replace('~<template\b.*?</template>~s', '', $html);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$original = $settings->toArray();
$originalFetcher = $plugin->get('fetcher');
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$webroot = Craft::getAlias('@webroot');
$created = ['embeds' => [], 'assets' => [], 'files' => [], 'hashes' => []];
$folderPath = "eye-posters-test-$run";
$volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;

// Every poster download in this run lands on the harness's own web server.
$pinned = new class() extends Fetcher {
    protected function addressesFor(string $host): array
    {
        return ['127.0.0.1'];
    }
};

register_shutdown_function(function() use (&$created, $plugin, $settings, $original, $originalFetcher, $volume, $folderPath) {
    $plugin->set('fetcher', $originalFetcher);
    $settings->setAttributes($original, false);

    foreach ($created['embeds'] as $id) {
        if ($embed = Embed::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($embed, true);
        }
    }

    foreach (array_unique($created['assets']) as $id) {
        if ($asset = Asset::find()->id($id)->status(null)->one()) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }
    }

    if ($volume && ($folder = Craft::$app->getAssets()->findFolder(['volumeId' => $volume->id, 'path' => "$folderPath/"]))) {
        Craft::$app->getAssets()->deleteFoldersByIds($folder->id);
    }

    if ($created['hashes']) {
        Craft::$app->getDb()->createCommand()->delete(PosterRecord::TABLE, ['urlHash' => array_unique($created['hashes'])])->execute();
    }

    // Jobs this run queued, whether or not the runner got to them.
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}', ['like', 'description', 'Downloading an embed poster'])->execute();

    foreach ($created['files'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

/** A test file in the web root, served by nginx for this run. */
$serve = function(string $name, string $bytes) use ($webroot, &$created): string {
    $path = "$webroot/$name";
    file_put_contents($path, $bytes);
    $created['files'][] = $path;

    return $name;
};

$png = function(int $w = 64, int $h = 36): string {
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagepng($image);

    return (string)ob_get_clean();
};

$track = function(string $url) use (&$created): string {
    $created['hashes'][] = Posters::hash($url);

    return $url;
};

$youtube = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

// -----------------------------------------------------------------------------
section('Consent categories');

check('a click-to-load YouTube embed waits for marketing', function() use ($plugin, $youtube) {
    $config = runtimeConfig((string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click'])));

    return ($config['consent']['category'] ?? null) === 'marketing' && ($config['consent']['provider'] ?? null) === 'youtube'
        ?: 'got ' . json_encode($config['consent'] ?? null);
});

check('a tool provider waits for preferences', function() use ($plugin) {
    $config = runtimeConfig((string)$plugin->renderer->renderUrl('https://codepen.io/team/pen/abcdef', EmbedOptions::fromArray(['loading' => 'click'])));

    return ($config['consent']['category'] ?? null) === 'preferences' ?: 'got ' . json_encode($config['consent'] ?? null);
});

check('an unknown page waits for marketing, the cautious default', function() use ($plugin) {
    $config = runtimeConfig((string)$plugin->renderer->renderUrl('https://example.com/widget', EmbedOptions::fromArray(['loading' => 'click'])));

    return ($config['consent']['category'] ?? null) === 'marketing' ?: 'got ' . json_encode($config['consent'] ?? null);
});

check('an embed’s own category wins over its provider’s', function() use ($plugin, $youtube) {
    $config = runtimeConfig((string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click', 'consentCategory' => 'analytics'])));

    return ($config['consent']['category'] ?? null) === 'analytics' ?: 'got ' . json_encode($config['consent'] ?? null);
});

check('a category that is not one of the four is dropped', function() {
    $options = EmbedOptions::fromArray(['consentCategory' => 'everything']);

    return $options->consentCategory === '' && EmbedOptions::fromArray(['consentCategory' => 'preferences'])->consentCategory === 'preferences'
        ?: "kept “{$options->consentCategory}”";
});

check('a reference tag cannot choose the category (necessary would skip asking)', function() use ($plugin, $run, &$created) {
    $embed = $plugin->embeds->createFromUrl('https://www.youtube.com/watch?v=aqz-KE-bpKQ', ['handle' => "eye-privacy-$run"]);
    $embed->handle = "eye-privacy-$run";

    if (!Craft::$app->getElements()->saveElement($embed)) {
        return 'could not save: ' . json_encode($embed->getErrors());
    }

    $created['embeds'][] = $embed->id;
    $html = Craft::$app->getElements()->parseRefs("{eye:eye-privacy-$run:render(click,consentCategory=necessary)}");
    $config = runtimeConfig($html);

    return ($config['consent']['category'] ?? null) === 'marketing' ?: 'got ' . json_encode($config['consent'] ?? null);
});

check('an embed that is not click-to-load carries no consent config', function() use ($plugin, $youtube) {
    return !isset(runtimeConfig((string)$plugin->renderer->renderUrl($youtube, new EmbedOptions()))['consent']);
});

// -----------------------------------------------------------------------------
section('Which consent manager');

check('Automatic defers to Toss exactly when Toss says its consent kit is on', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->consentManager = 'auto';
    $consent = new Consent();

    $expected = false;

    if (Craft::$app->getPlugins()->isPluginEnabled('toss')) {
        $toss = Craft::$app->getPlugins()->getPlugin('toss');
        $expected = $toss->get('consent')->isActive();
    }

    return $consent->tossIsActive() === $expected && $consent->manager() === ($expected ? 'toss' : 'auto')
        ?: 'tossIsActive ' . var_export($consent->tossIsActive(), true) . ', expected ' . var_export($expected, true);
});

check('with Toss in charge, Automatic becomes toss; an explicit choice is kept', function() use ($plugin) {
    $consent = new Consent();
    $active = new ReflectionProperty($consent, 'tossActive');
    $active->setAccessible(true);
    $active->setValue($consent, true);

    $plugin->getSettings()->consentManager = 'auto';
    $auto = $consent->manager();
    $plugin->getSettings()->consentManager = 'cookiebot';
    $explicit = $consent->manager();
    $plugin->getSettings()->consentManager = 'none';
    $none = $consent->manager();
    $plugin->getSettings()->consentManager = 'auto';

    return [$auto, $explicit, $none] === ['toss', 'cookiebot', 'none'] ?: 'got ' . json_encode([$auto, $explicit, $none]);
});

check('without Toss, Automatic stays automatic for the browser to resolve', function() use ($plugin) {
    $consent = new Consent();
    $active = new ReflectionProperty($consent, 'tossActive');
    $active->setAccessible(true);
    $active->setValue($consent, false);
    $plugin->getSettings()->consentManager = 'auto';

    return $consent->manager() === 'auto';
});

check('the manager reaches the runtime config, and is the same for every visitor', function() use ($plugin, $youtube) {
    $plugin->getSettings()->consentManager = 'klaro';
    $_COOKIE['toss_consent'] = 'anything';
    $first = (string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click']), ['id' => 'same']);
    unset($_COOKIE['toss_consent']);
    $second = (string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click']), ['id' => 'same']);
    $plugin->getSettings()->consentManager = 'auto';

    return (runtimeConfig($first)['consent']['manager'] ?? null) === 'klaro' && $first === $second
        ?: 'differs, or manager is ' . json_encode(runtimeConfig($first)['consent']['manager'] ?? null);
});

check('a nonsense manager setting is refused on validation', function() {
    $settings = new Settings(['consentManager' => 'magic']);

    return !$settings->validate(['consentManager']) ?: 'accepted';
});

// -----------------------------------------------------------------------------
section('Hold every third-party embed for consent');

check('a lazy third-party embed becomes a consent card', function() use ($plugin, $youtube) {
    $plugin->getSettings()->consentForAllEmbeds = true;
    $html = (string)$plugin->renderer->renderUrl($youtube, new EmbedOptions());
    $plugin->getSettings()->consentForAllEmbeds = false;
    $live = liveMarkup($html);

    return str_contains($html, 'data-eye-consent') && !str_contains($live, '<iframe') && (runtimeConfig($html)['loading'] ?? null) === 'click'
        ?: 'got ' . substr($html, 0, 300);
});

check('a frame on this site is left alone', function() use ($plugin) {
    $plugin->getSettings()->consentForAllEmbeds = true;
    $html = (string)$plugin->renderer->renderUrl(rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/') . '/eye-test', new EmbedOptions());
    $plugin->getSettings()->consentForAllEmbeds = false;

    return !str_contains($html, 'data-eye-consent') ?: 'a same-origin frame got a card';
});

check('off, an embed keeps its own loading', function() use ($plugin, $youtube) {
    return !str_contains((string)$plugin->renderer->renderUrl($youtube, new EmbedOptions()), 'data-eye-consent');
});

// -----------------------------------------------------------------------------
section('Posters — what the card shows');

check('YouTube declares its poster hosts, and they are the only registry hosts', function() use ($plugin) {
    $hosts = $plugin->providers->posterHosts();
    sort($hosts);

    return $hosts === ['i.ytimg.com', 'img.youtube.com'] ?: 'got ' . json_encode($hosts);
});

check('self-hosted with no copy yet: no poster, and nothing outside the template names a third party', function() use ($plugin, $youtube) {
    $plugin->getSettings()->posterMode = 'local';
    $plugin->getSettings()->posterVolume = null;
    $html = (string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click']));
    $live = liveMarkup($html);

    return str_contains($html, 'data-eye-consent')
        && !str_contains($live, 'background-image')
        && !preg_match('~https?://(?!' . preg_quote((string)parse_url((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST), '~') . ')~', str_replace('https://www.youtube.com/watch?v=dQw4w9WgXcQ', '', $live))
        ?: 'live markup: ' . substr($live, 0, 600);
});

check('hotlinked keeps Eye 5.0’s behaviour', function() use ($plugin, $youtube) {
    $plugin->getSettings()->posterMode = 'remote';
    $html = (string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click']));
    $plugin->getSettings()->posterMode = 'local';

    return str_contains($html, 'background-image:url(https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg)') ?: 'no hotlinked poster';
});

check('none shows no poster at all', function() use ($plugin, $youtube) {
    $plugin->getSettings()->posterMode = 'none';
    $html = (string)$plugin->renderer->renderUrl($youtube, EmbedOptions::fromArray(['loading' => 'click']));
    $plugin->getSettings()->posterMode = 'local';

    return !str_contains($html, 'background-image');
});

check('a poster on this site is shown as it is', function() use ($plugin) {
    $own = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/') . '/images/poster.jpg';

    return $plugin->posters->displayUrl($own) === $own ?: 'got ' . $plugin->posters->displayUrl($own);
});

check('posters download only from poster hosts — and the proxy’s allowlist while it is on', function() use ($plugin) {
    $posters = $plugin->posters;
    $settings = $plugin->getSettings();
    $settings->proxyEnabled = false;
    $settings->allowedHosts = ['example.com'];
    $before = [$posters->isDownloadable('https://i.ytimg.com/vi/x/hqdefault.jpg'), $posters->isDownloadable('https://example.com/a.jpg')];
    $settings->proxyEnabled = true;
    $after = $posters->isDownloadable('https://example.com/a.jpg');
    $settings->proxyEnabled = false;
    $settings->allowedHosts = [];
    $never = [
        $posters->isDownloadable('https://evil.test/a.jpg'),
        $posters->isDownloadable('https://i.ytimg.com.evil.test/a.jpg'),
        $posters->isDownloadable('file:///etc/passwd'),
        $posters->isDownloadable('ftp://i.ytimg.com/a.jpg'),
    ];

    return $before === [true, false] && $after === true && $never === [false, false, false, false]
        ?: json_encode(compact('before', 'after', 'never'));
});

check('the CP explains a poster Eye will not download', function() use ($plugin) {
    $plugin->getSettings()->posterMode = 'local';
    $described = $plugin->posters->describe('https://evil.test/a.jpg');

    return $described['state'] === 'refused' && $described['url'] === '' ?: json_encode($described);
});

check('the CP says when hotlinking hands the reader over', function() use ($plugin) {
    $plugin->getSettings()->posterMode = 'remote';
    $described = $plugin->posters->describe('https://i.ytimg.com/vi/x/hqdefault.jpg');
    $plugin->getSettings()->posterMode = 'local';

    return $described['state'] === 'remote' && str_contains($described['message'], 'i.ytimg.com') ?: json_encode($described);
});

check('a reference tag cannot set a poster', function() use ($plugin, $run) {
    $plugin->getSettings()->posterMode = 'remote';
    $html = Craft::$app->getElements()->parseRefs("{eye:eye-privacy-$run:render(click,posterUrl=https://evil.test/p.jpg)}");
    $plugin->getSettings()->posterMode = 'local';

    return str_contains($html, 'data-eye-consent') && !str_contains($html, 'evil.test') ?: 'got ' . substr($html, 0, 300);
});

check('folder settings that leave the volume are refused', function() {
    $bad = [];

    foreach (['../etc', 'a/../../b', '/abs/olute', 'sp ace', 'ok/fine-1', ''] as $folder) {
        $settings = new Settings(['posterFolder' => $folder]);
        $bad[$folder] = !$settings->validate(['posterFolder']);
    }

    return $bad === ['../etc' => true, 'a/../../b' => true, '/abs/olute' => false, 'sp ace' => true, 'ok/fine-1' => false, '' => false]
        ?: json_encode($bad);
});

// -----------------------------------------------------------------------------
section('Posters — fetching');

$image = $serve("eye-poster-$run.png", $png());
$bigImage = $serve("eye-poster-big-$run.png", $png(800, 600));
$fake = $serve("eye-poster-fake-$run.png", 'this is not a picture');
$svg = $serve("eye-poster-$run.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

check('a raster image on a poster host comes back', function() use ($pinned, $image) {
    $result = $pinned->fetchImage("http://i.ytimg.com/$image", ['i.ytimg.com'], Posters::MAX_BYTES);

    return $result->contentType === 'image/png' && str_starts_with($result->body, "\x89PNG") ?: "got $result->contentType";
});

check('a host off the list is refused before anything connects', function() use ($pinned, $image) {
    try {
        $pinned->fetchImage("http://example.com/$image", ['i.ytimg.com'], Posters::MAX_BYTES);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'not a host Eye downloads posters from') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('the address rules still hold: a poster host on a private address is refused', function() use ($plugin) {
    try {
        $plugin->fetcher->fetchImage('http://localhost/x.png', ['localhost'], Posters::MAX_BYTES);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'public address') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('an SVG is refused, whatever it is called', function() use ($pinned, $svg) {
    try {
        $pinned->fetchImage("http://i.ytimg.com/$svg", ['i.ytimg.com'], Posters::MAX_BYTES);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'not a type Eye accepts') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched an SVG';
});

check('a text file is refused', function() use ($pinned) {
    try {
        $pinned->fetchImage('http://i.ytimg.com/robots.txt', ['i.ytimg.com'], Posters::MAX_BYTES);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'not a type Eye accepts') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched a text file';
});

check('an image over the cap is abandoned', function() use ($pinned, $bigImage) {
    try {
        $pinned->fetchImage("http://i.ytimg.com/$bigImage", ['i.ytimg.com'], 1024);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'larger than') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('a redirect off the poster hosts is not followed', function() use ($pinned, $serve, $run) {
    // A one-line script in the web root that sends the fetch somewhere else.
    $script = $serve("eye-redirect-$run.php", '<?php header("Location: http://evil.test/poster.png", true, 302);');

    try {
        $pinned->fetchImage("http://i.ytimg.com/$script", ['i.ytimg.com'], Posters::MAX_BYTES);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'not a host Eye downloads posters from') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it followed the redirect';
});

check('the proxy’s switch does not govern posters', function() use ($plugin, $pinned, $image) {
    $plugin->getSettings()->proxyEnabled = false;

    try {
        $pinned->fetchImage("http://i.ytimg.com/$image", ['i.ytimg.com'], Posters::MAX_BYTES);
    } catch (Throwable $e) {
        return 'refused: ' . $e->getMessage();
    }

    return true;
});

// -----------------------------------------------------------------------------
section('Posters — downloading into a volume');

if (!$volume) {
    check('the harness has an asset volume', fn() => 'no volume to save posters to');
} else {
    $plugin->set('fetcher', $pinned);
    $settings->posterMode = 'local';
    $settings->posterVolume = $volume->uid;
    $settings->posterFolder = $folderPath;
    $settings->posterTransform = null;

    $posterUrl = $track("http://i.ytimg.com/$image");
    $asset = null;

    check('a poster downloads into the configured volume and folder', function() use ($plugin, $posterUrl, $folderPath, $volume, &$asset, &$created) {
        $asset = $plugin->posters->download($posterUrl);
        $created['assets'][] = $asset->id;

        return $asset->volumeId === $volume->id
            && str_starts_with((string)$asset->getPath(), "$folderPath/poster-")
            && $asset->getExtension() === 'png'
            ?: 'saved as ' . $asset->getPath() . ' in volume ' . $asset->volumeId;
    });

    check('its row says ready and points at the asset', function() use ($posterUrl, &$asset) {
        $row = PosterRecord::find()->where(['urlHash' => Posters::hash($posterUrl)])->asArray()->one();

        return $row && $row['status'] === 'ready' && (int)$row['assetId'] === (int)$asset?->id ?: json_encode($row);
    });

    check('the card shows the copy, served from this site', function() use ($plugin, $posterUrl, &$asset) {
        $shown = (new Posters())->displayUrl($posterUrl);
        $assetUrl = (string)$asset?->getUrl();
        $html = (string)$plugin->renderer->renderUrl('https://example.com/video', EmbedOptions::fromArray(['loading' => 'click', 'posterUrl' => $posterUrl]));

        return $shown !== '' && $shown === $assetUrl && !str_contains(liveMarkup($html), 'i.ytimg.com') && str_contains($html, 'background-image:url(')
            ?: "shown “{$shown}”, asset “{$assetUrl}”";
    });

    check('a second download reuses the copy', function() use ($plugin, $posterUrl, &$asset) {
        $again = $plugin->posters->download($posterUrl);

        return $again->id === $asset?->id ?: "new asset $again->id";
    });

    check('a forced download replaces the file in place', function() use ($plugin, $posterUrl, &$asset) {
        $again = $plugin->posters->download($posterUrl, true);

        return $again->id === $asset?->id ?: "new asset $again->id";
    });

    check('a file that only claims to be an image is refused and the reason recorded', function() use ($plugin, $fake, $track) {
        $url = $track("http://i.ytimg.com/$fake");

        try {
            $plugin->posters->download($url);
        } catch (Throwable $e) {
            $described = (new Posters())->describe($url);

            return str_contains($e->getMessage(), 'not an image Eye accepts') && $described['state'] === 'failed'
                ?: 'got ' . $e->getMessage() . ' / ' . json_encode($described);
        }

        return 'it saved a fake image';
    });

    check('a render with no copy queues one download, once', function() use ($track, $image) {
        $url = $track("http://i.ytimg.com/queued-$image");
        $posters = new Posters();
        $count = fn() => (int)(new craft\db\Query())->from('{{%queue}}')->where(['like', 'description', 'Downloading an embed poster'])->count();
        $before = $count();
        $shown = $posters->displayUrl($url);
        $again = (new Posters())->queue($url);
        $after = $count();
        $row = PosterRecord::find()->where(['urlHash' => Posters::hash($url)])->asArray()->one();

        // The queue runner may already have picked the job up (and failed: the file is absent).
        return $shown === '' && $again === false && $row !== null && ($after - $before === 1 || $row['status'] !== 'pending')
            ?: json_encode(compact('shown', 'again', 'before', 'after', 'row'));
    });

    check('the queue job downloads the poster', function() use ($image, $track, &$created) {
        $name = 'job-' . $image;
        $GLOBALS['serve']($name, $GLOBALS['png']());
        $url = $track("http://i.ytimg.com/$name");
        (new DownloadPoster(['url' => $url]))->execute(Craft::$app->getQueue());
        $asset = (new Posters())->asset($url);

        if ($asset) {
            $created['assets'][] = $asset->id;
        }

        return $asset !== null ?: 'no asset after the job ran';
    });

    check('saving a YouTube embed queues its poster', function() use ($plugin, $run, $track, &$created) {
        $embed = $plugin->embeds->createFromUrl('https://youtu.be/M7lc1UVf-VE', ['handle' => "eye-privacy-save-$run"]);
        $embed->handle = "eye-privacy-save-$run";
        $source = $track($plugin->posters->sourceFor($embed));

        if (!Craft::$app->getElements()->saveElement($embed)) {
            return 'could not save: ' . json_encode($embed->getErrors());
        }

        $created['embeds'][] = $embed->id;
        $row = PosterRecord::find()->where(['urlHash' => Posters::hash($source)])->asArray()->one();

        return $source === 'https://i.ytimg.com/vi/M7lc1UVf-VE/hqdefault.jpg' && $row !== null ?: "source $source, row " . json_encode($row);
    });

    check('deleting the copy forgets it, so the next render fetches it again', function() use ($posterUrl, &$asset, &$created) {
        $id = $asset?->id;
        Craft::$app->getElements()->deleteElement($asset, true);
        $row = PosterRecord::find()->where(['urlHash' => Posters::hash($posterUrl)])->asArray()->one();

        return $row === null ?: 'row survived: ' . json_encode($row) . " (asset $id)";
    });

    check('download-all fetches every embed’s poster and reports the rest', function() use ($plugin, $image, $track, &$created) {
        $name = 'all-' . $image;
        $GLOBALS['serve']($name, $GLOBALS['png']());
        $url = $track("http://i.ytimg.com/$name");
        $embed = $plugin->embeds->createFromUrl('https://example.com/all-' . $name, ['posterUrl' => $url, 'loading' => 'click']);
        $embed->handle = 'eye-privacy-all-' . substr(md5($name), 0, 6);
        Craft::$app->getElements()->saveElement($embed);
        $created['embeds'][] = $embed->id;
        $seen = [];
        $counts = $plugin->posters->downloadAll(false, function($u, $label, $state) use (&$seen, $track) {
            $seen[$u] = $state;
            // Download-all visits the harness's other embeds too; their rows go at the end.
            $track($u);
        });
        $asset = (new Posters())->asset($url);

        if ($asset) {
            $created['assets'][] = $asset->id;
        }

        foreach ((new craft\db\Query())->select(['assetId'])->from(PosterRecord::TABLE)->where(['urlHash' => $GLOBALS['created']['hashes']])->column() as $assetId) {
            if ($assetId) {
                $created['assets'][] = (int)$assetId;
            }
        }

        return in_array($seen[$url] ?? null, ['downloaded', 'existing'], true) && $asset !== null && array_sum($counts) === count($seen)
            ?: json_encode(['state' => $seen[$url] ?? null, 'counts' => $counts]);
    });
}

// -----------------------------------------------------------------------------
section('Settings screen');

check('the settings screen offers the managers, the hold switch and the poster settings', function() use ($plugin) {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode($view::TEMPLATE_MODE_CP);

    try {
        $html = (string)(new ReflectionMethod($plugin, 'settingsHtml'))->invoke($plugin);
    } finally {
        $view->setTemplateMode($mode);
    }

    foreach (['name="consentManager"', 'value="cookieyes"', 'value="consentmode"', 'name="consentForAllEmbeds"', 'name="posterMode"', 'name="posterVolume"', 'name="posterFolder"', 'i.ytimg.com'] as $needle) {
        if (!str_contains($html, $needle)) {
            return "missing $needle";
        }
    }

    return true;
});

echo "\n";
echo $failed === 0 ? "All $passed checks passed.\n" : "$passed passed, $failed FAILED.\n";

exit($failed === 0 ? 0 : 1);
