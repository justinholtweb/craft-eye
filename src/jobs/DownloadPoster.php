<?php

namespace justinholtweb\eye\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\eye\Plugin;
use Throwable;

/**
 * Downloads one poster into the poster volume.
 *
 * A queue job rather than a step in the save, because the save should not wait on YouTube's image
 * CDN — and because a render that finds no copy queues one too, which must never slow the page.
 *
 * A failure is written onto the poster's row (the CP shows it) and logged, not rethrown: the
 * queue's own retry would hammer a host that is answering 404, and the row already says when a
 * render may try again.
 */
class DownloadPoster extends BaseJob
{
    public string $url = '';

    public function execute($queue): void
    {
        try {
            Plugin::getInstance()->posters->download($this->url);
        } catch (Throwable $e) {
            Craft::warning("Eye could not download the poster at $this->url: " . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('eye', 'Downloading an embed poster');
    }
}
