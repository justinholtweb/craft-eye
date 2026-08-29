<?php

namespace justinholtweb\eye\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings.
 *
 * Nothing here is `required`. A required rule makes `savePluginSettings()` fail wholesale, so a
 * fresh install could not save *any* setting until that one field was filled in — values are
 * validated for correctness when present instead.
 */
class Settings extends Model
{
    /** Whether Eye registers its own stylesheet when an embed renders. */
    public bool $registerCss = true;

    /** Whether Eye registers the front-end runtime when an embed needs one. */
    public bool $registerJs = true;

    /** Defaults handed to every new embed, as an {@see EmbedOptions} array. */
    public array $defaultOptions = [];

    /**
     * Prefer a provider's privacy-friendly host where it has one — `youtube-nocookie.com`,
     * Vimeo's `dnt=1`, a Maps embed without a signed-in session.
     *
     * On by default: an embed that quietly hands every reader to a third party is the single
     * most common complaint about iframes in a CMS.
     */
    public bool $privacyMode = true;

    /**
     * Turn bare URLs on their own line into embeds when a rich-text field is saved.
     *
     * Off by default because it rewrites author content. The editor integrations do the same
     * thing at paste time, visibly, which is the version most people want.
     */
    public bool $autoembed = false;

    // Framability
    // -------------------------------------------------------------------------

    /**
     * Whether Eye probes a URL's `X-Frame-Options` / `Content-Security-Policy: frame-ancestors`
     * and warns the author when the other site refuses to be framed.
     *
     * This is the difference between "the embed is blank and nobody knows why" and a sentence
     * telling you which header did it.
     */
    public bool $checkFramability = true;

    /** How long a framability verdict is cached, in seconds. */
    public int $framabilityCacheDuration = 86400;

    // Proxy
    // -------------------------------------------------------------------------

    /**
     * Whether `proxy` and `inline` modes may fetch anything at all.
     *
     * Off by default, and deliberately a settings-screen decision rather than a per-embed one:
     * turning it on gives content authors a server-side HTTP client, so it belongs to whoever
     * administers the site. With an empty {@see self::$allowedHosts} it stays a no-op.
     */
    public bool $proxyEnabled = false;

    /**
     * The only hosts Eye will ever fetch. Exact (`example.com`) or one wildcard label
     * (`*.example.com`, which also matches `example.com`).
     *
     * There is no "allow everything" value on purpose.
     *
     * @var string[]
     */
    public array $allowedHosts = [];

    /** How long proxied HTML is cached, in seconds. */
    public int $proxyCacheDuration = 900;

    /** Connect + transfer timeout for a proxied fetch, in seconds. */
    public int $proxyTimeout = 10;

    /** Hard cap on a proxied response, in bytes. Anything larger is abandoned mid-stream. */
    public int $proxyMaxBytes = 2097152;

    /** How many redirects a proxied fetch may follow. Each hop is re-validated. */
    public int $proxyMaxRedirects = 3;

    /** The user agent Eye identifies itself with. Never the reader's. */
    public string $proxyUserAgent = 'Mozilla/5.0 (compatible; CraftEye/5.0; +https://github.com/justinholtweb/craft-eye)';

    /**
     * Strip `<script>` from proxied HTML.
     *
     * On by default. `inline` mode splices the result into your own document, where a remote
     * script would run with your origin's privileges; `proxy` mode is sandboxed, but the same
     * default is the safer surprise.
     */
    public bool $proxyStripScripts = true;

    /** An HTML Purifier config file in `config/htmlpurifier/`, without the extension. */
    public ?string $purifierConfig = null;

    public function defineRules(): array
    {
        return [
            [
                [
                    'registerCss', 'registerJs', 'privacyMode', 'autoembed', 'checkFramability',
                    'proxyEnabled', 'proxyStripScripts',
                ],
                'boolean',
            ],
            [['framabilityCacheDuration', 'proxyCacheDuration'], 'integer', 'min' => 0],
            [['proxyTimeout'], 'integer', 'min' => 1, 'max' => 120],
            [['proxyMaxBytes'], 'integer', 'min' => 1024],
            [['proxyMaxRedirects'], 'integer', 'min' => 0, 'max' => 10],
            [['proxyUserAgent', 'purifierConfig'], 'string'],
            [['defaultOptions'], 'safe'],
            [['allowedHosts'], 'validateAllowedHosts', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Yii skips an inline validator when the attribute is empty — and an empty array counts as
     * empty — so the one case this rule exists to explain (proxy on, allowlist blank) is exactly
     * the one it would never see. Hence `skipOnEmpty => false`.
     */
    public function validateAllowedHosts(string $attribute): void
    {
        $clean = [];

        foreach ($this->allowedHosts as $host) {
            // Craft's editable table posts rows, not strings. Normalising here rather than in a
            // setter keeps the property a plain array for everything that reads it.
            if (is_array($host)) {
                $host = $host['host'] ?? '';
            }

            $host = strtolower(trim((string)$host));

            if ($host === '') {
                continue;
            }

            if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $host)) {
                $this->addError($attribute, Craft::t('eye', '“{host}” is not a host name. Use example.com or *.example.com.', [
                    'host' => $host,
                ]));

                continue;
            }

            $clean[] = $host;
        }

        $this->allowedHosts = array_values(array_unique($clean));

        if ($this->proxyEnabled && !$this->allowedHosts) {
            $this->addWarning = Craft::t('eye', 'The proxy is enabled but no hosts are allowed, so nothing will be fetched.');
        }
    }

    /** Set by {@see self::validateAllowedHosts()}; shown on the settings screen, not an error. */
    public ?string $addWarning = null;

    public function attributeLabels(): array
    {
        return [
            'registerCss' => Craft::t('eye', 'Register stylesheet'),
            'registerJs' => Craft::t('eye', 'Register runtime'),
            'privacyMode' => Craft::t('eye', 'Privacy mode'),
            'autoembed' => Craft::t('eye', 'Auto-embed URLs on save'),
            'checkFramability' => Craft::t('eye', 'Check whether URLs allow framing'),
            'framabilityCacheDuration' => Craft::t('eye', 'Framability cache duration'),
            'proxyEnabled' => Craft::t('eye', 'Enable the proxy'),
            'allowedHosts' => Craft::t('eye', 'Allowed hosts'),
            'proxyCacheDuration' => Craft::t('eye', 'Proxy cache duration'),
            'proxyTimeout' => Craft::t('eye', 'Proxy timeout'),
            'proxyMaxBytes' => Craft::t('eye', 'Proxy response limit'),
            'proxyMaxRedirects' => Craft::t('eye', 'Proxy redirect limit'),
            'proxyUserAgent' => Craft::t('eye', 'Proxy user agent'),
            'proxyStripScripts' => Craft::t('eye', 'Strip scripts from proxied HTML'),
            'purifierConfig' => Craft::t('eye', 'HTML Purifier config'),
        ];
    }

    /**
     * The allowed hosts as editable-table rows.
     *
     * @return array<int, array{host: string}>
     */
    public function getAllowedHostRows(): array
    {
        return array_map(fn($host) => ['host' => is_array($host) ? ($host['host'] ?? '') : (string)$host], $this->allowedHosts);
    }

    public function getDefaultEmbedOptions(): EmbedOptions
    {
        return EmbedOptions::fromArray($this->defaultOptions ?: null);
    }

    /** Whether the proxy can actually do anything. Both halves are needed. */
    public function getProxyIsUsable(): bool
    {
        return $this->proxyEnabled && $this->allowedHosts !== [];
    }
}
