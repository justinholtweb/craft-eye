<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\eye\helpers\Ip;
use justinholtweb\eye\models\FetchResult;
use justinholtweb\eye\Plugin;
use Psr\Http\Message\ResponseInterface;
use yii\base\Exception;

/**
 * The only thing in Eye that opens an outbound connection.
 *
 * Everything the proxy and the framability probe do goes through here, so there is exactly one
 * place to get the safety right — and one place to read to check that it is.
 *
 * Nine rules, all of which have to hold:
 *
 * 1. `http` and `https` only.
 * 2. The host must be on the site's allowlist. There is no "allow everything" value.
 * 3. Every address the host resolves to must be public — all of them, not one of them, because
 *    a host answering with one public and one private address is a rebinding attempt and the
 *    choice of which to connect to is not ours.
 * 4. The connection is **pinned** to a validated address with `CURLOPT_RESOLVE`, closing the
 *    window between the check and the connect.
 * 5. Redirects are followed by hand, capped, and re-validated at every hop against 2 and 3.
 * 6. The response is capped by size, by time, and by content type.
 * 7. Nothing of the reader's is forwarded — no cookies, no auth, no client IP, no referer.
 * 8. Only Eye's own user agent goes out.
 * 9. The caller cannot pass a URL straight from a request; it comes from a stored embed.
 */
class Fetcher extends Component
{
    /** Read the body in chunks so an oversized response is abandoned rather than buffered. */
    private const CHUNK = 65536;

    /**
     * @throws Exception when the URL is refused, or the fetch fails.
     */
    public function fetch(string $url, array $options = []): FetchResult
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->proxyEnabled) {
            throw new Exception(Craft::t('eye', 'Eye’s proxy is disabled.'));
        }

        if (!$settings->allowedHosts) {
            throw new Exception(Craft::t('eye', 'Eye’s proxy has no allowed hosts, so it cannot fetch anything.'));
        }

        return $this->request($url, $settings->proxyMaxRedirects, $options);
    }

    /**
     * A fetch for the framability probe, which needs the headers of pages that are *not* on the
     * allowlist — it exists to tell an author about a URL before they commit to it.
     *
     * Same address validation, same pinning, same caps; the allowlist is the one rule relaxed,
     * and in exchange the body is discarded and only the headers are kept.
     *
     * @throws Exception
     */
    public function probe(string $url): FetchResult
    {
        return $this->request($url, 3, [
            'headersOnly' => true,
            'skipAllowlist' => true,
            'timeout' => 6,
        ]);
    }

    /** Whether a host is one the site has allowed. */
    public function hostIsAllowed(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]."));

        foreach (Plugin::getInstance()->getSettings()->allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if (str_starts_with($allowed, '*.')) {
                $bare = substr($allowed, 2);

                // `*.example.com` covers `example.com` itself: an author who allows a site's
                // subdomains and then finds the apex refused has learned nothing useful.
                if ($host === $bare || str_ends_with($host, ".$bare")) {
                    return true;
                }

                continue;
            }

            if ($host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /** Whether Eye would fetch this URL as things stand. Used by the CP to explain itself. */
    public function urlIsAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $this->hostIsAllowed($host);
    }

    // -------------------------------------------------------------------------

    /**
     * @throws Exception
     */
    private function request(string $url, int $redirectsLeft, array $options): FetchResult
    {
        $settings = Plugin::getInstance()->getSettings();
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new Exception(Craft::t('eye', '“{url}” is not a URL Eye can fetch.', ['url' => $url]));
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception(Craft::t('eye', 'Eye only fetches http and https URLs.'));
        }

        $host = strtolower($parts['host']);

        if (empty($options['skipAllowlist']) && !$this->hostIsAllowed($host)) {
            throw new Exception(Craft::t('eye', '{host} is not in Eye’s allowed hosts.', ['host' => $host]));
        }

        // Credentials in the URL would be sent to whatever the host turns out to be, and are a
        // classic way to smuggle a different host past a naive parser (`http://a@b/`).
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new Exception(Craft::t('eye', 'Eye will not fetch a URL containing credentials.'));
        }

        $addresses = $this->addressesFor($host);

        if (!$addresses) {
            throw new Exception(Craft::t('eye', '{host} does not resolve to a public address.', ['host' => $host]));
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            throw new Exception(Craft::t('eye', 'Eye will not fetch from port {port}.', ['port' => $port]));
        }

        $timeout = (int)($options['timeout'] ?? $settings->proxyTimeout);
        $maxBytes = (int)($options['maxBytes'] ?? $settings->proxyMaxBytes);
        $headersOnly = !empty($options['headersOnly']);

        // Not Craft::createGuzzleClient(): that applies the site's `httpProxy` and
        // `config/guzzle.php`, and a proxy resolves the host itself, which would make the address
        // check above meaningless. And the curl handler explicitly, because Guzzle's default
        // stack hands a request to PHP's stream wrapper whenever it can — and the stream wrapper
        // ignores every `curl` option below, the pin included, and does its own DNS lookup.
        $client = new Client(['handler' => HandlerStack::create(new CurlHandler())]);

        // The curl handler buffers the body rather than streaming it, so the size cap is
        // enforced as the bytes arrive: the progress callback aborts the transfer the moment it
        // passes the limit. A header probe aborts as soon as it has the headers.
        $headers = null;
        $tooLarge = false;

        try {
            $response = $client->request('GET', $url, [
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => min($timeout, 10),
                'timeout' => $timeout,
                'headers' => [
                    'User-Agent' => $settings->proxyUserAgent,
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1',
                    'Accept-Language' => 'en',
                ],
                'on_headers' => function(ResponseInterface $response) use (&$headers, &$tooLarge, $headersOnly, $maxBytes) {
                    $headers = $response;

                    // A declared length already over the cap saves reading anything at all.
                    if (!$headersOnly && (int)$response->getHeaderLine('Content-Length') > $maxBytes) {
                        $tooLarge = true;

                        throw new Exception('Too large.');
                    }
                },
                'curl' => [
                    // The pin. Without it, the address checked above and the address connected
                    // to are two separate DNS lookups, and everything between them is a race an
                    // attacker controls.
                    // One entry carrying every address: curl keys the cache on host and port, so
                    // separate entries replace each other and only the last survives. IPv4 first,
                    // since plenty of servers have no IPv6 route, and IPv6 in brackets.
                    CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, implode(',', $this->resolveList($addresses)))],
                    CURLOPT_PROXY => '',
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_COOKIEFILE => '',
                    CURLOPT_COOKIEJAR => '',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_XFERINFOFUNCTION => function($handle, int $downloadTotal, int $downloaded) use (&$headers, &$tooLarge, $headersOnly, $maxBytes): int {
                        if ($headersOnly && $headers !== null) {
                            return 1;
                        }

                        if ($downloaded > $maxBytes) {
                            $tooLarge = true;

                            return 1;
                        }

                        return 0;
                    },
                ],
            ]);
        } catch (GuzzleException $e) {
            if ($tooLarge) {
                throw new Exception(Craft::t('eye', 'The page at {host} is larger than Eye’s {limit} limit.', [
                    'host' => $host,
                    'limit' => Craft::$app->getFormatter()->asShortSize($maxBytes),
                ]), 0, $e);
            }

            // A probe that aborted on purpose once it had the headers.
            if ($headersOnly && $headers !== null) {
                $response = $headers;
            } else {
                throw new Exception(Craft::t('eye', 'Could not reach {host}: {message}', [
                    'host' => $host,
                    'message' => $e->getMessage(),
                ]), 0, $e);
            }
        }

        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
            if ($redirectsLeft < 1) {
                throw new Exception(Craft::t('eye', 'Too many redirects from {host}.', ['host' => $host]));
            }

            $next = $this->absoluteUrl($response->getHeaderLine('Location'), $url);

            if ($next === null) {
                throw new Exception(Craft::t('eye', '{host} redirected somewhere Eye cannot follow.', ['host' => $host]));
            }

            // Every hop starts the checks again from the top. A redirect is a *new* URL, and the
            // fact that the first one was allowed says nothing about this one.
            return $this->request($next, $redirectsLeft - 1, $options);
        }

        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        $result = new FetchResult([
            'url' => $url,
            'status' => $status,
            'contentType' => $contentType,
            'headers' => array_map(fn(array $v) => implode(', ', $v), $response->getHeaders()),
        ]);

        if ($headersOnly) {
            return $result;
        }

        if ($status !== 200) {
            throw new Exception(Craft::t('eye', '{host} answered {status}.', ['host' => $host, 'status' => $status]));
        }

        if ($contentType !== '' && !preg_match('~^(text/html|application/xhtml\+xml|text/plain)$~', $contentType)) {
            throw new Exception(Craft::t('eye', 'Eye only proxies HTML, and {host} returned {type}.', [
                'host' => $host,
                'type' => $contentType,
            ]));
        }

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(self::CHUNK);

            if (strlen($buffer) > $maxBytes) {
                $body->close();

                throw new Exception(Craft::t('eye', 'The page at {host} is larger than Eye’s {limit} limit.', [
                    'host' => $host,
                    'limit' => Craft::$app->getFormatter()->asShortSize($maxBytes),
                ]));
            }
        }

        $body->close();
        $result->body = $buffer;

        return $result;
    }

    /**
     * Every address `$host` resolves to, if all of them are public; otherwise none.
     *
     * Its own method so a check can substitute an address and prove the connection is pinned to
     * it rather than looked up again.
     *
     * @return string[]
     */
    protected function addressesFor(string $host): array
    {
        return Ip::resolvePublic($host);
    }

    /**
     * Addresses in the form `CURLOPT_RESOLVE` takes them.
     *
     * @param string[] $addresses
     * @return string[]
     */
    private function resolveList(array $addresses): array
    {
        usort($addresses, fn(string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return array_map(fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $addresses);
    }

    /**
     * Resolve a `Location` against the URL it came from.
     *
     * Returns null rather than guessing when the target is not http(s) — a redirect to
     * `file://`, `gopher://` or a bare `javascript:` is not a hop to follow.
     */
    private function absoluteUrl(string $location, string $base): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
            return null;
        }

        $parts = parse_url($base);

        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, strrpos($path, '/') + 1) ?: '/';

        return $origin . $directory . $location;
    }
}
