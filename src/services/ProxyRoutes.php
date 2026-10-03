<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\Plugin;

/**
 * The URLs proxy mode points frames at, and the only thing that decides what those URLs may
 * fetch.
 *
 * The rule this class exists to enforce: **the proxy route never takes a URL from the request.**
 * A stored embed travels as its uid, and the controller reads the URL and options out of the
 * database. A one-off — an inline field value, a `craft.eye.url()` call in a template — travels
 * as a payload signed with the site's security key, so a visitor cannot edit it into a request
 * for something else. Either way, what gets fetched was decided by someone with CP or template
 * access, never by whoever loaded the page.
 *
 * Without this, `/eye/proxy?url=…` would be an open proxy wearing the site's IP address.
 */
class ProxyRoutes extends Component
{
    /**
     * The query parameter the signed payload travels in.
     *
     * **Not `p`.** Craft's `pathParam` general config setting defaults to `p`, so `?p=…` is how
     * Craft is told which path was requested (`index.php?p=some/path`) — a signed payload sent as
     * `p` is read as a request path, and the route 404s before any controller runs. Namespaced
     * here for the same reason `token` cannot be reused.
     */
    public const PARAM = 'eyeref';

    /** Cache-busts a stored embed's proxy URL; the controller ignores it. */
    public const VERSION_PARAM = 'eyev';

    /** Bump if the payload shape changes, so old signed URLs stop being honoured. */
    private const PAYLOAD_VERSION = 2;

    /** The only options a signed payload may carry. Anything else comes from the plugin. */
    private const CARRIED = ['extract', 'remove', 'injectCss', 'stripScripts', 'linkTarget', 'cacheDuration'];

    public function urlFor(string $embedUrl, EmbedOptions $options, ?string $uid = null): string
    {
        if ($uid) {
            // Versioned by what shapes the response, because the response is cached by the browser:
            // without this, changing an embed's script handling would leave readers with the old
            // document (and the old sandbox) while the page around it expects the new one — and
            // changing its URL would keep serving the old page.
            $carried = [];

            foreach (self::CARRIED as $key) {
                $carried[$key] = $options->$key;
            }

            $carried['strip'] = Plugin::getInstance()->proxy->stripsScripts($options);
            $carried['url'] = $embedUrl;

            return UrlHelper::siteUrl("eye/proxy/$uid", [self::VERSION_PARAM => substr(md5(Json::encode($carried)), 0, 10)]);
        }

        return UrlHelper::siteUrl('eye/proxy', [self::PARAM => $this->sign($embedUrl, $options)]);
    }

    /** @return string A signed, self-describing payload. */
    public function sign(string $embedUrl, EmbedOptions $options): string
    {
        $carried = [];

        foreach (self::CARRIED as $key) {
            $carried[$key] = $options->$key;
        }

        $payload = base64_encode(Json::encode([
            'v' => self::PAYLOAD_VERSION,
            'url' => $embedUrl,
            'o' => $carried,
        ]));

        return Craft::$app->getSecurity()->hashData($payload, $this->signingKey());
    }

    /**
     * @return array{url: string, options: EmbedOptions}|null Null when the payload is missing,
     * tampered with, or from an older format — all of which are the same answer: no.
     */
    public function verify(string $signed): ?array
    {
        $payload = Craft::$app->getSecurity()->validateData($signed, $this->signingKey());

        if ($payload === false) {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        $data = Json::decodeIfJson($decoded);

        if (!is_array($data) || ($data['v'] ?? null) !== self::PAYLOAD_VERSION || empty($data['url'])) {
            return null;
        }

        $options = new EmbedOptions();
        $options->mode = EmbedOptions::MODE_PROXY;
        $options->apply(array_intersect_key(
            is_array($data['o'] ?? null) ? $data['o'] : [],
            array_flip(self::CARRIED),
        ));

        return ['url' => (string)$data['url'], 'options' => $options];
    }

    /**
     * The site's security key, narrowed to this one purpose. Craft's `|hash` filter and every
     * other `hashData()` caller sign with the bare key, so without the suffix anything one of
     * them signed would also be a valid proxy payload.
     */
    private function signingKey(): string
    {
        return Craft::$app->getConfig()->getGeneral()->securityKey . '|eye-proxy';
    }
}
