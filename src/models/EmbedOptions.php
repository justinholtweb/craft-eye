<?php

namespace justinholtweb\eye\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * Everything presentational about an embed.
 *
 * One format, shared verbatim by {@see \justinholtweb\eye\elements\Embed}, by inline field
 * values, by the editor JavaScript and by the renderer — so there is exactly one place that
 * knows what "lazy" or "16:9" means, and a value written by any of them can be read by all of
 * them.
 */
class EmbedOptions extends Model
{
    // How the frame gets its height, which is the whole problem.
    // -------------------------------------------------------------------------

    /** A responsive aspect-ratio box. The default, and what every video provider wants. */
    public const MODE_RATIO = 'ratio';

    /** One explicit height, in pixels. */
    public const MODE_FIXED = 'fixed';

    /** The frame reports its own height — same-origin DOM read, or the postMessage handshake. */
    public const MODE_AUTO = 'auto';

    /** Eye fetches the page and serves it from its own origin, so auto height always works. */
    public const MODE_PROXY = 'proxy';

    /** Eye fetches the page and splices the extracted fragment in. No iframe at all. */
    public const MODE_INLINE = 'inline';

    public const MODES = [
        self::MODE_RATIO,
        self::MODE_FIXED,
        self::MODE_AUTO,
        self::MODE_PROXY,
        self::MODE_INLINE,
    ];

    /** The modes that fetch the page server-side, and so answer to the proxy settings. */
    public const FETCHING_MODES = [self::MODE_PROXY, self::MODE_INLINE];

    public const LOADING_EAGER = 'eager';
    public const LOADING_LAZY = 'lazy';

    /** Nothing is requested until the reader asks for it. The GDPR-shaped one. */
    public const LOADING_CLICK = 'click';

    public const LOADINGS = [self::LOADING_EAGER, self::LOADING_LAZY, self::LOADING_CLICK];

    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';
    public const ALIGN_WIDE = 'wide';
    public const ALIGN_FULL = 'full';

    public const ALIGNS = [
        self::ALIGN_LEFT,
        self::ALIGN_CENTER,
        self::ALIGN_RIGHT,
        self::ALIGN_WIDE,
        self::ALIGN_FULL,
    ];

    /**
     * `sandbox` tokens Eye will write. Anything not on this list is dropped rather than passed
     * through, because a typo'd token is silently ignored by the browser and an author would
     * never find out that their sandbox has a hole in it.
     *
     * `allow-scripts` + `allow-same-origin` together defeat the sandbox for a *same-origin*
     * frame; {@see self::getSandboxWarning()} says so rather than silently rewriting it.
     */
    public const SANDBOX_TOKENS = [
        'allow-downloads',
        'allow-forms',
        'allow-modals',
        'allow-orientation-lock',
        'allow-pointer-lock',
        'allow-popups',
        'allow-popups-to-escape-sandbox',
        'allow-presentation',
        'allow-same-origin',
        'allow-scripts',
        'allow-storage-access-by-user-activation',
        'allow-top-navigation',
        'allow-top-navigation-by-user-activation',
    ];

    /** Permissions-Policy features an `allow` attribute may name. */
    public const ALLOW_FEATURES = [
        'accelerometer',
        'autoplay',
        'camera',
        'clipboard-write',
        'encrypted-media',
        'fullscreen',
        'geolocation',
        'gyroscope',
        'microphone',
        'payment',
        'picture-in-picture',
        'web-share',
        'xr-spatial-tracking',
    ];

    /**
     * The consent categories an embed can wait for. The names Toss and the rest of the family use,
     * and the four every consent manager has an equivalent of.
     */
    public const CONSENT_CATEGORIES = ['necessary', 'preferences', 'analytics', 'marketing'];

    public const REFERRER_POLICIES = [
        'no-referrer',
        'no-referrer-when-downgrade',
        'origin',
        'origin-when-cross-origin',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
        'unsafe-url',
    ];

    /**
     * What a reference tag may change: `{eye:map:render(click,height=500)}`.
     *
     * Anyone who can type into a rich-text field can write a reference tag, and Craft's ref-tag
     * pattern allows almost any character in it — so a tag may only change where and how an
     * embed sits on the page, never what it runs: no fallback markup, no CSS, no sandbox, no
     * proxy rules, and no consent category (`necessary` would load it without asking). Those
     * belong to whoever manages the library embed.
     */
    public const REF_TAG_KEYS = [
        'mode', 'ratio', 'height', 'minHeight', 'maxHeight', 'width', 'align', 'className',
        'caption', 'title', 'loading', 'timeout', 'showLoader', 'showFallbackLink',
        'consentTitle', 'consentText', 'consentButtonLabel', 'rememberConsent', 'scrollToTop',
    ];

    /**
     * The option keys behind each choice in an Embed field's "options an author may set".
     *
     * @var array<string, string[]>
     */
    public const FIELD_OPTION_KEYS = [
        'mode' => ['mode'],
        'ratio' => ['ratio'],
        'height' => ['height'],
        'loading' => ['loading'],
        'caption' => ['caption'],
        'title' => ['title'],
        'align' => ['align'],
        'consent' => ['consentText'],
        'extract' => ['extract'],
    ];

    // Size and placement
    // -------------------------------------------------------------------------

    public string $mode = self::MODE_RATIO;

    /** `16:9`, `4:3`, `1:1` — anything `w:h`. Used by {@see self::MODE_RATIO}. */
    public string $ratio = '16:9';

    /** Height in pixels, for {@see self::MODE_FIXED}. */
    public int $height = 480;

    /** The height an auto/proxy frame starts at, before it has measured itself. */
    public int $minHeight = 240;

    /** A ceiling for auto height, past which the frame scrolls internally. 0 means none. */
    public int $maxHeight = 0;

    /** Any CSS width. */
    public string $width = '100%';

    public string $align = self::ALIGN_CENTER;

    /** Extra classes on the wrapper, for the site's own stylesheet to hook. */
    public string $className = '';

    /** A DOM id for the wrapper. Generated when blank. */
    public string $id = '';

    /** Shown under the frame. */
    public string $caption = '';

    // Loading
    // -------------------------------------------------------------------------

    public string $loading = self::LOADING_LAZY;

    /** How far ahead of the viewport a lazy or click embed is prepared. */
    public string $rootMargin = '200px';

    /** Show a spinner while the frame loads. */
    public bool $showLoader = true;

    /**
     * How long to wait for the frame's `load` event before showing the fallback, in
     * milliseconds. 0 waits forever.
     *
     * A frame blocked by `X-Frame-Options` fires `load` in some browsers and not others, and
     * never tells the parent why — a timeout is the only thing that can turn "permanently blank"
     * into "here is a link to the thing".
     */
    public int $timeout = 8000;

    /** Markup shown when the frame cannot load. Blank falls back to a link card. */
    public string $fallback = '';

    /** Whether the fallback card offers to open the URL in a new tab. */
    public bool $showFallbackLink = true;

    // Consent, for `loading: click`
    // -------------------------------------------------------------------------

    public string $consentTitle = '';

    public string $consentText = '';

    public string $consentButtonLabel = '';

    /** A poster image behind the consent card. Providers fill this in when they can. */
    public string $posterUrl = '';

    /** Remember the reader's choice for this provider in `localStorage`. */
    public bool $rememberConsent = false;

    /**
     * Which consent category, granted in the site's consent manager, unlocks this embed without
     * a click — and revoked, locks it again. Blank uses the provider's.
     */
    public string $consentCategory = '';

    // Security
    // -------------------------------------------------------------------------

    /**
     * `null` omits the attribute entirely, which is *not* the same as an empty array — an empty
     * `sandbox` is the most restrictive setting there is, and would break most embeds.
     *
     * @var string[]|null
     */
    public ?array $sandbox = null;

    /** @var string[] */
    public array $allow = [];

    public bool $allowFullscreen = true;

    public string $referrerPolicy = 'strict-origin-when-cross-origin';

    /** `auto`, `yes` or `no`. Legacy, but still the only thing some embeds respond to. */
    public string $scrolling = 'auto';

    /** The accessible name. Every iframe needs one; Eye derives it when the author does not. */
    public string $title = '';

    // Behaviour
    // -------------------------------------------------------------------------

    /**
     * Scroll the top of the frame back into view when in-frame navigation changes its height.
     *
     * Without this, clicking a link inside a tall auto-height frame leaves the reader halfway
     * down a page they have not seen the top of.
     */
    public bool $scrollToTop = false;

    /** Measure this selector instead of the document, when auto height can read the DOM. */
    public string $autoHeightSelector = '';

    /** Extra query parameters appended to the frame's src. */
    public array $params = [];

    // Proxy and inline
    // -------------------------------------------------------------------------

    /** Keep only what matches this CSS selector. The Advanced iFrame trick, done with a parser. */
    public string $extract = '';

    /** Drop everything matching these selectors (comma-separated). */
    public string $remove = '';

    /** CSS injected into the proxied document, or scoped to the fragment when inline. */
    public string $injectCss = '';

    /** `null` defers to the plugin setting. */
    public ?bool $stripScripts = null;

    /** What proxied links do: `blank`, `self` (stay in the frame) or `parent`. */
    public string $linkTarget = 'blank';

    /** Overrides the plugin's proxy cache duration for this embed. `null` uses the setting. */
    public ?int $cacheDuration = null;

    // -------------------------------------------------------------------------

    public function defineRules(): array
    {
        return [
            [['mode'], 'in', 'range' => self::MODES],
            [['loading'], 'in', 'range' => self::LOADINGS],
            [['align'], 'in', 'range' => self::ALIGNS],
            [['scrolling'], 'in', 'range' => ['auto', 'yes', 'no']],
            [['linkTarget'], 'in', 'range' => ['blank', 'self', 'parent']],
            [['referrerPolicy'], 'in', 'range' => self::REFERRER_POLICIES],
            [['consentCategory'], 'in', 'range' => self::CONSENT_CATEGORIES, 'skipOnEmpty' => true],
            [['height', 'minHeight', 'maxHeight', 'timeout'], 'integer', 'min' => 0],
            [['cacheDuration'], 'integer', 'min' => 0],
            [['ratio'], 'match', 'pattern' => '/^\d+(\.\d+)?\s*[:\/]\s*\d+(\.\d+)?$/', 'skipOnEmpty' => true],
            [
                [
                    'width', 'className', 'id', 'caption', 'rootMargin', 'fallback', 'consentTitle',
                    'consentText', 'consentButtonLabel', 'posterUrl', 'title', 'autoHeightSelector',
                    'extract', 'remove', 'injectCss',
                ],
                'string',
            ],
            [
                [
                    'showLoader', 'showFallbackLink', 'rememberConsent', 'allowFullscreen',
                    'scrollToTop',
                ],
                'boolean',
            ],
            [['sandbox', 'allow', 'params', 'stripScripts'], 'safe'],
        ];
    }

    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|string|null $value An options array, a JSON string, or null.
     */
    public static function fromArray(array|string|null $value): self
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        $options = new self();

        if ($value) {
            $options->apply($value);
        }

        return $options;
    }

    /**
     * Assign only the keys that exist, coercing each to the property's type.
     *
     * Everything that reaches this — a JSON column, a ref tag, a posted form, an editor's data
     * attribute — is untrusted and partial, so `setAttributes()` is not enough on its own.
     *
     * @param array<string, mixed> $values
     */
    public function apply(array $values): void
    {
        foreach ($values as $key => $value) {
            $key = (string)$key;

            if (!property_exists($this, $key) || $value === null && !in_array($key, ['sandbox', 'stripScripts', 'cacheDuration'], true)) {
                continue;
            }

            match ($key) {
                'height', 'minHeight', 'maxHeight', 'timeout' => $this->$key = max(0, (int)$value),
                'cacheDuration' => $this->cacheDuration = $value === '' || $value === null ? null : max(0, (int)$value),
                'showLoader', 'showFallbackLink', 'rememberConsent', 'allowFullscreen', 'scrollToTop' => $this->$key = self::toBool($value),
                'stripScripts' => $this->stripScripts = $value === '' || $value === null ? null : self::toBool($value),
                'sandbox' => $this->sandbox = $value === '' || $value === null ? null : self::cleanTokens($value, self::SANDBOX_TOKENS),
                'allow' => $this->allow = self::cleanTokens($value, self::ALLOW_FEATURES) ?? [],
                'params' => $this->params = is_array($value) ? $value : self::parseQuery((string)$value),
                'mode' => $this->mode = in_array($value, self::MODES, true) ? (string)$value : $this->mode,
                'loading' => $this->loading = self::normalizeLoading($value) ?? $this->loading,
                'align' => $this->align = in_array($value, self::ALIGNS, true) ? (string)$value : $this->align,
                'ratio' => $this->ratio = self::normalizeRatio((string)$value) ?? $this->ratio,
                'referrerPolicy' => $this->referrerPolicy = in_array($value, self::REFERRER_POLICIES, true) ? (string)$value : $this->referrerPolicy,
                'scrolling' => $this->scrolling = in_array($value, ['auto', 'yes', 'no'], true) ? (string)$value : $this->scrolling,
                'consentCategory' => $this->consentCategory = in_array($value, self::CONSENT_CATEGORIES, true) ? (string)$value : '',
                'linkTarget' => $this->linkTarget = in_array($value, ['blank', 'self', 'parent'], true) ? (string)$value : $this->linkTarget,
                // The values below end up inside a `style` attribute, a CSS `url()` or a
                // `<style>` element, where HTML escaping is no protection at all.
                'width' => $this->width = self::cleanCssLength($value) ?? $this->width,
                'rootMargin' => $this->rootMargin = self::cleanRootMargin($value) ?? $this->rootMargin,
                'posterUrl' => $this->posterUrl = self::cleanHttpUrl($value) ?? '',
                'injectCss' => $this->injectCss = is_scalar($value) ? self::cleanCss((string)$value) : $this->injectCss,
                'className' => $this->className = is_scalar($value) ? trim(preg_replace('/[^\w\s-]+/', '', (string)$value) ?? '') : $this->className,
                'id' => $this->id = is_scalar($value) ? (preg_replace('/[^\w-]+/', '', (string)$value) ?? '') : $this->id,
                default => $this->$key = is_scalar($value) ? (string)$value : $this->$key,
            };
        }
    }

    /** A copy with `$overrides` applied — the original is never mutated. */
    public function merge(array|string|null $overrides): self
    {
        if (!$overrides) {
            return $this;
        }

        $clone = clone $this;

        if (is_string($overrides)) {
            $decoded = json_decode($overrides, true);
            $overrides = is_array($decoded) ? $decoded : self::parseEmbedOptions($overrides);
        }

        $clone->apply($overrides);

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $out = [];

        foreach (get_object_vars($this) as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Only what differs from the defaults.
     *
     * Stored configs are diffed so that a default Eye later changes its mind about — a longer
     * timeout, a safer referrer policy — reaches embeds that never expressed an opinion.
     *
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        $defaults = new self();
        $out = [];

        foreach (get_object_vars($this) as $key => $value) {
            if ($value !== $defaults->$key) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    // Reference-tag options: `{eye:pricing:render(click,height=600)}`
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function parseEmbedOptions(string $string): array
    {
        $out = [];

        foreach (preg_split('/\s*,\s*/', trim($string), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = array_map('trim', explode('=', $part, 2));

                if ($key !== '') {
                    $out[self::normalizeKey($key)] = $value;
                }

                continue;
            }

            // A bare word is either a mode, a loading strategy, an alignment or a boolean flag —
            // `{eye:map:render(click)}` should mean what it obviously means.
            if (in_array($part, self::MODES, true)) {
                $out['mode'] = $part;
            } elseif (($loading = self::normalizeLoading($part)) !== null) {
                $out['loading'] = $loading;
            } elseif (in_array($part, self::ALIGNS, true)) {
                $out['align'] = $part;
            } elseif (self::normalizeRatio($part) !== null) {
                $out['ratio'] = self::normalizeRatio($part);
            } elseif (str_starts_with($part, 'no-')) {
                $out[self::normalizeKey(substr($part, 3))] = false;
            } else {
                $out[self::normalizeKey($part)] = true;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    public static function toEmbedOptions(array $options): string
    {
        $parts = [];

        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $parts[] = $value ? $key : "no-$key";
            } elseif (is_array($value)) {
                $parts[] = $key . '=' . implode(' ', $value);
            } else {
                $parts[] = $key . '=' . $value;
            }
        }

        return implode(',', $parts);
    }

    // Derived values the renderer and the templates ask for
    // -------------------------------------------------------------------------

    /** The CSS `aspect-ratio` value, or null when this mode does not use one. */
    public function getAspectRatio(): ?string
    {
        if ($this->mode !== self::MODE_RATIO) {
            return null;
        }

        return str_replace(':', ' / ', self::normalizeRatio($this->ratio) ?? '16:9');
    }

    /** Whether this mode puts an `<iframe>` on the page at all. */
    public function getUsesFrame(): bool
    {
        return $this->mode !== self::MODE_INLINE;
    }

    /** Whether the height is decided at runtime rather than by CSS. */
    public function getIsAutoHeight(): bool
    {
        return in_array($this->mode, [self::MODE_AUTO, self::MODE_PROXY], true);
    }

    /** Whether Eye fetches the page itself. */
    public function getIsFetched(): bool
    {
        return in_array($this->mode, self::FETCHING_MODES, true);
    }

    /** Whether the front-end runtime has anything to do for this embed. */
    public function getNeedsRuntime(): bool
    {
        return $this->getIsAutoHeight()
            || $this->loading === self::LOADING_CLICK
            || $this->timeout > 0
            || $this->scrollToTop;
    }

    public function getSandboxAttribute(): ?string
    {
        return $this->sandbox === null ? null : implode(' ', $this->sandbox);
    }

    public function getAllowAttribute(): ?string
    {
        $features = $this->allow;

        if ($this->allowFullscreen && !in_array('fullscreen', $features, true)) {
            $features[] = 'fullscreen';
        }

        return $features ? implode('; ', $features) : null;
    }

    /**
     * The one sandbox combination worth warning about.
     *
     * `allow-scripts allow-same-origin` on a frame from your *own* origin — which is what proxy
     * mode serves — lets the framed document reach out and remove its own sandbox attribute.
     */
    public function getSandboxWarning(): ?string
    {
        if ($this->sandbox === null) {
            return null;
        }

        $hasScripts = in_array('allow-scripts', $this->sandbox, true);
        $hasSameOrigin = in_array('allow-same-origin', $this->sandbox, true);

        if ($hasScripts && $hasSameOrigin && $this->mode === self::MODE_PROXY) {
            return 'allow-scripts with allow-same-origin lets a same-origin frame remove its own sandbox. Proxied pages are served from your origin, so this sandbox does nothing.';
        }

        return null;
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Only the given keys of an options array.
     *
     * @param array<string, mixed> $values
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public static function only(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }

    /** Whether a URL is one a frame, a link or a poster may point at: http(s), nothing else. */
    public static function isHttpUrl(string $url): bool
    {
        return (bool)preg_match('~^https?://[^\s/?#]+~i', trim($url));
    }

    /**
     * CSS that cannot leave the `<style>` element it is written into, and cannot pull in a
     * stylesheet from somewhere else. `<` is written as the CSS escape `\3c `, which still means
     * `<` inside a CSS string, so legitimate `content: "<"` survives.
     */
    public static function cleanCss(string $css): string
    {
        $css = str_replace('<', '\\3c ', $css);

        // An at-keyword spelled with an escape (`@\69mport`) is still `@import` to the browser,
        // and no pattern for the plain word will match it — so any at-rule with an escape in its
        // name goes too.
        $css = preg_replace('/@[\w-]*\\\\[^;{]*(?:;|\{[^}]*\}?)?/', '', $css) ?? '';

        return preg_replace('/@import\b[^;]*;?/i', '', $css) ?? '';
    }

    private static function cleanHttpUrl(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        return $value !== '' && self::isHttpUrl($value) ? $value : null;
    }

    /** `640px`, `80%`, `40rem`, `auto`, `min(100%, 720px)` — one CSS length, no declarations. */
    private static function cleanCssLength(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $value . 'px';
        }

        return preg_match('/^[\w\s.%(),+*\/-]+$/', $value) ? $value : null;
    }

    private static function cleanRootMargin(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';

        return preg_match('/^(-?\d+(\.\d+)?(px|%)?\s*){1,4}$/', $value) ? $value : null;
    }

    private static function normalizeKey(string $key): string
    {
        return StringHelper::toCamelCase($key);
    }

    private static function normalizeLoading(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));

        return match ($value) {
            'eager', 'immediate', 'now' => self::LOADING_EAGER,
            'lazy', 'defer' => self::LOADING_LAZY,
            'click', 'consent', 'ondemand', 'on-demand' => self::LOADING_CLICK,
            default => null,
        };
    }

    /** `16x9`, `16/9`, `16:9` and `1.7778` all mean the same thing to an author. */
    private static function normalizeRatio(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d+(?:\.\d+)?)\s*[:\/x]\s*(\d+(?:\.\d+)?)$/i', $value, $m)) {
            return $m[2] > 0 ? "$m[1]:$m[2]" : null;
        }

        if (is_numeric($value) && (float)$value > 0) {
            return $value . ':1';
        }

        return null;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool)$value;
    }

    /**
     * @return string[]|null
     */
    private static function cleanTokens(mixed $value, array $allowed): ?array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!is_array($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $token) {
            $token = strtolower(trim((string)$token));

            if (in_array($token, $allowed, true) && !in_array($token, $out, true)) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function parseQuery(string $value): array
    {
        parse_str(ltrim($value, '?&'), $out);

        return array_map(fn($v) => is_array($v) ? reset($v) : (string)$v, $out);
    }
}
