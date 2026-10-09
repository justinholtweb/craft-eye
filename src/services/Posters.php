<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\models\Volume;
use DateTime;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\jobs\DownloadPoster;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\records\PosterRecord;
use Throwable;
use yii\base\Exception;
use yii\db\IntegrityException;

/**
 * Self-hosted posters for the click-to-load card.
 *
 * A consent card exists so that nothing reaches the third party until the reader agrees — and a
 * card that hotlinks the provider's thumbnail has already sent the reader's IP address, user agent
 * and the page they are on to that provider, before they agreed to anything. So Eye downloads the
 * poster once, server-side, into an asset volume, and the card shows that copy. Until the copy
 * exists the card shows no poster at all: a plain card is a smaller cost than a broken promise.
 *
 * The download goes through {@see Fetcher::fetchImage()}, and only from hosts the provider
 * registry declares as poster hosts (YouTube's image CDN), plus the proxy's own allowlist when the
 * proxy is on. Posters are keyed by URL, not by embed, so an Embed field value and a
 * `craft.eye.url()` call get the same treatment as a library embed.
 */
class Posters extends Component
{
    /** Show a downloaded copy, or nothing. */
    public const MODE_LOCAL = 'local';

    /** Hotlink the provider's poster, as Eye 5.0 did. */
    public const MODE_REMOTE = 'remote';

    /** Never show a poster. */
    public const MODE_NONE = 'none';

    public const MODES = [self::MODE_LOCAL, self::MODE_REMOTE, self::MODE_NONE];

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    /** A poster is a thumbnail. Anything bigger than this is not one. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** How long a failed download waits before a render may try again, in seconds. */
    public const RETRY_AFTER = 86400;

    /** A pending row older than this lost its job (a cleared queue, a crash) and is re-queued. */
    public const PENDING_STALE = 3600;

    /** What `getimagesize()` reports → the extension the copy is saved with. Raster only. */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    /** @var array<string, array<string, mixed>|false> Rows by URL hash, for this request. */
    private array $rows = [];

    /** @var array<string, string> What the card shows, by mode and remote URL, for this request. */
    private array $displayUrls = [];

    /**
     * The URL the consent card should put behind itself, for a poster at `$url`.
     *
     * The same answer for every visitor — it depends on the database, never on the reader — so a
     * statically cached page is right for whoever gets it.
     */
    public function displayUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || !EmbedOptions::isHttpUrl($url)) {
            return '';
        }

        $mode = $this->mode();
        $key = "$mode|$url";

        if (isset($this->displayUrls[$key])) {
            return $this->displayUrls[$key];
        }

        return $this->displayUrls[$key] = match (true) {
            $mode === self::MODE_NONE => '',
            // An image on this site is not a third party.
            $this->isSameSite($url) => $url,
            $mode === self::MODE_REMOTE => $url,
            default => $this->localUrl($url),
        };
    }

    public function mode(): string
    {
        $mode = Plugin::getInstance()->getSettings()->posterMode;

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_LOCAL;
    }

    /**
     * The hosts Eye downloads posters from: every provider's declared poster hosts, plus the
     * proxy's allowlist when the proxy is on — an admin who allowed server-side fetches from a
     * host has already answered the question for a poster image there.
     *
     * @return string[]
     */
    public function hosts(): array
    {
        $plugin = Plugin::getInstance();
        $hosts = $plugin->providers->posterHosts();

        if ($plugin->getSettings()->getProxyIsUsable()) {
            foreach ($plugin->getSettings()->allowedHosts as $host) {
                if (is_string($host) && $host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /** Whether Eye would download a poster from `$url`. */
    public function isDownloadable(string $url): bool
    {
        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && Fetcher::hostMatches($host, $this->hosts());
    }

    /** Whether `$url` is on one of this install's own sites, and so no third party at all. */
    public function isSameSite(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteHost = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);

            if (is_string($siteHost) && strcasecmp($siteHost, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    /** The volume posters are saved to, by UID (what the settings screen stores) or handle. */
    public function getVolume(): ?Volume
    {
        $value = trim((string)Plugin::getInstance()->getSettings()->posterVolume);

        if ($value === '') {
            return null;
        }

        $volumes = Craft::$app->getVolumes();

        return $volumes->getVolumeByUid($value) ?? $volumes->getVolumeByHandle($value);
    }

    /** The poster a library embed shows: its own, or its provider's. */
    public function sourceFor(Embed $embed): string
    {
        $own = $embed->getOptions()->posterUrl;

        return $own !== '' ? $own : ($embed->getProviderMatch()->posterUrl ?? '');
    }

    /**
     * What the CP says about a poster, so an author can see whether readers' browsers will
     * contact anyone before they consent.
     *
     * @return array{state: string, message: string, url: string}
     */
    public function describe(string $url): array
    {
        $url = trim($url);
        $host = (string)parse_url($url, PHP_URL_HOST);
        $out = fn(string $state, string $message, string $shown = '') => ['state' => $state, 'message' => $message, 'url' => $shown];

        if ($url === '') {
            return $out('none', Craft::t('eye', 'No poster. The consent card is plain.'));
        }

        if ($this->mode() === self::MODE_NONE) {
            return $out('off', Craft::t('eye', 'Posters are switched off in Eye’s settings.'));
        }

        if ($this->isSameSite($url)) {
            return $out('local', Craft::t('eye', 'Served from this site.'), $url);
        }

        if ($this->mode() === self::MODE_REMOTE) {
            return $out('remote', Craft::t('eye', 'Hotlinked: readers’ browsers contact {host} before they consent. Switch posters to self-hosted in Eye’s settings to stop that.', ['host' => $host]), $url);
        }

        if (!$this->isDownloadable($url)) {
            return $out('refused', Craft::t('eye', 'Not shown: Eye only downloads posters from its providers’ image hosts (and the proxy’s allowed hosts), and {host} is neither.', ['host' => $host]));
        }

        if (!$this->getVolume()) {
            return $out('unconfigured', Craft::t('eye', 'Not shown yet: choose a poster volume in Eye’s settings and the poster will be downloaded there.'));
        }

        $row = $this->row($url);
        $shown = $this->localUrl($url, false);

        if ($shown !== '') {
            return $out('ready', Craft::t('eye', 'Self-hosted. Readers’ browsers contact nobody until they consent.'), $shown);
        }

        if ($row && $row['status'] === self::STATUS_FAILED) {
            return $out('failed', Craft::t('eye', 'Could not be downloaded: {error}', ['error' => (string)$row['error']]));
        }

        return $out('pending', Craft::t('eye', 'Downloading. The card shows no poster until the copy is ready.'));
    }

    // Downloading
    // -------------------------------------------------------------------------

    /**
     * Queue a download unless there is a copy, a download on the way, or a recent failure.
     *
     * Cheap and idempotent, so it is safe to call from a render: the row is claimed before the job
     * is pushed, and the unique index on the URL hash settles a race between two requests.
     */
    public function queue(string $url): bool
    {
        $url = trim($url);

        if (
            $url === ''
            || $this->mode() !== self::MODE_LOCAL
            || $this->isSameSite($url)
            || !$this->isDownloadable($url)
            || !$this->getVolume()
        ) {
            return false;
        }

        $hash = self::hash($url);
        $row = $this->row($url);
        $db = Craft::$app->getDb();
        $now = time();

        if ($row) {
            $age = $now - (int)strtotime((string)($row['attemptedAt'] ?? $row['dateUpdated']) . ' UTC');
            $due = match ($row['status']) {
                self::STATUS_FAILED => $age > self::RETRY_AFTER,
                self::STATUS_PENDING => $age > self::PENDING_STALE,
                // Ready with its asset gone: CASCADE removes the row, so this is only a row whose
                // asset could not be loaded — try again.
                default => $this->localUrl($url, false) === '',
            };

            if (!$due) {
                return false;
            }

            $db->createCommand()->update(PosterRecord::TABLE, [
                'status' => self::STATUS_PENDING,
                'attemptedAt' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $row['id']])->execute();
        } else {
            try {
                $db->createCommand()->insert(PosterRecord::TABLE, [
                    'urlHash' => $hash,
                    'url' => $url,
                    'status' => self::STATUS_PENDING,
                    'attemptedAt' => Db::prepareDateForDb(new DateTime()),
                ])->execute();
            } catch (IntegrityException) {
                // Another request claimed it first; its job will do.
                return false;
            }
        }

        unset($this->rows[$hash]);
        $this->displayUrls = [];
        Craft::$app->getQueue()->push(new DownloadPoster(['url' => $url]));

        return true;
    }

    /**
     * Download `$url` into the poster volume now, or return the copy already there.
     *
     * @throws Exception when the poster cannot be downloaded or saved. The reason is also written
     * onto the poster's row, where the CP shows it.
     */
    public function download(string $url, bool $force = false): Asset
    {
        $url = trim($url);
        $volume = $this->getVolume();

        try {
            if (!$volume) {
                throw new Exception(Craft::t('eye', 'No poster volume is set in Eye’s settings.'));
            }

            if (!$this->isDownloadable($url)) {
                throw new Exception(Craft::t('eye', '{host} is not a host Eye downloads posters from.', [
                    'host' => (string)parse_url($url, PHP_URL_HOST),
                ]));
            }

            $existing = $this->asset($url);

            if ($existing && !$force) {
                $this->record($url, self::STATUS_READY, $existing->id, null);

                return $existing;
            }

            $result = Plugin::getInstance()->fetcher->fetchImage($url, $this->hosts(), self::MAX_BYTES);

            // The Content-Type header is the server's claim; the bytes are the evidence. Both have
            // to say "raster image", and the extension comes from the bytes.
            $info = @getimagesizefromstring($result->body);
            $mime = is_array($info) ? $info['mime'] : '';

            if (!isset(self::TYPES[$mime]) || empty($info[0]) || empty($info[1])) {
                throw new Exception(Craft::t('eye', 'The poster at {host} is not an image Eye accepts.', [
                    'host' => (string)parse_url($url, PHP_URL_HOST),
                ]));
            }

            $filename = 'poster-' . substr(self::hash($url), 0, 16) . '.' . self::TYPES[$mime];
            $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'eye-' . bin2hex(random_bytes(8)) . '-' . $filename;
            FileHelper::writeToFile($tempPath, $result->body);

            try {
                $asset = $existing
                    ? $this->replace($existing, $tempPath, $filename)
                    : $this->create($volume, $tempPath, $filename);
            } finally {
                if (is_file($tempPath)) {
                    FileHelper::unlink($tempPath);
                }
            }

            $this->record($url, self::STATUS_READY, $asset->id, null);

            // Pages cached with the plain card should pick up the poster.
            Craft::$app->getElements()->invalidateCachesForElementType(Embed::class);

            return $asset;
        } catch (Throwable $e) {
            $this->record($url, self::STATUS_FAILED, null, $e->getMessage());

            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        }
    }

    /**
     * Download every poster Eye knows about: each library embed's, and any a render has queued.
     *
     * @return array<string, int> Counts: downloaded, existing, skipped, failed.
     */
    public function downloadAll(bool $force = false, ?callable $progress = null): array
    {
        $counts = ['downloaded' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        $urls = [];

        /** @var Embed[] $embeds */
        $embeds = Embed::find()->status(null)->all();

        foreach ($embeds as $embed) {
            $source = $this->sourceFor($embed);

            if ($source !== '') {
                $urls[$source] = $embed->title ?: $embed->handle;
            }
        }

        foreach (PosterRecord::find()->select(['url'])->column() as $known) {
            $urls[(string)$known] ??= '';
        }

        foreach ($urls as $url => $label) {
            $url = (string)$url;

            if ($this->isSameSite($url) || !$this->isDownloadable($url)) {
                $counts['skipped']++;
                $progress && $progress($url, (string)$label, 'skipped', Craft::t('eye', 'not a poster host'));

                continue;
            }

            $had = $this->asset($url) !== null;

            try {
                $asset = $this->download($url, $force);
                $state = $had && !$force ? 'existing' : 'downloaded';
                $counts[$state]++;
                $progress && $progress($url, (string)$label, $state, (string)$asset->getPath());
            } catch (Throwable $e) {
                $counts['failed']++;
                $progress && $progress($url, (string)$label, 'failed', $e->getMessage());
            }
        }

        return $counts;
    }

    /** The asset holding `$url`'s copy, if there is one. */
    public function asset(string $url): ?Asset
    {
        $row = $this->row($url);

        if (!$row || empty($row['assetId'])) {
            return null;
        }

        return Asset::find()->id((int)$row['assetId'])->status(null)->one();
    }

    public static function hash(string $url): string
    {
        return sha1(trim($url));
    }

    // -------------------------------------------------------------------------

    /** The self-hosted copy's URL, queuing a download when there is none (unless told not to). */
    private function localUrl(string $url, bool $queue = true): string
    {
        $row = $this->row($url);

        if ($row && $row['status'] === self::STATUS_READY && $row['assetId']) {
            $asset = $this->asset($url);
            $transform = Plugin::getInstance()->getSettings()->posterTransform ?: null;

            try {
                $shown = $asset ? $asset->getUrl($transform) : null;
            } catch (Throwable $e) {
                Craft::warning("Eye could not get a URL for the poster of $url: " . $e->getMessage(), Plugin::LOG_CATEGORY);
                $shown = null;
            }

            // A volume without public URLs has nothing to show — and is still not a reason to
            // hotlink.
            if (is_string($shown) && $shown !== '') {
                return $shown;
            }
        }

        if ($queue) {
            try {
                $this->queue($url);
            } catch (Throwable $e) {
                Craft::warning("Eye could not queue the poster for $url: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function row(string $url): ?array
    {
        $hash = self::hash($url);

        if (!array_key_exists($hash, $this->rows)) {
            $row = PosterRecord::find()->where(['urlHash' => $hash])->asArray()->one();
            $this->rows[$hash] = is_array($row) ? $row : false;
        }

        return $this->rows[$hash] ?: null;
    }

    private function record(string $url, string $status, ?int $assetId, ?string $error): void
    {
        $hash = self::hash($url);
        $now = Db::prepareDateForDb(new DateTime());
        $values = [
            'status' => $status,
            'assetId' => $assetId,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'attemptedAt' => $now,
            'dateUpdated' => $now,
        ];
        $db = Craft::$app->getDb();

        try {
            if ($this->row($url)) {
                $db->createCommand()->update(PosterRecord::TABLE, $values, ['urlHash' => $hash])->execute();
            } else {
                $db->createCommand()->insert(PosterRecord::TABLE, $values + ['urlHash' => $hash, 'url' => $url])->execute();
            }
        } catch (Throwable $e) {
            Craft::warning("Eye could not record the poster for $url: " . $e->getMessage(), Plugin::LOG_CATEGORY);
        }

        unset($this->rows[$hash]);
        $this->displayUrls = [];
    }

    /** @throws Exception */
    private function create(Volume $volume, string $tempPath, string $filename): Asset
    {
        $folderPath = trim(str_replace('\\', '/', Plugin::getInstance()->getSettings()->posterFolder), '/');

        // Re-checked here as well as in the settings validator: `config/eye.php` skips validation.
        if ($folderPath !== '' && !preg_match('~^[\w-]+(/[\w-]+)*$~', $folderPath)) {
            $folderPath = 'eye-posters';
        }

        $assets = Craft::$app->getAssets();
        $folder = $folderPath === ''
            ? $assets->getRootFolderByVolumeId($volume->id)
            : $assets->ensureFolderByFullPathAndVolume($folderPath, $volume, false);

        if (!$folder) {
            throw new Exception(Craft::t('eye', 'The poster folder could not be created.'));
        }

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new Exception(Craft::t('eye', 'The poster could not be saved: {errors}', [
                'errors' => implode(' ', $asset->getFirstErrors()),
            ]));
        }

        return $asset;
    }

    private function replace(Asset $asset, string $tempPath, string $filename): Asset
    {
        Craft::$app->getAssets()->replaceAssetFile($asset, $tempPath, $filename);

        return $asset;
    }
}
