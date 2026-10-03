<?php

namespace justinholtweb\eye\helpers;

use Craft;

/**
 * A per-address, per-minute request budget for Eye's anonymous routes.
 */
abstract class RateLimit
{
    /** The whole site's budget for a bucket, as a multiple of one address's. */
    private const GLOBAL_FACTOR = 20;

    /**
     * Whether this address may make another request to `$bucket` this minute.
     *
     * Two budgets, both of which must have room: one per address, and one for everybody. The
     * global one is what stops an attacker with many addresses — a cloud range, an IPv6 block —
     * from simply spreading the load.
     */
    public static function allow(string $bucket, int $perMinute): bool
    {
        $minute = intdiv(time(), 60);

        return self::take(sprintf('eye:rate:%s:%s:%d', $bucket, sha1(self::address()), $minute), $perMinute)
            && self::take(sprintf('eye:rate:%s:*:%d', $bucket, $minute), $perMinute * self::GLOBAL_FACTOR);
    }

    /**
     * Whether `$identity` — a signed-in user, say — may make another request to `$bucket` this
     * minute. For actions that already know who is asking, where an address is the wrong unit.
     */
    public static function allowFor(string $bucket, string $identity, int $perMinute): bool
    {
        return self::take(sprintf('eye:rate:%s:%s:%d', $bucket, sha1($identity), intdiv(time(), 60)), $perMinute);
    }

    /**
     * The address a request is charged to.
     *
     * Craft trusts every host by default (`trustedHosts` is `['any']`), which makes
     * `X-Forwarded-For` something any client can set — a fresh value per request would mean the
     * limit never applies, and a victim's address would let someone spend their budget. So the
     * forwarded address is believed only once a site has said which proxies to trust; until then
     * it is the connecting address. An IPv6 address is charged by its /64, since one machine is
     * routinely handed a whole one.
     */
    public static function address(): string
    {
        $request = Craft::$app->getRequest();
        $trusted = Craft::$app->getConfig()->getGeneral()->trustedHosts;
        $ip = (string)($trusted === ['any'] ? $request->getRemoteIP() : $request->getUserIP());

        if (str_contains($ip, ':') && ($packed = @inet_pton($ip)) !== false) {
            return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        return $ip;
    }

    /**
     * The count is read and written under a mutex: without it, requests sent in parallel all read
     * the same number and none is ever refused. A busy lock refuses rather than queues — a worker
     * held up waiting cannot serve anyone.
     */
    private static function take(string $key, int $limit): bool
    {
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 2)) {
            return false;
        }

        try {
            $count = (int)$cache->get($key);

            if ($count >= $limit) {
                return false;
            }

            $cache->set($key, $count + 1, 120);

            return true;
        } finally {
            $mutex->release($key);
        }
    }
}
