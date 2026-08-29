<?php

namespace justinholtweb\eye\models;

use craft\base\Model;

/** What {@see \justinholtweb\eye\services\Proxy} made of a fetched page. */
class ProxyResult extends Model
{
    /** A whole document (proxy mode) or a fragment (inline mode). */
    public string $html = '';

    /** The page's `<title>`, which makes a much better fallback card than the URL. */
    public string $title = '';

    /** The URL the content actually came from, after redirects. */
    public string $url = '';

    /** How many nodes the extract selector matched. Zero is worth telling an author about. */
    public int $extracted = 0;

    /** Whether this came out of the cache. */
    public bool $cached = false;
}
