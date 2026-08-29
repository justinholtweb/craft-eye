<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use justinholtweb\eye\models\FramabilityResult;
use justinholtweb\eye\Plugin;
use Throwable;

/**
 * Ask a URL whether it will let this site frame it — before the author publishes the page and
 * finds out from a reader.
 *
 * Two headers decide it, and they disagree about precedence: `X-Frame-Options` is the old one,
 * `Content-Security-Policy: frame-ancestors` is the current one, and where both are present
 * every modern browser ignores the former. Eye reports whichever one actually applies.
 */
class Framability extends Component
{
    private const CACHE_PREFIX = 'eye:framable:';

    /**
     * @param bool $useCache Pass false for a "check again" button, which has to mean it.
     */
    public function check(string $url, bool $useCache = true): FramabilityResult
    {
        $settings = Plugin::getInstance()->getSettings();

        // Probe what actually goes in the frame, not what the author pasted.
        //
        // These are frequently different, and the difference is not a detail: YouTube's *watch*
        // page sends `X-Frame-Options: SAMEORIGIN` while its `/embed/` URL is designed to be
        // framed. Checking the pasted URL would put a red "refuses framing" badge on the single
        // most common embed there is. Resolving here rather than at each call site means no
        // caller has to remember — and it is idempotent, because feeding an embed URL back
        // through the provider registry returns the same URL.
        $url = Plugin::getInstance()->providers->match($url)?->embedUrl ?: $url;

        $key = self::CACHE_PREFIX . md5($url . '|' . $this->siteOrigin());
        $cache = Craft::$app->getCache();

        if ($useCache) {
            $cached = $cache->get($key);

            if (is_array($cached)) {
                return new FramabilityResult($cached);
            }
        }

        $result = $this->probe($url);

        if ($settings->framabilityCacheDuration > 0) {
            // An error is cached for a fraction of the time: a URL that was down for a minute
            // should not wear a red dot for a day.
            $duration = $result->status === FramabilityResult::STATUS_ERROR
                ? min(300, $settings->framabilityCacheDuration)
                : $settings->framabilityCacheDuration;

            $cache->set($key, $result->toArray(), $duration);
        }

        return $result;
    }

    public function forget(string $url): void
    {
        $url = Plugin::getInstance()->providers->match($url)?->embedUrl ?: $url;

        Craft::$app->getCache()->delete(self::CACHE_PREFIX . md5($url . '|' . $this->siteOrigin()));
    }

    // -------------------------------------------------------------------------

    private function probe(string $url): FramabilityResult
    {
        $result = new FramabilityResult([
            'url' => $url,
            'checkedAt' => time(),
        ]);

        try {
            $response = Plugin::getInstance()->fetcher->probe($url);
        } catch (Throwable $e) {
            $result->status = FramabilityResult::STATUS_ERROR;
            $result->message = $e->getMessage();

            return $result;
        }

        $result->httpStatus = $response->status;

        if ($response->status >= 400) {
            $result->status = FramabilityResult::STATUS_ERROR;
            $result->message = Craft::t('eye', 'The URL answered {status}.', ['status' => $response->status]);

            return $result;
        }

        $csp = $response->getHeader('Content-Security-Policy');
        $ancestors = $csp !== null ? $this->frameAncestors($csp) : null;

        // Where both headers are present, `frame-ancestors` is the one browsers obey — so it is
        // the one an author needs to hear about, even if X-Frame-Options says something else.
        if ($ancestors !== null) {
            $result->source = 'frame-ancestors';
            $result->header = 'frame-ancestors ' . implode(' ', $ancestors);

            return $this->judgeAncestors($result, $ancestors);
        }

        $xfo = $response->getHeader('X-Frame-Options');

        if ($xfo !== null && trim($xfo) !== '') {
            $result->source = 'x-frame-options';
            $result->header = trim($xfo);

            return $this->judgeXfo($result, trim($xfo));
        }

        $result->status = FramabilityResult::STATUS_ALLOWED;
        $result->message = Craft::t('eye', 'No framing restrictions — this page can be embedded.');

        return $result;
    }

    /**
     * @param string[] $sources
     */
    private function judgeAncestors(FramabilityResult $result, array $sources): FramabilityResult
    {
        if ($sources === [] || in_array("'none'", $sources, true)) {
            $result->status = FramabilityResult::STATUS_DENIED;
            $result->message = Craft::t('eye', 'This page sets frame-ancestors ‘none’, so no site may embed it.');

            return $result;
        }

        if (in_array('*', $sources, true)) {
            $result->status = FramabilityResult::STATUS_ALLOWED;
            $result->message = Craft::t('eye', 'This page allows any site to embed it.');

            return $result;
        }

        $origin = $this->siteOrigin();

        foreach ($sources as $source) {
            if ($this->originMatches($origin, $source, $result->url)) {
                $result->status = FramabilityResult::STATUS_ALLOWED;
                $result->message = Craft::t('eye', 'This page allows {origin} to embed it.', ['origin' => $origin]);

                return $result;
            }
        }

        $result->status = FramabilityResult::STATUS_RESTRICTED;
        $result->message = Craft::t('eye', 'This page only allows {sources} to embed it — not {origin}.', [
            'sources' => implode(', ', $sources),
            'origin' => $origin,
        ]);

        return $result;
    }

    private function judgeXfo(FramabilityResult $result, string $value): FramabilityResult
    {
        $directive = strtoupper(trim(explode(' ', $value)[0]));

        if ($directive === 'DENY') {
            $result->status = FramabilityResult::STATUS_DENIED;
            $result->message = Craft::t('eye', 'This page sends X-Frame-Options: DENY, so no site may embed it.');

            return $result;
        }

        if ($directive === 'SAMEORIGIN') {
            $sameOrigin = $this->originOf($result->url) === $this->siteOrigin();
            $result->status = $sameOrigin ? FramabilityResult::STATUS_ALLOWED : FramabilityResult::STATUS_RESTRICTED;
            $result->message = $sameOrigin
                ? Craft::t('eye', 'This page allows same-origin embedding, and it is on this domain.')
                : Craft::t('eye', 'This page sends X-Frame-Options: SAMEORIGIN, so only its own domain may embed it.');

            return $result;
        }

        // ALLOW-FROM was removed from every browser years ago; a page still sending it is
        // effectively unprotected, which is not the same as intending to be embeddable.
        if ($directive === 'ALLOW-FROM') {
            $result->status = FramabilityResult::STATUS_ALLOWED;
            $result->message = Craft::t('eye', 'This page sends the obsolete X-Frame-Options: ALLOW-FROM, which browsers ignore. It will embed, but that may not be intended.');

            return $result;
        }

        $result->status = FramabilityResult::STATUS_UNKNOWN;
        $result->message = Craft::t('eye', 'Eye did not understand this page’s X-Frame-Options header: {value}', ['value' => $value]);

        return $result;
    }

    /**
     * Pull `frame-ancestors` out of a CSP header, which may carry a dozen other directives and
     * may itself be several comma-joined policies.
     *
     * @return string[]|null Null when the header does not mention frame-ancestors at all — which
     * is different from mentioning it with no sources.
     */
    private function frameAncestors(string $csp): ?array
    {
        foreach (explode(',', $csp) as $policy) {
            foreach (explode(';', $policy) as $directive) {
                $tokens = preg_split('/\s+/', trim($directive), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                if ($tokens && strtolower($tokens[0]) === 'frame-ancestors') {
                    return array_slice($tokens, 1);
                }
            }
        }

        return null;
    }

    private function originMatches(string $origin, string $source, string $targetUrl): bool
    {
        $source = trim($source);

        if ($source === "'self'") {
            return $this->originOf($targetUrl) === $origin;
        }

        if (str_starts_with($source, "'")) {
            return false;
        }

        $originParts = parse_url($origin);
        $originHost = strtolower($originParts['host'] ?? '');
        $originScheme = strtolower($originParts['scheme'] ?? 'https');

        // A bare scheme (`https:`) allows everything on it.
        if (preg_match('~^([a-z][a-z0-9+.-]*):$~i', $source, $m)) {
            return strtolower($m[1]) === $originScheme;
        }

        if (preg_match('~^([a-z][a-z0-9+.-]*)://(.+)$~i', $source, $m)) {
            if (strtolower($m[1]) !== $originScheme) {
                return false;
            }

            $source = $m[2];
        }

        $source = strtolower(rtrim(explode('/', $source)[0], '.'));
        $source = preg_replace('/:\d+$/', '', $source) ?? $source;

        if (str_starts_with($source, '*.')) {
            $bare = substr($source, 2);

            return $originHost === $bare || str_ends_with($originHost, ".$bare");
        }

        return $originHost === $source;
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return strtolower(($parts['scheme'] ?? 'https') . '://' . $parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The origin a browser would send as the framing ancestor.
     *
     * The primary site's, not the current request's: a check run from the CP has to answer for
     * the front end, and a check run from a console command has no request at all.
     */
    private function siteOrigin(): string
    {
        $url = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        if (!$url) {
            $url = UrlHelper::baseSiteUrl();
        }

        return $this->originOf((string)$url);
    }
}
