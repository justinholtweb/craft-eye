<?php

namespace justinholtweb\eye\models;

use Craft;
use craft\base\Model;

/**
 * Whether a URL will actually appear when you frame it.
 *
 * The reason this exists: a page that refuses to be framed does not tell the framing page so.
 * The browser blocks it, logs a line in a console nobody is looking at, and leaves a blank
 * rectangle. Every author's first assumption is that the CMS is broken.
 */
class FramabilityResult extends Model
{
    /** Not checked, or the check could not reach a verdict. */
    public const STATUS_UNKNOWN = 'unknown';

    /** No framing restrictions found. */
    public const STATUS_ALLOWED = 'allowed';

    /** Framing is refused outright. */
    public const STATUS_DENIED = 'denied';

    /** Framing is allowed, but not from this site. */
    public const STATUS_RESTRICTED = 'restricted';

    /** The URL could not be reached at all — DNS, TLS, 404, timeout. */
    public const STATUS_ERROR = 'error';

    public string $url = '';

    public string $status = self::STATUS_UNKNOWN;

    /** A sentence an author can act on. Never a header dump. */
    public string $message = '';

    /** The header that decided it, verbatim, for whoever wants to see it. */
    public string $header = '';

    /** `x-frame-options`, `frame-ancestors`, or blank. */
    public string $source = '';

    public ?int $checkedAt = null;

    public int $httpStatus = 0;

    public function getIsFramable(): bool
    {
        return $this->status === self::STATUS_ALLOWED;
    }

    /** Whether the verdict is bad enough that the CP should say something. */
    public function getIsProblem(): bool
    {
        return in_array($this->status, [self::STATUS_DENIED, self::STATUS_RESTRICTED, self::STATUS_ERROR], true);
    }

    /** What the CP should suggest doing about it. */
    public function getSuggestion(): string
    {
        return match ($this->status) {
            self::STATUS_DENIED, self::STATUS_RESTRICTED => Craft::t('eye', 'Use proxy mode to serve this page from your own domain, or link to it instead of framing it.'),
            self::STATUS_ERROR => Craft::t('eye', 'Check the URL — Eye could not load it at all.'),
            default => '',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ALLOWED => Craft::t('eye', 'Embeddable'),
            self::STATUS_DENIED => Craft::t('eye', 'Refuses framing'),
            self::STATUS_RESTRICTED => Craft::t('eye', 'Not from this site'),
            self::STATUS_ERROR => Craft::t('eye', 'Unreachable'),
            default => Craft::t('eye', 'Not checked'),
        };
    }

    /** Maps onto Craft's status-dot colours. */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_ALLOWED => 'green',
            self::STATUS_DENIED, self::STATUS_ERROR => 'red',
            self::STATUS_RESTRICTED => 'orange',
            default => 'gray',
        };
    }
}
