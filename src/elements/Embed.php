<?php

namespace justinholtweb\eye\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\eye\elements\db\EmbedQuery;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\FramabilityResult;
use justinholtweb\eye\models\ProviderMatch;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\records\EmbedRecord;
use Throwable;
use Twig\Markup;

/**
 * One embed in the library: a URL, plus every decision about how it should behave.
 *
 * Being a real element buys the index, search, permissions, relations and restore-from-trash —
 * and, the reason it is an element rather than a row in a settings screen, **reference tags**.
 * `craft\htmlfield\HtmlFieldData` parses ref tags over every rich-text value, so
 * `{eye:pricing-calculator:render}` renders this embed inside CKEditor content, Redactor
 * content, or any other HTML field, with no template changes and no per-editor rendering code.
 *
 * **Localized, but not translatable.** An embed exists on every site — an embed referenced from
 * the Spanish page has to be *findable* from the Spanish page, and an element that only lives on
 * the primary site is not — while its URL and options live in one row, shared by all of them.
 *
 * @property-read EmbedOptions $options
 * @property-read FramabilityResult $framability
 */
class Embed extends Element
{
    public ?string $handle = null;

    public ?string $url = null;

    /** The provider handle, resolved from the URL on save. */
    public string $provider = 'generic';

    /** Mirrors `options.mode`, as a column, so the index can sort and filter on it. */
    public string $mode = EmbedOptions::MODE_RATIO;

    /** The last framability verdict. Denormalised so the index does not have to probe. */
    public ?string $framable = null;

    public ?string $checkMessage = null;

    public ?DateTime $checkedAt = null;

    private ?EmbedOptions $_options = null;

    private ?ProviderMatch $_match = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('eye', 'Embed');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('eye', 'embed');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('eye', 'Embeds');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('eye', 'embeds');
    }

    public static function refHandle(): ?string
    {
        return 'eye';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * Enabled/disabled is meaningful here: a disabled embed renders nothing at all, which is how
     * you take a third party off every page at once without hunting down the references.
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * Localized so that every site can *find* an embed. Not translatable: {@see self::afterSave()}
     * writes one row, whichever site the save came from.
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    public function getSupportedSites(): array
    {
        return Craft::$app->getSites()->getAllSiteIds();
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @return EmbedQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new EmbedQuery(static::class);
    }

    // Options
    // -------------------------------------------------------------------------

    /**
     * The `config` column, straight off the query.
     *
     * Craft hands every selected column to the element's constructor, so a stored column with no
     * matching property is a fatal `UnknownPropertyException` the moment anything loads an embed
     * from the database — not at save time, and not anywhere near the line that caused it.
     */
    public function setConfig(mixed $value): void
    {
        $this->setOptions($value);
    }

    /**
     * `checkedAt` arrives from the database as a string and is typed here as a `DateTime`.
     * Naming it here is what makes Craft convert it.
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['checkedAt']);
    }

    public function setOptions(mixed $value): void
    {
        $this->_options = $value instanceof EmbedOptions ? $value : EmbedOptions::fromArray(
            is_array($value) || is_string($value) ? $value : null
        );

        $this->mode = $this->_options->mode;
    }

    public function getOptions(): EmbedOptions
    {
        if ($this->_options === null) {
            $this->_options = Plugin::getInstance()->getSettings()->getDefaultEmbedOptions();
        }

        return $this->_options;
    }

    /** What the provider registry makes of this embed's URL. */
    public function getProviderMatch(): ?ProviderMatch
    {
        if ($this->_match === null && $this->url) {
            $this->_match = Plugin::getInstance()->providers->match($this->url);
        }

        return $this->_match;
    }

    /** The URL the `<iframe>` actually points at, which is rarely the one the author pasted. */
    public function getEmbedUrl(): string
    {
        return $this->getProviderMatch()->embedUrl ?? (string)$this->url;
    }

    public function getProviderName(): string
    {
        return Plugin::getInstance()->providers->getByHandle($this->provider)->name
            ?? Craft::t('eye', 'Web page');
    }

    // Rendering
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides Per-call overrides, for a template that wants one
     * embed to behave differently in one place.
     */
    public function render(array $overrides = []): Markup
    {
        return Plugin::getInstance()->renderer->renderEmbed($this, $overrides);
    }

    /**
     * The property `{eye:pricing:render}` resolves.
     *
     * Craft splices the result in raw, so this is the entire rich-text integration: whatever
     * editor produced the content, the tag renders the same embed the same way.
     */
    public function getRender(): Markup
    {
        return $this->render();
    }

    /**
     * Lets a reference tag carry options: `{eye:map:render(click,height=500)}`.
     *
     * Craft resolves a reference tag by reading the named property off the element, and its
     * pattern is loose enough to allow parentheses — so one embed can behave differently in one
     * place without a second mechanism and without the editors having to store anything beyond
     * the tag they already write.
     *
     * `render` on its own is not handled here: Yii finds {@see self::getRender()} first.
     */
    public function __get($name)
    {
        $overrides = $this->parseRenderCall((string)$name);

        return $overrides !== null ? $this->render($overrides) : parent::__get($name);
    }

    public function __isset($name): bool
    {
        return $this->parseRenderCall((string)$name) !== null || parent::__isset($name);
    }

    /** @return array<string, mixed>|null */
    private function parseRenderCall(string $name): ?array
    {
        if (!preg_match('/^render\((.*)\)$/s', $name, $matches)) {
            return null;
        }

        // Anyone who can write rich text can write this tag, so it only gets the presentational
        // options (see EmbedOptions::REF_TAG_KEYS), and cannot turn an embed into a server-side
        // fetch that whoever manages it never chose.
        $overrides = EmbedOptions::only(EmbedOptions::parseEmbedOptions($matches[1]), EmbedOptions::REF_TAG_KEYS);

        if (
            isset($overrides['mode'])
            && in_array($overrides['mode'], EmbedOptions::FETCHING_MODES, true)
            && !$this->getOptions()->getIsFetched()
        ) {
            unset($overrides['mode']);
        }

        return $overrides;
    }

    /** The tag an author copies out of the CP. */
    public function getEmbedCode(array $overrides = []): string
    {
        $ref = $this->handle ?: $this->id;

        if (!$overrides) {
            return sprintf('{eye:%s:render}', $ref);
        }

        return sprintf('{eye:%s:render(%s)}', $ref, EmbedOptions::toEmbedOptions($overrides));
    }

    // Framability
    // -------------------------------------------------------------------------

    /** The stored verdict, without going near the network. */
    public function getFramability(): FramabilityResult
    {
        return new FramabilityResult([
            'url' => (string)$this->url,
            'status' => $this->framable ?: FramabilityResult::STATUS_UNKNOWN,
            'message' => (string)$this->checkMessage,
            'checkedAt' => $this->checkedAt?->getTimestamp(),
        ]);
    }

    // Element plumbing
    // -------------------------------------------------------------------------

    public function getRef(): ?string
    {
        return $this->handle;
    }

    public function getUiLabel(): string
    {
        return $this->title ?: ($this->url ?: Craft::t('eye', 'Untitled embed'));
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("eye/embeds/$this->id");
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('eye/embeds');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Dashes allowed on purpose: `{eye:price-list:render}` parses fine — Craft's ref pattern
        // is far looser than its handle pattern — and reads better than `priceList`.
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('eye', 'Handles must start with a letter and contain only letters, numbers, dashes and underscores.')];
        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'validateHandleIsFree'];
        $rules[] = [['url'], 'required'];
        $rules[] = [['url'], 'validateUrl'];
        $rules[] = [['provider'], 'string', 'max' => 64];
        $rules[] = [['mode'], 'in', 'range' => EmbedOptions::MODES];

        return $rules;
    }

    /**
     * Handles have to be unique among *live* embeds, not among rows.
     *
     * A `UniqueValidator` over `eye_embeds` would be the obvious rule and is the wrong one: a
     * deleted embed keeps its row until garbage collection, so its handle would stay taken
     * forever and an author would be told "already taken" by something they cannot see anywhere.
     * Element queries exclude trashed elements, which is the same question the author is asking.
     */
    public function validateHandleIsFree(string $attribute): void
    {
        if (!$this->handle) {
            return;
        }

        if (Plugin::getInstance()->embeds->handleIsTaken($this->handle, $this->id)) {
            $this->addError($attribute, Craft::t('eye', 'Another embed is already using the handle “{handle}”.', [
                'handle' => $this->handle,
            ]));
        }
    }

    public function validateUrl(string $attribute): void
    {
        $url = trim((string)$this->url);

        if ($url === '') {
            return;
        }

        if (!preg_match('~^https?://~i', $url)) {
            $this->addError($attribute, Craft::t('eye', 'The URL must start with http:// or https://.'));

            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->addError($attribute, Craft::t('eye', 'That does not look like a URL.'));

            return;
        }

        // A fetching mode is a promise Eye may not be able to keep. Saying so at save time is
        // the difference between a settings problem and a mystery.
        if ($this->getOptions()->getIsFetched()) {
            $settings = Plugin::getInstance()->getSettings();

            if (!$settings->proxyEnabled) {
                $this->addError('mode', Craft::t('eye', 'Proxy and inline modes need Eye’s proxy turned on in the plugin settings.'));
            } elseif (!Plugin::getInstance()->fetcher->urlIsAllowed($url)) {
                $this->addError('mode', Craft::t('eye', 'Proxy and inline modes only work for hosts on Eye’s allowed list. Add {host} to it, or choose another mode.', [
                    'host' => parse_url($url, PHP_URL_HOST) ?: $url,
                ]));
            }
        }
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'handle' => Craft::t('eye', 'Handle'),
            'url' => Craft::t('eye', 'URL'),
            'mode' => Craft::t('eye', 'Mode'),
        ]);
    }

    public function beforeSave(bool $isNew): bool
    {
        $this->url = trim((string)$this->url) ?: null;

        if (!$this->title && $this->url) {
            $this->title = $this->deriveTitle();
        }

        if (!$this->handle && ($this->title || $this->url)) {
            $this->handle = Plugin::getInstance()->embeds->uniqueHandle($this->title ?: (string)$this->url, $this->id);
        }

        // The provider is derived, never author-supplied: it is a fact about the URL, and an
        // author who edits the URL should not have to remember to re-pick it.
        if ($this->url) {
            $this->provider = $this->getProviderMatch()?->provider->handle ?? 'generic';
        }

        $this->mode = $this->getOptions()->mode;

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        // One row, whichever site the save came from — an embed is localized so it can be found
        // everywhere, not so it can differ.
        if (!$this->propagating) {
            $record = $isNew ? new EmbedRecord() : EmbedRecord::findOne($this->id);

            if (!$record) {
                // A restored element, or one whose row went missing. Write it back rather than
                // failing the save and leaving an element with no embed behind it.
                $record = new EmbedRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->handle = (string)$this->handle;
            $record->url = (string)$this->url;
            $record->provider = $this->provider;
            $record->mode = $this->mode;
            $record->config = json_encode($this->getOptions()->toStorageArray());
            $record->framable = $this->framable;
            $record->checkMessage = $this->checkMessage;
            $record->checkedAt = Db::prepareDateForDb($this->checkedAt);
            $record->save(false);

            // Fetch the poster now, in the background, so the card has its self-hosted copy
            // before the first reader arrives. Never a reason for the save itself to fail.
            try {
                Plugin::getInstance()->posters->queue(Plugin::getInstance()->posters->sourceFor($this));
            } catch (Throwable $e) {
                Craft::warning('Eye could not queue a poster download: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        parent::afterSave($isNew);
    }

    /**
     * Frees the handle when an embed goes to the trash.
     *
     * Craft's delete is a soft delete, so the row — and its handle — outlive the embed an author
     * can see. Without this, deleting "map" and immediately recreating it fails on a unique
     * index pointing at something invisible.
     */
    public function afterDelete(): void
    {
        if ($this->handle && $this->id) {
            Craft::$app->getDb()->createCommand()
                ->update(EmbedRecord::TABLE, ['handle' => substr((string)$this->handle, 0, 40) . '--trashed-' . $this->id], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    /**
     * Takes the handle back, or a variation of it if the name has been reused meanwhile — an
     * embed that cannot come back out of the trash is worse than one that comes back as `map-2`.
     */
    public function afterRestore(): void
    {
        if ($this->id) {
            $handle = preg_replace('/--trashed-\d+$/', '', (string)$this->handle) ?: 'embed';
            $embeds = Plugin::getInstance()->embeds;

            if ($embeds->handleIsTaken($handle, $this->id)) {
                $handle = $embeds->uniqueHandle($handle, $this->id);
            }

            $this->handle = $handle;
            Craft::$app->getDb()->createCommand()
                ->update(EmbedRecord::TABLE, ['handle' => $handle], ['id' => $this->id])
                ->execute();
        }

        parent::afterRestore();
    }

    /** A title from the URL, so an author who pastes and saves still gets something readable. */
    private function deriveTitle(): string
    {
        $match = $this->getProviderMatch();

        if ($match && $match->label !== '') {
            return $match->label;
        }

        $host = parse_url((string)$this->url, PHP_URL_HOST) ?: (string)$this->url;
        $name = $match && !$match->provider->getIsGeneric() ? $match->provider->name : preg_replace('/^www\./', '', $host);

        return Craft::t('eye', '{name} embed', ['name' => $name]);
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'url', 'provider'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('eye', 'All embeds'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('eye', 'Behaviour')],
        ];

        foreach ([
            EmbedOptions::MODE_RATIO => Craft::t('eye', 'Aspect ratio'),
            EmbedOptions::MODE_FIXED => Craft::t('eye', 'Fixed height'),
            EmbedOptions::MODE_AUTO => Craft::t('eye', 'Auto height'),
            EmbedOptions::MODE_PROXY => Craft::t('eye', 'Proxied'),
            EmbedOptions::MODE_INLINE => Craft::t('eye', 'Inlined'),
        ] as $mode => $label) {
            $sources[] = [
                'key' => "mode:$mode",
                'label' => $label,
                'criteria' => ['mode' => $mode],
            ];
        }

        $sources[] = ['heading' => Craft::t('eye', 'Health')];
        $sources[] = [
            'key' => 'framable:problem',
            'label' => Craft::t('eye', 'Needs attention'),
            'criteria' => ['framable' => [
                FramabilityResult::STATUS_DENIED,
                FramabilityResult::STATUS_RESTRICTED,
                FramabilityResult::STATUS_ERROR,
            ]],
        ];

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('eye', 'Handle')],
            'url' => ['label' => Craft::t('eye', 'URL')],
            'provider' => ['label' => Craft::t('eye', 'Provider')],
            'mode' => ['label' => Craft::t('eye', 'Mode')],
            'loading' => ['label' => Craft::t('eye', 'Loading')],
            'framable' => ['label' => Craft::t('eye', 'Framing')],
            'embedCode' => ['label' => Craft::t('eye', 'Embed')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['provider', 'mode', 'framable', 'embedCode', 'dateUpdated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'eye_embeds.handle' => Craft::t('eye', 'Handle'),
            'eye_embeds.provider' => Craft::t('eye', 'Provider'),
            'eye_embeds.mode' => Craft::t('eye', 'Mode'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode((string)$this->handle)),
            'url' => Html::a(Html::encode($this->shortUrl()), (string)$this->url, [
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'title' => (string)$this->url,
            ]),
            'provider' => Html::encode($this->getProviderName()),
            'mode' => Html::encode(ucfirst($this->mode)),
            'loading' => Html::encode(ucfirst($this->getOptions()->loading)),
            'framable' => $this->framabilityHtml(),
            'embedCode' => Cp::renderTemplate('_includes/forms/copytextbtn.twig', [
                'class' => ['code', 'small', 'light'],
                'value' => $this->getEmbedCode(),
            ]),
            default => parent::attributeHtml($attribute),
        };
    }

    private function framabilityHtml(): string
    {
        $framability = $this->getFramability();

        return Html::tag('span', Html::tag('span', '', [
                'class' => ['status', $framability->getStatusColor()],
            ]) . Html::encode($framability->getStatusLabel()), [
            'class' => 'flex',
            'title' => $framability->message,
        ]);
    }

    private function shortUrl(): string
    {
        $url = preg_replace('~^https?://(www\.)?~', '', (string)$this->url) ?? (string)$this->url;

        return strlen($url) > 48 ? substr($url, 0, 45) . '…' : $url;
    }

    // Metadata
    // -------------------------------------------------------------------------

    protected function metadata(): array
    {
        $framability = $this->getFramability();

        return [
            Craft::t('eye', 'Provider') => $this->getProviderName(),
            Craft::t('eye', 'Mode') => ucfirst($this->mode),
            Craft::t('eye', 'Framing') => $framability->getStatusLabel(),
            Craft::t('eye', 'Reference') => fn() => Html::tag('code', Html::encode($this->getEmbedCode())),
        ];
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
