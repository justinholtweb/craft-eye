<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\eye\elements\db\EmbedQuery;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\FramabilityResult;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\records\EmbedRecord;
use Throwable;

/**
 * The embed library, and the authority on handles.
 */
class Embeds extends Component
{
    /** @var array<string, Embed|null> */
    private array $byHandle = [];

    public function getEmbedById(int $id): ?Embed
    {
        /** @var Embed|null */
        return Craft::$app->getElements()->getElementById($id, Embed::class);
    }

    /**
     * Look an embed up by handle.
     *
     * Memoized per request because a page with a dozen ref tags asks the same question a dozen
     * times, and a ref tag is resolved once per rich-text *value*, not once per page.
     */
    public function getEmbedByHandle(string $handle): ?Embed
    {
        if (!array_key_exists($handle, $this->byHandle)) {
            /** @var Embed|null $embed */
            $embed = Embed::find()->handle($handle)->status(null)->one();
            $this->byHandle[$handle] = $embed;
        }

        return $this->byHandle[$handle];
    }

    public function getEmbedByUid(string $uid): ?Embed
    {
        /** @var Embed|null */
        return Embed::find()->uid($uid)->status(null)->one();
    }

    /** Handle or id — the two things a template author might be holding. */
    public function resolve(string|int|null $reference): ?Embed
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return is_numeric($reference)
            ? $this->getEmbedById((int)$reference)
            : $this->getEmbedByHandle((string)$reference);
    }

    public function find(array $criteria = []): EmbedQuery
    {
        /** @var EmbedQuery $query */
        $query = Embed::find();

        if ($criteria) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    // Handles
    // -------------------------------------------------------------------------

    /**
     * Asked of an element query, not of the table: a trashed embed's row keeps its handle until
     * garbage collection, and an author cannot be told a name is taken by something invisible.
     */
    public function handleIsTaken(string $handle, ?int $exceptId = null): bool
    {
        $query = Embed::find()->handle($handle)->status(null)->siteId('*')->unique();

        if ($exceptId) {
            $query->andWhere(['not', ['elements.id' => $exceptId]]);
        }

        return $query->exists();
    }

    /** A free handle based on `$seed`, suffixed if it has to be. */
    public function uniqueHandle(string $seed, ?int $exceptId = null): string
    {
        $base = StringHelper::toKebabCase(StringHelper::toAscii($seed));
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '', $base) ?: 'embed';
        $base = ltrim($base, '0123456789-_') ?: 'embed';
        $base = substr($base, 0, 40);

        if (!$this->handleIsTaken($base, $exceptId)) {
            return $base;
        }

        for ($i = 2; $i < 1000; $i++) {
            if (!$this->handleIsTaken("$base-$i", $exceptId)) {
                return "$base-$i";
            }
        }

        return $base . '-' . StringHelper::randomString(6);
    }

    // Saving
    // -------------------------------------------------------------------------

    public function saveEmbed(Embed $embed, bool $runValidation = true): bool
    {
        $saved = Craft::$app->getElements()->saveElement($embed, $runValidation);

        if ($saved) {
            unset($this->byHandle[(string)$embed->handle]);
        }

        return $saved;
    }

    public function deleteEmbed(Embed $embed): bool
    {
        unset($this->byHandle[(string)$embed->handle]);

        return Craft::$app->getElements()->deleteElement($embed);
    }

    /**
     * Build an embed from nothing but a URL — what the CKEditor paste handler and the "new from
     * URL" button both do.
     */
    public function createFromUrl(string $url, array $overrides = []): Embed
    {
        $embed = new Embed();
        $embed->url = trim($url);

        $match = Plugin::getInstance()->providers->match($embed->url);
        $options = Plugin::getInstance()->getSettings()->getDefaultEmbedOptions();

        if ($match) {
            $embed->provider = $match->provider->handle;
            $options = $options->merge($match->getOptionDefaults());
        }

        $embed->setOptions($options->merge($overrides));

        return $embed;
    }

    // Framability
    // -------------------------------------------------------------------------

    /**
     * Re-probe an embed and write the verdict onto its row.
     *
     * Written with a direct `update` rather than a save: this is derived data about the outside
     * world, and pushing it through the element save would bump `dateUpdated`, add a revision,
     * and make a background health check look like an edit.
     */
    public function checkFramability(Embed $embed, bool $useCache = false): FramabilityResult
    {
        $result = Plugin::getInstance()->framability->check((string)$embed->url, $useCache);

        $embed->framable = $result->status;
        $embed->checkMessage = $result->message;
        $embed->checkedAt = new \DateTime();

        if ($embed->id) {
            try {
                Craft::$app->getDb()->createCommand()
                    ->update(EmbedRecord::TABLE, [
                        'framable' => $result->status,
                        'checkMessage' => $result->message,
                        'checkedAt' => Db::prepareDateForDb($embed->checkedAt),
                    ], ['id' => $embed->id])
                    ->execute();
            } catch (Throwable $e) {
                Craft::warning('Could not record a framability check: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        return $result;
    }

    /**
     * Check every embed that has not been checked lately.
     *
     * @param int $maxAge Seconds. An embed checked more recently than this is left alone.
     * @return array<string, int> Counts by resulting status.
     */
    public function checkAll(int $maxAge = 86400, ?callable $progress = null): array
    {
        $counts = [];
        $cutoff = time() - $maxAge;

        /** @var Embed[] $embeds */
        $embeds = Embed::find()->status(null)->all();

        foreach ($embeds as $embed) {
            if ($maxAge > 0 && $embed->checkedAt && $embed->checkedAt->getTimestamp() > $cutoff) {
                continue;
            }

            // A proxied or inlined embed is never framed by the browser, so a framing header
            // says nothing about whether it works.
            if (in_array($embed->mode, EmbedOptions::FETCHING_MODES, true)) {
                continue;
            }

            $result = $this->checkFramability($embed);
            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;

            if ($progress) {
                $progress($embed, $result);
            }
        }

        return $counts;
    }
}
