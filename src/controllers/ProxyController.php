<?php

namespace justinholtweb\eye\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\eye\helpers\RateLimit;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\services\ProxyRoutes;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * The public face of proxy mode.
 *
 * This controller is the reason proxy mode is safe to offer at all, so what it will *not* do
 * matters more than what it does: it never takes a URL from the request. A stored embed arrives
 * as its uid and the URL is read from the database; a one-off arrives as a payload signed with
 * the site's security key. There is no third way in, which is what stops this being an open
 * proxy running on the site's own IP address.
 */
class ProxyController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    /** Requests per minute one address may make to the proxy. A page rarely has more than a few. */
    public const REQUESTS_PER_MINUTE = 120;

    /**
     * The shortest a proxied page is cached for outside devMode. With no cache at all, every
     * page view is a fetch from the remote site, so anyone with a frame URL could use this server
     * to hammer it.
     */
    public const MIN_CACHE_SECONDS = 60;

    /**
     * The sandbox a proxied page is served in, as a CSP directive.
     *
     * With its scripts stripped, the page keeps this site's origin — that is what lets the parent
     * measure it — but may run nothing: no scripts, no `javascript:` frames, no `srcdoc`. With its
     * scripts kept, it may run them but loses this origin, so they cannot read the site's cookies
     * or call its actions as whoever is viewing.
     */
    private const SANDBOX_STRIPPED = 'sandbox allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation';
    private const SANDBOX_SCRIPTED = 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation';

    /**
     * Serve a proxied page for a frame to point at.
     *
     * @throws NotFoundHttpException|ForbiddenHttpException|BadRequestHttpException
     */
    public function actionRender(?string $uid = null): Response
    {
        // First, before anything touches the database: this route is anonymous.
        if (!RateLimit::allow('proxy', self::REQUESTS_PER_MINUTE)) {
            Craft::$app->getResponse()->getHeaders()->set('Retry-After', '60');

            throw new TooManyRequestsHttpException(Craft::t('eye', 'Too many requests. Try again in a minute.'));
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->proxyEnabled) {
            throw new ForbiddenHttpException(Craft::t('eye', 'Eye’s proxy is disabled.'));
        }

        [$url, $options] = $this->resolveTarget($uid);

        $duration = $options->cacheDuration ?? $settings->proxyCacheDuration;

        if ($duration < self::MIN_CACHE_SECONDS && !Craft::$app->getConfig()->getGeneral()->devMode) {
            $options->cacheDuration = self::MIN_CACHE_SECONDS;
        }

        try {
            $result = $plugin->proxy->document($url, $options);
        } catch (Throwable $e) {
            Craft::warning("Eye could not proxy $url: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->errorDocument($e->getMessage());
        }

        return $this->htmlResponse($result->html, $options);
    }

    /**
     * The child script, for a page you want framed *by* this site with auto height.
     *
     * Served from a stable URL rather than as a published asset, because the whole point is that
     * somebody else puts it in a `<script src>` on a different site, and a hashed
     * `/cpresources/…` path moves every time the plugin is updated.
     */
    public function actionChild(): Response
    {
        $path = Craft::getAlias('@justinholtweb/eye/web/assets/runtime/dist/eye-child.js');

        if (!$path || !is_file($path)) {
            throw new NotFoundHttpException();
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = file_get_contents($path);
        $response->getHeaders()
            ->set('Content-Type', 'application/javascript; charset=utf-8')
            ->set('Cache-Control', 'public, max-age=86400')
            // Read by pages on other origins by design — that is what it is for.
            ->set('Access-Control-Allow-Origin', '*')
            ->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: EmbedOptions}
     * @throws NotFoundHttpException|BadRequestHttpException
     */
    private function resolveTarget(?string $uid): array
    {
        $plugin = Plugin::getInstance();

        if ($uid !== null) {
            $embed = $plugin->embeds->getEmbedByUid($uid);

            if (!$embed || !$embed->enabled) {
                throw new NotFoundHttpException(Craft::t('eye', 'Embed not found.'));
            }

            $options = $embed->getOptions();

            // Only an embed that was *saved* as a proxy may be fetched through this route.
            // Otherwise a uid harvested from the page would turn any embed into a fetch.
            if (!in_array($options->mode, EmbedOptions::FETCHING_MODES, true)) {
                throw new NotFoundHttpException(Craft::t('eye', 'That embed is not proxied.'));
            }

            return [(string)$embed->url, $options];
        }

        $signed = (string)Craft::$app->getRequest()->getRequiredQueryParam(ProxyRoutes::PARAM);
        $payload = $plugin->proxyRoutes->verify($signed);

        if ($payload === null) {
            throw new BadRequestHttpException(Craft::t('eye', 'That proxy link is not valid.'));
        }

        return [$payload['url'], $payload['options']];
    }

    private function htmlResponse(string $html, EmbedOptions $options): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $html;

        $duration = $options->cacheDuration ?? Plugin::getInstance()->getSettings()->proxyCacheDuration;

        $response->getHeaders()
            ->set('Content-Type', 'text/html; charset=utf-8')
            ->set('X-Content-Type-Options', 'nosniff')
            ->set('Referrer-Policy', 'no-referrer')
            // Only this site may frame the proxy. Without it, anyone who found the URL could
            // frame it on their own site and use this server as their content delivery.
            ->set('X-Frame-Options', 'SAMEORIGIN')
            ->set('Content-Security-Policy', implode('; ', array_filter([
                Plugin::getInstance()->proxy->stripsScripts($options) ? self::SANDBOX_STRIPPED : self::SANDBOX_SCRIPTED,
                Plugin::getInstance()->proxy->stripsScripts($options) ? "script-src 'none'" : null,
                "object-src 'none'",
                "frame-ancestors 'self'",
            ])))
            ->set('Cache-Control', $duration > 0 ? "private, max-age=$duration" : 'no-store');

        return $response;
    }

    /** A readable page inside the frame beats a blank rectangle and a line in the logs. */
    private function errorDocument(string $message): Response
    {
        $devMode = Craft::$app->getConfig()->getGeneral()->devMode;
        $body = Craft::t('eye', 'This content could not be loaded.');

        $html = sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;'
            . 'font:15px/1.5 system-ui,sans-serif;color:#3f3f46;background:#f4f4f5;text-align:center;padding:1.5rem}</style>'
            . '</head><body><div><p>%s</p>%s</div></body></html>',
            htmlspecialchars($body, ENT_QUOTES, 'UTF-8'),
            $devMode ? '<p style="color:#a1a1aa;font-size:13px">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>' : ''
        );

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $html;
        $response->setStatusCode(200);
        $response->getHeaders()
            ->set('Content-Type', 'text/html; charset=utf-8')
            ->set('Cache-Control', 'no-store')
            ->set('X-Frame-Options', 'SAMEORIGIN')
            ->set('Content-Security-Policy', self::SANDBOX_STRIPPED . "; script-src 'none'; frame-ancestors 'self'");

        return $response;
    }
}
