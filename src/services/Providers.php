<?php

namespace justinholtweb\eye\services;

use craft\base\Component;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\Provider;
use justinholtweb\eye\models\ProviderMatch;
use justinholtweb\eye\Plugin;

/**
 * Paste a URL, get the right embed.
 *
 * This is the service that makes an author never type `<iframe>`. Each provider knows the URL
 * shapes its service uses, the URL a frame should actually point at, the aspect ratio the
 * content really has, the Permissions-Policy features it needs, and — where one exists — the
 * cookie-less host to prefer.
 *
 * Everything a provider returns is a *default*. An embed can override any of it, and an
 * unrecognised URL still works: it falls through to the generic provider, which frames the URL
 * as given and assumes nothing.
 */
class Providers extends Component
{
    /** @var array<string, Provider>|null */
    private ?array $providers = null;

    /** @var array<string, array>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, Provider> Keyed by handle, in match order.
     */
    public function getAll(): array
    {
        if ($this->providers === null) {
            $this->providers = [];

            foreach ($this->definitions() as $handle => $definition) {
                $this->providers[$handle] = new Provider(array_diff_key($definition, array_flip(['patterns', 'build', 'poster'])) + ['handle' => $handle]);
            }
        }

        return $this->providers;
    }

    public function getByHandle(string $handle): ?Provider
    {
        return $this->getAll()[$handle] ?? null;
    }

    /**
     * Work out how to embed a URL.
     *
     * Never returns null for a well-formed http(s) URL — the generic provider always matches —
     * so callers do not need a second code path for "we do not know this service".
     */
    public function match(string $url, ?bool $privacy = null): ?ProviderMatch
    {
        $url = trim($url);

        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return null;
        }

        $privacy ??= Plugin::getInstance()->getSettings()->privacyMode;
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $query = [];

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $context = [
            'url' => $url,
            'host' => strtolower($parts['host']),
            'path' => $parts['path'] ?? '/',
            'query' => $query,
            'fragment' => $parts['fragment'] ?? '',
            'privacy' => $privacy,
        ];

        foreach ($this->definitions() as $handle => $definition) {
            if ($handle === 'generic') {
                continue;
            }

            foreach ($definition['patterns'] ?? [] as $pattern) {
                if (!preg_match($pattern, $url, $matches)) {
                    continue;
                }

                $built = ($definition['build'])($matches, $context);

                if ($built === null) {
                    continue;
                }

                $result = new ProviderMatch([
                    'provider' => $this->getByHandle($handle),
                    'sourceUrl' => $url,
                    'embedUrl' => is_array($built) ? $built['url'] : $built,
                    'options' => is_array($built) ? ($built['options'] ?? []) : [],
                    'label' => is_array($built) ? ($built['label'] ?? '') : '',
                ]);

                if (isset($definition['poster'])) {
                    $result->posterUrl = (string)($definition['poster'])($matches, $context);
                }

                return $result;
            }
        }

        return new ProviderMatch([
            'provider' => $this->getByHandle('generic'),
            'sourceUrl' => $url,
            'embedUrl' => $url,
        ]);
    }

    /** Whether a URL is something Eye recognises, as opposed to something it will merely frame. */
    public function isKnown(string $url): bool
    {
        $match = $this->match($url);

        return $match !== null && !$match->provider->getIsGeneric();
    }

    /**
     * Options for a provider `<select>`.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getSelectOptions(): array
    {
        $options = [];

        foreach ($this->getAll() as $handle => $provider) {
            $options[] = ['label' => $provider->name, 'value' => $handle];
        }

        return $options;
    }

    // The registry
    // -------------------------------------------------------------------------

    /**
     * Order matters: the first pattern to match wins, so the specific services come before the
     * catch-alls, and `generic` is last and matched only by falling off the end.
     *
     * @return array<string, array>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $video = ['accelerometer', 'autoplay', 'clipboard-write', 'encrypted-media', 'gyroscope', 'picture-in-picture', 'web-share'];

        return $this->definitions = [
            'youtube' => [
                'name' => 'YouTube',
                'homepage' => 'https://www.youtube.com',
                'ratio' => '16:9',
                'allow' => $video,
                'hasPrivacyVariant' => true,
                'consentText' => 'This will load a video from YouTube, which may set cookies.',
                'patterns' => [
                    '~^https?://(?:www\.|m\.)?youtube(?:-nocookie)?\.com/watch\?[^#]*\bv=([\w-]{6,})~i',
                    '~^https?://youtu\.be/([\w-]{6,})~i',
                    '~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/(?:embed|shorts|live|v)/([\w-]{6,})~i',
                    '~^https?://(?:www\.)?youtube\.com/playlist\?[^#]*\blist=([\w-]+)~i',
                ],
                'build' => function(array $m, array $c) {
                    $host = $c['privacy'] ? 'www.youtube-nocookie.com' : 'www.youtube.com';
                    $isPlaylist = str_contains($c['path'], '/playlist');
                    $params = [];

                    if (!empty($c['query']['list']) && !$isPlaylist) {
                        $params['list'] = $c['query']['list'];
                    }

                    // `?t=90`, `?t=1m30s` and `#t=90` all mean the same thing to a viewer, and
                    // none of them is what the embed player wants — it only takes `start`.
                    $start = $c['query']['t'] ?? $c['query']['start'] ?? $this->seconds($c['fragment']);

                    if ($start !== null && $start !== '') {
                        $params['start'] = $this->seconds((string)$start) ?? (int)$start;
                    }

                    $path = $isPlaylist ? 'embed/videoseries' : "embed/$m[1]";

                    if ($isPlaylist) {
                        $params['list'] = $m[1];
                    }

                    $shorts = str_contains($c['path'], '/shorts/');

                    return [
                        'url' => "https://$host/$path" . ($params ? '?' . http_build_query($params) : ''),
                        'options' => $shorts ? ['ratio' => '9:16'] : [],
                    ];
                },
                'poster' => fn(array $m, array $c) => str_contains($c['path'], '/playlist')
                    ? ''
                    : "https://i.ytimg.com/vi/$m[1]/hqdefault.jpg",
            ],

            'vimeo' => [
                'name' => 'Vimeo',
                'homepage' => 'https://vimeo.com',
                'ratio' => '16:9',
                'allow' => $video,
                'hasPrivacyVariant' => true,
                'consentText' => 'This will load a video from Vimeo.',
                'patterns' => [
                    '~^https?://(?:www\.)?vimeo\.com/(?:channels/[\w-]+/|groups/[\w-]+/videos/)?(\d+)(?:/([0-9a-f]+))?~i',
                    '~^https?://player\.vimeo\.com/video/(\d+)(?:\?[^#]*\bh=([0-9a-f]+))?~i',
                ],
                'build' => function(array $m, array $c) {
                    $params = [];

                    // An unlisted video carries its access hash in the path; the player wants it
                    // as `h=`. Drop it and the embed 404s with no explanation.
                    $hash = $m[2] ?? ($c['query']['h'] ?? '');

                    if ($hash) {
                        $params['h'] = $hash;
                    }

                    if ($c['privacy']) {
                        $params['dnt'] = 1;
                    }

                    return 'https://player.vimeo.com/video/' . $m[1] . ($params ? '?' . http_build_query($params) : '');
                },
            ],

            'loom' => [
                'name' => 'Loom',
                'homepage' => 'https://www.loom.com',
                'ratio' => '16:9',
                'allow' => ['fullscreen'],
                'patterns' => ['~^https?://(?:www\.)?loom\.com/(?:share|embed)/([0-9a-f]{16,})~i'],
                'build' => fn(array $m) => "https://www.loom.com/embed/$m[1]",
            ],

            'wistia' => [
                'name' => 'Wistia',
                'homepage' => 'https://wistia.com',
                'ratio' => '16:9',
                'allow' => $video,
                'patterns' => ['~^https?://(?:[\w-]+\.)?wistia\.(?:com|net)/(?:medias|embed/iframe)/(\w+)~i'],
                'build' => fn(array $m) => "https://fast.wistia.net/embed/iframe/$m[1]",
            ],

            'dailymotion' => [
                'name' => 'Dailymotion',
                'homepage' => 'https://www.dailymotion.com',
                'ratio' => '16:9',
                'allow' => $video,
                'patterns' => [
                    '~^https?://(?:www\.)?dailymotion\.com/video/([a-z0-9]+)~i',
                    '~^https?://dai\.ly/([a-z0-9]+)~i',
                ],
                'build' => fn(array $m) => "https://geo.dailymotion.com/player.html?video=$m[1]",
            ],

            'tiktok' => [
                'name' => 'TikTok',
                'homepage' => 'https://www.tiktok.com',
                'ratio' => '9:16',
                'allow' => $video,
                'patterns' => ['~^https?://(?:www\.)?tiktok\.com/@[\w.-]+/video/(\d+)~i'],
                'build' => fn(array $m) => "https://www.tiktok.com/embed/v2/$m[1]",
            ],

            'instagram' => [
                'name' => 'Instagram',
                'homepage' => 'https://www.instagram.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 640,
                'allow' => ['encrypted-media', 'picture-in-picture'],
                'patterns' => ['~^https?://(?:www\.)?instagram\.com/(p|reel|tv)/([\w-]+)~i'],
                'build' => fn(array $m) => "https://www.instagram.com/$m[1]/$m[2]/embed/",
            ],

            'x' => [
                'name' => 'X (Twitter)',
                'homepage' => 'https://x.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 560,
                'allow' => [],
                'patterns' => ['~^https?://(?:www\.)?(?:twitter|x)\.com/[\w]+/status/(\d+)~i'],
                'build' => fn(array $m) => "https://platform.twitter.com/embed/Tweet.html?id=$m[1]",
            ],

            'spotify' => [
                'name' => 'Spotify',
                'homepage' => 'https://open.spotify.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 352,
                'allow' => ['autoplay', 'clipboard-write', 'encrypted-media', 'picture-in-picture'],
                'allowFullscreen' => false,
                'patterns' => ['~^https?://open\.spotify\.com/(?:intl-\w+/)?(track|album|playlist|artist|show|episode)/(\w+)~i'],
                'build' => fn(array $m) => [
                    'url' => "https://open.spotify.com/embed/{$m[1]}/{$m[2]}",
                    // A single track's player is one row tall; an album's is a list.
                    'options' => ['height' => in_array(strtolower($m[1]), ['track', 'episode'], true) ? 152 : 352],
                ],
            ],

            'soundcloud' => [
                'name' => 'SoundCloud',
                'homepage' => 'https://soundcloud.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 166,
                'allow' => ['autoplay'],
                'allowFullscreen' => false,
                'patterns' => ['~^https?://(?:www\.)?soundcloud\.com/[\w-]+(?:/[\w-]+)?~i'],
                'build' => fn(array $m, array $c) => 'https://w.soundcloud.com/player/?' . http_build_query([
                    'url' => $c['url'],
                    'color' => '#333333',
                    'visual' => 'false',
                ]),
            ],

            'applepodcasts' => [
                'name' => 'Apple Podcasts',
                'homepage' => 'https://podcasts.apple.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 175,
                'allow' => ['autoplay', 'encrypted-media'],
                'allowFullscreen' => false,
                'patterns' => ['~^https?://podcasts\.apple\.com/(.+)$~i'],
                'build' => fn(array $m) => "https://embed.podcasts.apple.com/$m[1]",
            ],

            'applemusic' => [
                'name' => 'Apple Music',
                'homepage' => 'https://music.apple.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 450,
                'allow' => ['autoplay', 'encrypted-media'],
                'allowFullscreen' => false,
                'patterns' => ['~^https?://music\.apple\.com/(.+)$~i'],
                'build' => fn(array $m) => "https://embed.music.apple.com/$m[1]",
            ],

            'googlemaps' => [
                'name' => 'Google Maps',
                'homepage' => 'https://maps.google.com',
                'ratio' => '4:3',
                'allow' => ['geolocation'],
                'consentText' => 'This will load a map from Google.',
                'patterns' => [
                    '~^https?://(?:www\.)?google\.[a-z.]+/maps/embed\?~i',
                    '~^https?://(?:www\.)?google\.[a-z.]+/maps/place/([^/@?]+)~i',
                    '~^https?://(?:www\.)?google\.[a-z.]+/maps/?\?~i',
                    '~^https?://maps\.app\.goo\.gl/(\w+)~i',
                    '~^https?://goo\.gl/maps/(\w+)~i',
                ],
                'build' => function(array $m, array $c) {
                    // An `/maps/embed?pb=…` URL is already an embed — Google's own "share" dialog
                    // hands one out — so pass it through untouched rather than re-deriving it.
                    if (str_contains($c['path'], '/maps/embed')) {
                        return $c['url'];
                    }

                    // A short link (`maps.app.goo.gl`) cannot be resolved without following it,
                    // which is a server-side fetch this service has no business doing. Framing it
                    // works: Google redirects inside the frame.
                    if (str_contains($c['host'], 'goo.gl')) {
                        return $c['url'];
                    }

                    $q = $c['query']['q'] ?? ($m[1] ?? '');

                    if ($q === '' && preg_match('~/@(-?[\d.]+),(-?[\d.]+)~', $c['url'], $at)) {
                        $q = "$at[1],$at[2]";
                    }

                    if ($q === '') {
                        return null;
                    }

                    return 'https://www.google.com/maps?' . http_build_query([
                        'q' => rawurldecode((string)$q),
                        'output' => 'embed',
                    ]);
                },
            ],

            'openstreetmap' => [
                'name' => 'OpenStreetMap',
                'homepage' => 'https://www.openstreetmap.org',
                'ratio' => '4:3',
                'allowFullscreen' => false,
                'patterns' => ['~^https?://(?:www\.)?openstreetmap\.org/~i'],
                'build' => function(array $m, array $c) {
                    if (str_contains($c['path'], '/export/embed.html')) {
                        return $c['url'];
                    }

                    // `#map=15/51.5074/-0.1278` is where OSM keeps the view. The embed endpoint
                    // wants a bounding box instead, so derive one whose size tracks the zoom.
                    if (!preg_match('~map=(\d+)/(-?[\d.]+)/(-?[\d.]+)~', $c['fragment'], $hash)) {
                        return null;
                    }

                    $zoom = (int)$hash[1];
                    $lat = (float)$hash[2];
                    $lon = (float)$hash[3];
                    $span = 360 / (2 ** max(1, $zoom)) * 2;

                    return 'https://www.openstreetmap.org/export/embed.html?' . http_build_query([
                        'bbox' => implode(',', [$lon - $span, $lat - $span / 2, $lon + $span, $lat + $span / 2]),
                        'layer' => 'mapnik',
                        'marker' => "$lat,$lon",
                    ]);
                },
            ],

            'googledocs' => [
                'name' => 'Google Docs',
                'homepage' => 'https://docs.google.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 640,
                'patterns' => ['~^https?://docs\.google\.com/(document|spreadsheets|presentation|forms)/d/(?:e/)?([\w-]+)~i'],
                'build' => function(array $m, array $c) {
                    $type = strtolower($m[1]);
                    $isPublished = str_contains($c['path'], '/d/e/');
                    $base = "https://docs.google.com/$type/d/" . ($isPublished ? 'e/' : '') . $m[2];

                    return match ($type) {
                        'presentation' => [
                            'url' => "$base/embed?start=false&loop=false",
                            'options' => ['mode' => EmbedOptions::MODE_RATIO, 'ratio' => '16:9'],
                        ],
                        'forms' => "$base/viewform?embedded=true",
                        default => "$base/preview",
                    };
                },
            ],

            'googlecalendar' => [
                'name' => 'Google Calendar',
                'homepage' => 'https://calendar.google.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 600,
                'allowFullscreen' => false,
                'patterns' => ['~^https?://calendar\.google\.com/calendar/(?:u/\d+/)?(?:embed|r)\?~i'],
                'build' => fn(array $m, array $c) => str_replace('/calendar/r?', '/calendar/embed?', $c['url']),
            ],

            'calendly' => [
                'name' => 'Calendly',
                'homepage' => 'https://calendly.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 700,
                'allowFullscreen' => false,
                'consentText' => 'This will load a booking calendar from Calendly.',
                'patterns' => ['~^https?://calendly\.com/([\w-]+(?:/[\w-]+)?)~i'],
                'build' => fn(array $m, array $c) => $c['url'] . (str_contains($c['url'], '?') ? '&' : '?') . 'embed_type=Inline',
            ],

            'typeform' => [
                'name' => 'Typeform',
                'homepage' => 'https://www.typeform.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 600,
                'allowFullscreen' => false,
                'patterns' => ['~^https?://[\w-]+\.typeform\.com/to/(\w+)~i'],
                'build' => fn(array $m, array $c) => $c['url'],
            ],

            'airtable' => [
                'name' => 'Airtable',
                'homepage' => 'https://airtable.com',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 533,
                'allowFullscreen' => false,
                'patterns' => ['~^https?://airtable\.com/(?:embed/)?(shr\w+|app\w+/shr\w+)~i'],
                'build' => fn(array $m) => "https://airtable.com/embed/$m[1]",
            ],

            'figma' => [
                'name' => 'Figma',
                'homepage' => 'https://www.figma.com',
                'ratio' => '16:9',
                'allow' => ['clipboard-write'],
                'patterns' => ['~^https?://(?:www\.)?figma\.com/(file|design|proto|board|slides)/~i'],
                'build' => fn(array $m, array $c) => 'https://www.figma.com/embed?' . http_build_query([
                    'embed_host' => 'craft-eye',
                    'url' => $c['url'],
                ]),
            ],

            'miro' => [
                'name' => 'Miro',
                'homepage' => 'https://miro.com',
                'ratio' => '16:9',
                'patterns' => ['~^https?://miro\.com/app/(?:board|live-embed)/([\w=-]+)~i'],
                'build' => fn(array $m) => "https://miro.com/app/live-embed/$m[1]/?moveToViewport=&embedAutoplay=true",
            ],

            'canva' => [
                'name' => 'Canva',
                'homepage' => 'https://www.canva.com',
                'ratio' => '16:9',
                'patterns' => ['~^https?://(?:www\.)?canva\.com/design/([\w-]+)/([\w-]+)~i'],
                'build' => fn(array $m) => "https://www.canva.com/design/$m[1]/$m[2]/view?embed",
            ],

            'codepen' => [
                'name' => 'CodePen',
                'homepage' => 'https://codepen.io',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 400,
                'patterns' => ['~^https?://codepen\.io/((?:team/)?[\w-]+)/(?:pen|embed|details|full)/([\w-]+)~i'],
                'build' => fn(array $m) => "https://codepen.io/$m[1]/embed/$m[2]?default-tab=result",
            ],

            'jsfiddle' => [
                'name' => 'JSFiddle',
                'homepage' => 'https://jsfiddle.net',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 400,
                'patterns' => ['~^https?://jsfiddle\.net/(?:([\w-]+)/)?(\w+)~i'],
                'build' => fn(array $m) => 'https://jsfiddle.net/' . ($m[1] ? "$m[1]/" : '') . "$m[2]/embedded/result,html,css,js/",
            ],

            'codesandbox' => [
                'name' => 'CodeSandbox',
                'homepage' => 'https://codesandbox.io',
                'mode' => EmbedOptions::MODE_FIXED,
                'height' => 500,
                'allow' => ['accelerometer', 'camera', 'encrypted-media', 'geolocation', 'gyroscope', 'microphone'],
                'patterns' => ['~^https?://codesandbox\.io/(?:s|embed|p/sandbox)/([\w-]+)~i'],
                'build' => fn(array $m) => "https://codesandbox.io/embed/$m[1]",
            ],

            'descript' => [
                'name' => 'Descript',
                'homepage' => 'https://www.descript.com',
                'ratio' => '16:9',
                'allow' => $video,
                'patterns' => ['~^https?://share\.descript\.com/(?:view|embed)/(\w+)~i'],
                'build' => fn(array $m) => "https://share.descript.com/embed/$m[1]",
            ],

            'pdf' => [
                'name' => 'PDF',
                'homepage' => '',
                'mode' => EmbedOptions::MODE_RATIO,
                'ratio' => '17:22',
                'allowFullscreen' => true,
                'patterns' => ['~^https?://[^?#]+\.pdf(?:[?#]|$)~i'],
                'build' => fn(array $m, array $c) => $c['url'],
            ],

            'generic' => [
                'name' => 'Web page',
                'homepage' => '',
                'mode' => EmbedOptions::MODE_RATIO,
                'ratio' => '4:3',
                'patterns' => [],
                'build' => fn(array $m, array $c) => $c['url'],
            ],
        ];
    }

    /**
     * `90`, `1m30s` and `t=2h3m4s` in seconds; null when the string says nothing about time.
     */
    private function seconds(string $value): ?int
    {
        $value = ltrim(trim($value), 't=');

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        if (!preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/i', $value, $m) || !array_filter(array_slice($m, 1))) {
            return null;
        }

        return ((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + (int)($m[3] ?? 0);
    }
}
