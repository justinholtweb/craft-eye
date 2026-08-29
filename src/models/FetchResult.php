<?php

namespace justinholtweb\eye\models;

use craft\base\Model;

/** What {@see \justinholtweb\eye\services\Fetcher} brought back. */
class FetchResult extends Model
{
    /** The URL actually fetched — after redirects, not the one asked for. */
    public string $url = '';

    public int $status = 0;

    public string $contentType = '';

    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The document's declared charset, from the header or from a `<meta charset>`.
     *
     * Guessing wrong turns every apostrophe into a question mark, and a lot of the pages people
     * want to proxy are old enough to be windows-1252 with no header at all.
     */
    public function getCharset(): ?string
    {
        if (preg_match('/charset=["\']?([\w-]+)/i', $this->getHeader('Content-Type') ?? '', $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($this->body, 0, 4096), $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /** The body as UTF-8, whatever it arrived as. */
    public function getUtf8Body(): string
    {
        $charset = $this->getCharset();

        if ($charset === null || in_array($charset, ['UTF-8', 'UTF8'], true)) {
            return $this->body;
        }

        $converted = @mb_convert_encoding($this->body, 'UTF-8', $charset);

        return is_string($converted) ? $converted : $this->body;
    }
}
