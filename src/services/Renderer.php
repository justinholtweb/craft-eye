<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\ProviderMatch;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\web\assets\runtime\RuntimeAsset;
use Throwable;
use Twig\Markup;

/**
 * Turns an embed into markup.
 *
 * One path for every surface — the element, a ref tag, an inline field value, a Twig call — so
 * there is a single answer to "what does an Eye embed look like on the page", and a site that
 * wants a different one overrides a single template.
 */
class Renderer extends Component
{
    /** A site template of this name wins over Eye's own. */
    public const SITE_TEMPLATE = '_eye/embed';

    private int $counter = 0;

    /** Registered once per request, however many embeds there are. */
    private bool $assetsRegistered = false;

    /**
     * @param array<string, mixed> $overrides
     */
    public function renderEmbed(Embed $embed, array $overrides = []): Markup
    {
        // A disabled embed renders nothing — the point of the switch is to take a third party
        // off every page at once without hunting down the references.
        if (!$embed->enabled) {
            return new Markup('', Craft::$app->charset);
        }

        $options = $embed->getOptions()->merge($overrides);

        return $this->renderUrl((string)$embed->url, $options, [
            'embed' => $embed,
            'handle' => $embed->handle,
            'label' => $embed->title,
            'uid' => $embed->uid,
        ]);
    }

    /**
     * Render a URL that has no element behind it — an inline field value, or a template calling
     * `craft.eye.url()`.
     *
     * @param array<string, mixed> $context
     */
    public function renderUrl(string $url, EmbedOptions $options, array $context = []): Markup
    {
        $url = trim($url);

        if ($url === '') {
            return new Markup('', Craft::$app->charset);
        }

        // The URL becomes a frame's `src` and the fallback link's `href`, where `javascript:` runs.
        // Library embeds are validated on save; inline values and template calls are not.
        if (!EmbedOptions::isHttpUrl($url)) {
            Craft::warning('Eye refused to render a URL that is not http(s): ' . $url, Plugin::LOG_CATEGORY);

            return new Markup('', Craft::$app->charset);
        }

        $plugin = Plugin::getInstance();
        $match = $plugin->providers->match($url);

        // Provider defaults sit *under* the embed's own options, never over them: the provider
        // knows what YouTube needs, the author knows what this page needs.
        if ($match) {
            $options = EmbedOptions::fromArray($match->getOptionDefaults())->merge($options->toStorageArray());
        }

        $embedUrl = $match->embedUrl ?? $url;

        // "Hold every third-party embed for consent" overrides the embed's own loading setting,
        // for every frame that would reach another origin. A proxied frame is on this origin but
        // still carries the other site's images and links. Inline mode has no frame to hold.
        if (
            $plugin->getSettings()->consentForAllEmbeds
            && $options->mode !== EmbedOptions::MODE_INLINE
            && ($options->mode === EmbedOptions::MODE_PROXY || !$this->isSameOrigin($embedUrl))
        ) {
            $options = $options->merge(['loading' => EmbedOptions::LOADING_CLICK]);
        }
        $id = Html::id((string)($context['id'] ?? sprintf('eye-%s', $context['handle'] ?? ++$this->counter)));

        $variables = array_merge([
            'embed' => null,
            'handle' => null,
            'label' => '',
            'uid' => null,
        ], $context, [
            'id' => $id,
            'url' => $url,
            'src' => $this->sourceUrl($embedUrl, $options, $context),
            'options' => $options,
            'provider' => $match?->provider,
            'match' => $match,
            'frameAttributes' => $this->frameAttributes($options, $context),
            'wrapperStyle' => $this->wrapperStyle($options),
            'classes' => $this->classes($options, $match?->provider->handle ?? 'generic'),
            'config' => $this->runtimeConfig($options, $embedUrl, $match),
            'consent' => $this->consent($options, $match, $url),
            'fallback' => $this->fallback($options, $match, $url, (string)($context['label'] ?? '')),
            'inlineHtml' => null,
            'inlineError' => null,
            'texts' => $this->texts(),
        ]);

        if ($options->mode === EmbedOptions::MODE_INLINE) {
            [$variables['inlineHtml'], $variables['inlineError']] = $this->inlineHtml($url, $options);
        }

        $this->registerAssets($options);

        return new Markup($this->renderTemplate($variables), Craft::$app->charset);
    }

    // -------------------------------------------------------------------------

    /**
     * Where the frame points.
     *
     * For every mode but `proxy` that is the provider's embed URL. For `proxy` it is a route on
     * *this* site, which is the entire trick: same origin means the height can be measured, the
     * CSS can be injected, and `X-Frame-Options` never enters into it.
     */
    private function sourceUrl(string $embedUrl, EmbedOptions $options, array $context): string
    {
        if ($options->mode === EmbedOptions::MODE_PROXY) {
            return Plugin::getInstance()->proxyRoutes->urlFor($embedUrl, $options, $context['uid'] ?? null);
        }

        if (!$options->params) {
            return $embedUrl;
        }

        return UrlHelper::urlWithParams($embedUrl, $options->params);
    }

    /**
     * @return array<string, mixed>
     */
    private function frameAttributes(EmbedOptions $options, array $context): array
    {
        $attributes = [
            'class' => 'eye-frame',
            'title' => $options->title ?: ($context['label'] ?? '') ?: Craft::t('eye', 'Embedded content'),
            'referrerpolicy' => $options->referrerPolicy,
            'scrolling' => $options->scrolling === 'auto' ? null : $options->scrolling,
            // Native lazy loading costs nothing and works without JavaScript. The runtime's
            // IntersectionObserver is for click-to-load, which native loading cannot express.
            'loading' => $options->loading === EmbedOptions::LOADING_EAGER ? 'eager' : 'lazy',
            'frameborder' => '0',
        ];

        if (($sandbox = $options->getSandboxAttribute()) !== null) {
            $attributes['sandbox'] = $sandbox;
        }

        if (($allow = $options->getAllowAttribute()) !== null) {
            $attributes['allow'] = $allow;
        }

        if ($options->allowFullscreen) {
            $attributes['allowfullscreen'] = true;
        }

        return array_filter($attributes, fn($value) => $value !== null);
    }

    private function wrapperStyle(EmbedOptions $options): string
    {
        $rules = [];

        if ($options->width !== '' && $options->width !== '100%') {
            $rules[] = 'width:' . $options->width;
        }

        $ratio = $options->getAspectRatio();

        if ($ratio !== null) {
            $rules[] = 'aspect-ratio:' . $ratio;
        } elseif ($options->mode === EmbedOptions::MODE_FIXED) {
            $rules[] = 'height:' . $options->height . 'px';
        } elseif ($options->getIsAutoHeight()) {
            // A starting height, so the page does not reflow from zero the moment the frame
            // reports back — and so a reader with JavaScript off still sees something.
            $rules[] = 'height:' . max(1, $options->minHeight) . 'px';
        }

        if ($options->maxHeight > 0) {
            $rules[] = 'max-height:' . $options->maxHeight . 'px';
        }

        return implode(';', $rules);
    }

    /** @return string[] */
    private function classes(EmbedOptions $options, string $provider): array
    {
        $classes = [
            'eye',
            'eye--' . $options->mode,
            'eye--' . $options->loading,
            'eye--align-' . $options->align,
            'eye--provider-' . preg_replace('/[^a-z0-9]+/', '', $provider),
        ];

        if ($options->className !== '') {
            foreach (preg_split('/\s+/', $options->className, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * What the front-end runtime needs, as JSON on the wrapper.
     *
     * Only what the runtime actually reads. Everything presentational has already become CSS by
     * the time it gets here, and shipping the rest would put an author's notes in the page.
     */
    private function runtimeConfig(EmbedOptions $options, string $embedUrl, ?ProviderMatch $match = null): string
    {
        $config = [
            'mode' => $options->mode,
            'loading' => $options->loading,
            'timeout' => $options->timeout,
        ];

        if ($options->getIsAutoHeight()) {
            $config['auto'] = [
                'min' => $options->minHeight,
                'max' => $options->maxHeight,
                'selector' => $options->autoHeightSelector ?: null,
                'scrollToTop' => $options->scrollToTop,
                // A proxied frame is on this origin by construction, so the runtime can skip the
                // handshake and read the document directly.
                //
                // Unless its scripts are kept: then it is sandboxed onto an opaque origin (see
                // ProxyController) and reports its height with the injected child script instead.
                'sameOrigin' => $options->mode === EmbedOptions::MODE_PROXY
                    ? Plugin::getInstance()->proxy->stripsScripts($options)
                    : $this->isSameOrigin($embedUrl),
            ];
        }

        if ($options->loading === EmbedOptions::LOADING_CLICK) {
            $consent = Plugin::getInstance()->consent;

            $config['consent'] = [
                'remember' => $options->rememberConsent,
                'key' => $options->rememberConsent ? 'eye:consent:' . parse_url($embedUrl, PHP_URL_HOST) : null,
                // What the site's consent manager has to grant for the card to open by itself,
                // and which manager to listen to. Both are facts about the site, not the reader,
                // so they are safe in a statically cached page; the reader's answer is only ever
                // read in the browser.
                'category' => $consent->categoryFor($options, $match),
                'manager' => $consent->manager(),
                // Klaro consents per service; a service named after the provider wins there.
                'provider' => $match?->provider->handle ?? 'generic',
            ];
        }

        if ($options->rootMargin !== '') {
            $config['rootMargin'] = $options->rootMargin;
        }

        return Json::encode($config);
    }

    private function isSameOrigin(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return true;
        }

        $siteHost = parse_url((string)Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), PHP_URL_HOST);

        return is_string($siteHost) && strcasecmp($host, $siteHost) === 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function consent(EmbedOptions $options, $match, string $url): array
    {
        $provider = $match?->provider;
        $name = $provider && !$provider->getIsGeneric() ? $provider->name : (parse_url($url, PHP_URL_HOST) ?: $url);

        return [
            'title' => $options->consentTitle ?: Craft::t('eye', 'Load content from {name}?', ['name' => $name]),
            'text' => $options->consentText
                ?: ($provider?->consentText ?: Craft::t('eye', 'This content is hosted by {name}. Loading it will share your IP address with them.', ['name' => $name])),
            'button' => $options->consentButtonLabel ?: Craft::t('eye', 'Load content'),
            // Through Posters: a self-hosted copy, or nothing — a hotlinked thumbnail would hand
            // the reader to the provider before they have agreed to anything.
            'poster' => $this->cssUrl(Plugin::getInstance()->posters->displayUrl($options->posterUrl ?: ($match->posterUrl ?? ''))),
            'name' => $name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(EmbedOptions $options, $match, string $url, string $label): array
    {
        $provider = $match?->provider;
        $name = $provider && !$provider->getIsGeneric() ? $provider->name : (parse_url($url, PHP_URL_HOST) ?: $url);

        return [
            // Purified, whoever wrote it: the template prints it raw.
            'html' => $options->fallback !== '' ? Plugin::getInstance()->proxy->purify($options->fallback) : '',
            'showLink' => $options->showFallbackLink,
            'url' => $url,
            'title' => $label ?: Craft::t('eye', 'This content could not be loaded'),
            'text' => Craft::t('eye', '{name} would not load in this page.', ['name' => $name]),
            'linkLabel' => Craft::t('eye', 'Open in a new tab'),
        ];
    }

    /**
     * A URL that is safe inside CSS `url(…)` in a `style` attribute: http(s) or root-relative, with every
     * character that could close the `url()` or the declaration percent-encoded.
     */
    private function cssUrl(string $url): string
    {
        // http(s), or a root-relative path on this site — a self-hosted poster in a volume whose
        // base URL is `/uploads` comes back like that. Never `//host`, which is another origin.
        if ($url === '' || (!EmbedOptions::isHttpUrl($url) && !preg_match('~^/(?![/\\\\])~', $url))) {
            return '';
        }

        return strtr($url, [
            '"' => '%22', "'" => '%27', '(' => '%28', ')' => '%29', '\\' => '%5C',
            ' ' => '%20', ';' => '%3B', "\n" => '', "\r" => '',
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null} The fragment, or an explanation of why not.
     */
    private function inlineHtml(string $url, EmbedOptions $options): array
    {
        try {
            return [Plugin::getInstance()->proxy->fragment($url, $options)->html, null];
        } catch (Throwable $e) {
            Craft::warning("Eye could not inline $url: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            // Devs get the reason; readers get the fallback card. An author debugging a blank
            // box is exactly who this plugin exists for, so devMode says what went wrong.
            return [null, Craft::$app->getConfig()->getGeneral()->devMode ? $e->getMessage() : ''];
        }
    }

    /** @return array<string, string> */
    private function texts(): array
    {
        return [
            'loading' => Craft::t('eye', 'Loading…'),
            'caption' => Craft::t('eye', 'Caption'),
        ];
    }

    private function registerAssets(EmbedOptions $options): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();

        if ($this->assetsRegistered) {
            return;
        }

        // A console request or an element API response has no head to register into, and a
        // rendered embed can legitimately end up in both.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        if (!$settings->registerCss && !$settings->registerJs) {
            return;
        }

        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $view->registerAssetBundle(RuntimeAsset::class);
            $this->assetsRegistered = true;
        } catch (Throwable $e) {
            Craft::warning('Eye could not register its assets: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }

    /**
     * A site override wins, and is looked for in site template mode so it can include the site's
     * own partials.
     */
    private function renderTemplate(array $variables): string
    {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if ($view->doesTemplateExist(self::SITE_TEMPLATE)) {
                return $view->renderTemplate(self::SITE_TEMPLATE, $variables);
            }

            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate('eye/_render/embed', $variables);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }
}
