<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use HTMLPurifier;
use HTMLPurifier_Config;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\ProxyResult;
use justinholtweb\eye\Plugin;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExceptionInterface as CssSelectorException;
use Throwable;
use yii\base\Exception;

/**
 * Fetch a page, keep the part that matters, and serve it from this site's own origin.
 *
 * This is the answer to the two things an iframe cannot do on its own: a page that sends
 * `X-Frame-Options` will not render in a frame at all, and a page that does render gives the
 * framing site no way to measure it, restyle it, or show only part of it. Moving the content
 * onto your origin solves all four at once — which is exactly why it is fenced in by
 * {@see Fetcher}.
 *
 * Two shapes come out:
 *
 * - **document** — a whole HTML page for {@see EmbedOptions::MODE_PROXY} to point a frame at.
 *   Sandboxed by being in a frame, so the remote page's own CSS cannot touch yours.
 * - **fragment** — sanitised markup for {@see EmbedOptions::MODE_INLINE} to splice straight into
 *   the document. No frame, so it inherits your typography — and so it goes through HTML
 *   Purifier first, without exception.
 */
class Proxy extends Component
{
    /** Bump when the transformation changes, so old cache entries are not reused. */
    private const CACHE_VERSION = 2;

    private const CACHE_PREFIX = 'eye:proxy:';

    private ?CssSelectorConverter $converter = null;

    /**
     * A whole document, for a frame to point at.
     *
     * @throws Exception
     */
    public function document(string $url, EmbedOptions $options): ProxyResult
    {
        return $this->render($url, $options, false);
    }

    /**
     * A sanitised fragment, for splicing into the page.
     *
     * @throws Exception
     */
    public function fragment(string $url, EmbedOptions $options): ProxyResult
    {
        return $this->render($url, $options, true);
    }

    /** Whether this embed's proxied page has its scripts removed. */
    public function stripsScripts(EmbedOptions $options): bool
    {
        return $options->stripScripts ?? Plugin::getInstance()->getSettings()->proxyStripScripts;
    }

    public function forget(string $url, EmbedOptions $options): void
    {
        $cache = Craft::$app->getCache();

        foreach ([true, false] as $inline) {
            $cache->delete($this->cacheKey($url, $options, $inline));
        }
    }

    // -------------------------------------------------------------------------

    /**
     * @throws Exception
     */
    private function render(string $url, EmbedOptions $options, bool $inline): ProxyResult
    {
        $settings = Plugin::getInstance()->getSettings();
        $key = $this->cacheKey($url, $options, $inline);
        $duration = $options->cacheDuration ?? $settings->proxyCacheDuration;
        $cache = Craft::$app->getCache();

        if ($duration > 0) {
            $cached = $cache->get($key);

            if (is_array($cached)) {
                $result = new ProxyResult($cached);
                $result->cached = true;

                return $result;
            }
        }

        $response = Plugin::getInstance()->fetcher->fetch($url);
        $result = $this->transform($response->getUtf8Body(), $response->url, $options, $inline);

        if ($duration > 0) {
            $cache->set($key, $result->toArray(), $duration);
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    private function transform(string $html, string $url, EmbedOptions $options, bool $inline): ProxyResult
    {
        $settings = Plugin::getInstance()->getSettings();
        $result = new ProxyResult(['url' => $url]);

        if (trim($html) === '') {
            throw new Exception(Craft::t('eye', 'The page came back empty.'));
        }

        $dom = $this->parse($html);
        $xpath = new DOMXPath($dom);

        $result->title = $this->textOf($xpath, '//title');

        // The base has to be settled before anything else touches a URL: a page with its own
        // `<base href>` means every relative link on it is relative to *that*, not to where the
        // page was fetched from.
        $base = $this->resolveBase($xpath, $url);
        $this->removeNodes($xpath, '//base');

        $strip = $this->stripsScripts($options);

        if ($strip) {
            // The response's CSP sandbox is what actually stops script here (see
            // ProxyController); this is the second layer, so a stripped page has nothing left
            // that would run if that header were ever lost on the way to the browser.
            $this->removeNodes($xpath, '//script');
            $this->removeNodes($xpath, '//noscript');
            $this->unwrapNodes($xpath, '//object|//embed|//applet');
            $this->stripEventHandlers($xpath);
        }

        // Always, whether or not scripts are stripped: a frame busting itself out of the page is
        // not something the site owner asked for.
        $this->removeNodes($xpath, '//meta[translate(@http-equiv, "REFSH", "refsh")="refresh"]');

        if ($options->remove !== '') {
            $this->removeBySelector($xpath, $options->remove);
        }

        $this->absolutizeUrls($xpath, $base);
        $this->retargetLinks($xpath, $options);

        if ($options->extract !== '') {
            $result->extracted = $this->extract($xpath, $options->extract);
        }

        if ($inline) {
            $result->html = $this->buildFragment($dom, $xpath, $options);

            return $result;
        }

        $result->html = $this->buildDocument($dom, $xpath, $options, $base);

        return $result;
    }

    /**
     * Parse messy real-world HTML without libxml deciding it knows better about the encoding.
     *
     * The body has already been converted to UTF-8, so the job here is only to stop libxml
     * guessing — hence the XML declaration, which is removed again immediately.
     */
    private function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }

        return $dom;
    }

    private function resolveBase(DOMXPath $xpath, string $url): string
    {
        $nodes = $xpath->query('//base[@href]');

        if ($nodes && $nodes->length > 0) {
            /** @var DOMElement $node */
            $node = $nodes->item(0);
            $href = trim($node->getAttribute('href'));

            if ($href !== '') {
                return $this->absolute($href, $url) ?? $url;
            }
        }

        return $url;
    }

    /**
     * Every relative URL in the document becomes absolute against the page it came from.
     *
     * Without this the proxied page asks *this* site for its stylesheets, images and links, and
     * gets a wall of 404s — the single most visible way a naive proxy fails.
     */
    private function absolutizeUrls(DOMXPath $xpath, string $base): void
    {
        $attributes = ['href', 'src', 'action', 'poster', 'data-src', 'longdesc', 'cite', 'formaction'];

        foreach ($attributes as $attribute) {
            $nodes = $xpath->query("//*[@$attribute]");

            foreach ($nodes ?: [] as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $value = trim($node->getAttribute($attribute));

                // An in-page anchor must stay relative or it stops being an in-page anchor.
                if ($value === '' || str_starts_with($value, '#')) {
                    continue;
                }

                $absolute = $this->absolute($value, $base);

                if ($absolute !== null) {
                    $node->setAttribute($attribute, $absolute);
                }
            }
        }

        // srcset is a list of URLs with descriptors, so it needs its own pass.
        foreach ($xpath->query('//*[@srcset]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $parts = [];

            foreach (explode(',', $node->getAttribute('srcset')) as $candidate) {
                $candidate = trim($candidate);

                if ($candidate === '') {
                    continue;
                }

                $bits = preg_split('/\s+/', $candidate, 2) ?: [];
                $absolute = $this->absolute($bits[0], $base);
                $parts[] = trim(($absolute ?? $bits[0]) . ' ' . ($bits[1] ?? ''));
            }

            $node->setAttribute('srcset', implode(', ', $parts));
        }

        // …and so does `url()` inside CSS, in both `<style>` blocks and style attributes.
        foreach ($xpath->query('//style') ?: [] as $node) {
            $node->textContent = $this->absolutizeCss($node->textContent, $base);
        }

        foreach ($xpath->query('//*[@style]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->setAttribute('style', $this->absolutizeCss($node->getAttribute('style'), $base));
            }
        }
    }

    private function absolutizeCss(string $css, string $base): string
    {
        return preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function(array $m) use ($base) {
            $url = trim($m[2]);

            if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                return $m[0];
            }

            return 'url(' . $m[1] . ($this->absolute($url, $base) ?? $url) . $m[1] . ')';
        }, $css) ?? $css;
    }

    /**
     * Resolve one URL against a base. Returns null for anything that is not a fetchable
     * location — `javascript:`, `data:`, `mailto:` — which are left exactly as they were.
     */
    private function absolute(string $url, string $base): ?string
    {
        $url = trim($url);

        if ($url === '' || preg_match('~^(https?:)?//~i', $url)) {
            return preg_match('~^//~', $url) ? 'https:' . $url : $url;
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return null;
        }

        $parts = parse_url($base);

        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int)strrpos($path, '/') + 1) ?: '/';

        return $origin . $this->normalizePath($directory . $url);
    }

    /** Flatten `../` so a proxied path cannot climb out of its own directory. */
    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
    }

    /**
     * Decide where a proxied link goes.
     *
     * The default is a new tab, because the alternative — a reader navigating *inside* the frame
     * with no address bar, no back button and no way to tell they have left — is the thing that
     * makes proxied content feel broken.
     */
    private function retargetLinks(DOMXPath $xpath, EmbedOptions $options): void
    {
        $target = match ($options->linkTarget) {
            'self' => '_self',
            'parent' => '_top',
            default => '_blank',
        };

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (str_starts_with(trim($node->getAttribute('href')), '#')) {
                continue;
            }

            $node->setAttribute('target', $target);

            if ($target === '_blank') {
                $node->setAttribute('rel', trim($node->getAttribute('rel') . ' noopener noreferrer'));
            }
        }

        foreach ($xpath->query('//form') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->setAttribute('target', $target === '_self' ? '_self' : '_blank');
            }
        }
    }

    /**
     * Keep only what the selector matches.
     *
     * @return int How many nodes matched — zero means the author's selector is wrong, and that
     * is worth surfacing rather than silently rendering an empty box.
     */
    private function extract(DOMXPath $xpath, string $selector): int
    {
        $nodes = $this->query($xpath, $selector);

        if ($nodes === null || $nodes === []) {
            return 0;
        }

        $body = $xpath->query('//body')->item(0);

        if (!$body instanceof DOMElement) {
            return 0;
        }

        // Detach the keepers *before* emptying the body, or removing their ancestors takes them
        // with it.
        $keep = [];

        foreach ($nodes as $node) {
            $keep[] = $node->parentNode?->removeChild($node) ?? $node;
        }

        while ($body->firstChild) {
            $body->removeChild($body->firstChild);
        }

        foreach ($keep as $node) {
            $body->appendChild($node);
        }

        return count($keep);
    }

    private function removeBySelector(DOMXPath $xpath, string $selectors): void
    {
        foreach ($this->query($xpath, $selectors) ?? [] as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * @return DOMNode[]|null Null when the selector will not compile — a bad selector should
     * leave the page alone, not empty it.
     */
    private function query(DOMXPath $xpath, string $selectors): ?array
    {
        $this->converter ??= new CssSelectorConverter();
        $found = [];

        foreach (explode(',', $selectors) as $selector) {
            $selector = trim($selector);

            if ($selector === '') {
                continue;
            }

            try {
                $expression = $this->converter->toXPath($selector);
            } catch (CssSelectorException) {
                continue;
            }

            $nodes = $xpath->query($expression);

            foreach ($nodes ?: [] as $node) {
                $found[spl_object_id($node)] = $node;
            }
        }

        return $found ? array_values($found) : null;
    }

    /**
     * Remove elements but keep what is inside them.
     *
     * For `<object>` that is the fallback content HTML shows when there is no plugin. For
     * `<embed>` it matters more than it looks: libxml's HTML 4 parser does not know `<embed>` is a
     * void element, so it nests the *rest of the page* inside it, and removing the node would take
     * all of that with it.
     */
    private function unwrapNodes(DOMXPath $xpath, string $expression): void
    {
        foreach (array_reverse(iterator_to_array($xpath->query($expression) ?: [])) as $node) {
            $parent = $node->parentNode;

            if ($parent === null) {
                continue;
            }

            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }

            $parent->removeChild($node);
        }
    }

    private function removeNodes(DOMXPath $xpath, string $expression): void
    {
        foreach (iterator_to_array($xpath->query($expression) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Everything that runs script without a `<script>` element: `onclick` and friends, `srcdoc`
     * (a whole document in an attribute), and `javascript:` / `vbscript:` / `data:` URLs in any
     * attribute a browser will follow — `<iframe src>`, `<form action>`, `<a href>`, SVG's
     * `xlink:href`. A `data:` image is left alone; it cannot run anything.
     */
    private function stripEventHandlers(DOMXPath $xpath): void
    {
        foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $element = $attribute->parentNode;

            if (!$element instanceof DOMElement) {
                continue;
            }

            if (preg_match('/^on\w+/', $name) || $name === 'srcdoc') {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            $value = preg_replace('/[\x00-\x20]+/', '', html_entity_decode((string)$attribute->nodeValue)) ?? '';

            if (!preg_match('/^(javascript|vbscript|data):/i', $value)) {
                continue;
            }

            if (strtolower($element->nodeName) === 'img' && in_array($name, ['src', 'srcset'], true) && preg_match('/^data:image\//i', $value)) {
                continue;
            }

            $element->removeAttribute($attribute->nodeName);
        }
    }

    private function textOf(DOMXPath $xpath, string $expression): string
    {
        // `query()` returns false for a bad expression, and `?->` does not catch false.
        $nodes = $xpath->query($expression);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node ? trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '') : '';
    }

    // Output
    // -------------------------------------------------------------------------

    private function buildDocument(DOMDocument $dom, DOMXPath $xpath, EmbedOptions $options, string $base): string
    {
        $head = $xpath->query('//head')->item(0);

        if ($head instanceof DOMElement) {
            // Put the base back, pointing at the real origin, so anything Eye did not rewrite —
            // a URL assembled by CSS, a lazy-loading attribute nobody standardised — still
            // resolves against the site it came from rather than against this one.
            $baseNode = $dom->createElement('base');
            $baseNode->setAttribute('href', $base);
            $head->insertBefore($baseNode, $head->firstChild);

            $style = $dom->createElement('style');
            $style->appendChild($dom->createTextNode($this->frameCss($options)));
            $head->appendChild($style);

            // A page that keeps its scripts is served sandboxed onto an opaque origin, so the
            // parent cannot measure it; the child script reports its height instead.
            if (!$this->stripsScripts($options) && ($child = $this->childScript()) !== null) {
                $script = $dom->createElement('script');
                $script->appendChild($dom->createTextNode($child));
                $head->appendChild($script);
            }
        }

        $html = $dom->saveHTML();

        return is_string($html) ? $html : '';
    }

    /**
     * The fragment path, which is the one that ends up inside your own document — so it is
     * purified, never trusted, and always scoped.
     */
    private function buildFragment(DOMDocument $dom, DOMXPath $xpath, EmbedOptions $options): string
    {
        $body = $xpath->query('//body')->item(0);
        $html = '';

        if ($body instanceof DOMElement) {
            foreach ($body->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }
        }

        $html = $this->purify($html);
        $scope = 'eye-inline-' . StringHelper::randomString(8);
        $css = '';

        if ($options->injectCss !== '') {
            // Scoped by prefixing the wrapper's class, so an author's `body { … }` cannot repaint
            // the page around it. Cleaned again here because this lands in the site's own page.
            $css = '<style>' . $this->scopeCss(EmbedOptions::cleanCss($this->flattenCss($options->injectCss)), ".$scope") . '</style>';
        }

        return sprintf('<div class="eye-inline %s">%s%s</div>', $scope, $css, $html);
    }

    private function frameCss(EmbedOptions $options): string
    {
        $css = "html,body{margin:0;padding:0;}img,video,iframe,table{max-width:100%;}";

        if ($options->injectCss !== '') {
            $css .= "\n" . EmbedOptions::cleanCss($options->injectCss);
        }

        return $css;
    }

    private function childScript(): ?string
    {
        $path = Craft::getAlias('@justinholtweb/eye/web/assets/runtime/dist/eye-child.js');
        $js = $path && is_file($path) ? file_get_contents($path) : false;

        // `</script` cannot appear in it today; make sure it never ends the element early.
        return is_string($js) ? str_ireplace('</script', '<\/script', $js) : null;
    }

    /**
     * Inline CSS lands in the site's own page, so it gets no at-rules and no escapes at all.
     *
     * The scoper only prefixes top-level selectors: one nested in `@media` or `@supports` would
     * reach the whole page, where an attribute selector and a background URL can read a CSRF
     * token out one character at a time. And an escape can spell an at-keyword (`@\69mport`)
     * that no pattern for `@import` will ever match. Rules that need either belong in a
     * stylesheet on the site.
     */
    private function flattenCss(string $css): string
    {
        $css = str_replace('\\', '', $css);

        // A block at-rule takes its block with it, nested braces included; a statement one ends
        // at its semicolon.
        do {
            $css = preg_replace('/@[^{};]*\{(?:[^{}]|\{[^{}]*\})*\}|@[^{};]*;?/', '', $css, -1, $count) ?? '';
        } while ($count > 0 && str_contains($css, '@'));

        $css = str_replace('@', '', $css);

        // Whatever is left must be flat rule sets. Anything still nested — braces the pattern
        // above could not pair up — would put a selector out of the scoper's reach, so the whole
        // block is refused rather than half-trusted.
        $depth = 0;

        foreach (str_split($css) as $char) {
            $depth += match ($char) {
                '{' => 1, '}' => -1, default => 0
            };

            if ($depth < 0 || $depth > 1) {
                return '';
            }
        }

        return $depth === 0 ? $css : '';
    }

    /**
     * Prefix every selector in a block of CSS.
     *
     * Not a CSS parser — it does not need to be. It leaves at-rules' preludes alone, recurses
     * into their blocks, and rewrites the `body`/`html` selectors an author copied from the
     * remote site's own stylesheet into the wrapper itself.
     */
    private function scopeCss(string $css, string $scope): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;

        return preg_replace_callback('/(^|\})([^{}@]+)\{/m', function(array $m) use ($scope) {
            $selectors = array_map(function(string $selector) use ($scope) {
                $selector = trim($selector);

                if ($selector === '') {
                    return '';
                }

                if (in_array($selector, ['html', 'body', ':root', 'html body'], true)) {
                    return $scope;
                }

                return "$scope $selector";
            }, explode(',', $m[2]));

            return $m[1] . implode(', ', array_filter($selectors)) . '{';
        }, $css) ?? $css;
    }

    /**
     * Inline mode splices remote HTML into this site's own document, where a remote script would
     * run with this origin's privileges. Purifier is not optional here — nor for an embed's
     * fallback markup, which the renderer prints raw.
     */
    public function purify(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;

        $options = [
            'Attr.AllowedFrameTargets' => ['_blank', '_self', '_top'],
            'Attr.EnableID' => true,
            // Remote ids are only unique on the remote page. Prefixing them keeps in-page
            // anchors working without colliding with the host page's own ids.
            'Attr.IDPrefix' => 'eye-',
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^https?://%',
            'CSS.AllowTricky' => false,
            'HTML.TargetBlank' => false,
            'Cache.DefinitionImpl' => null,
        ];

        $file = Plugin::getInstance()->getSettings()->purifierConfig;

        if ($file) {
            $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier' . DIRECTORY_SEPARATOR . $file . '.json';

            if (is_file($path)) {
                $decoded = json_decode((string)file_get_contents($path), true);

                if (is_array($decoded)) {
                    $options = $decoded + $options;
                }
            }
        }

        foreach ($options as $option => $value) {
            $config->set($option, $value);
        }

        $cachePath = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'eye' . DIRECTORY_SEPARATOR . 'purifier';

        try {
            FileHelper::createDirectory($cachePath);
            $config->set('Cache.SerializerPath', $cachePath);
        } catch (Throwable) {
            // A read-only runtime directory costs speed, not correctness.
        }

        return (new HTMLPurifier($config))->purify($html);
    }

    private function cacheKey(string $url, EmbedOptions $options, bool $inline): string
    {
        return self::CACHE_PREFIX . md5(implode('|', [
            self::CACHE_VERSION,
            $url,
            $inline ? 'fragment' : 'document',
            $options->extract,
            $options->remove,
            $options->injectCss,
            // The effective answer, not the option: the plugin setting can change under it.
            $this->stripsScripts($options) ? 'strip' : 'keep',
            $options->linkTarget,
        ]));
    }
}
