<?php

namespace justinholtweb\eye\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\InlineEmbed;
use justinholtweb\eye\Plugin;
use justinholtweb\eye\web\assets\editor\EditorAsset;
use yii\db\Schema;

/**
 * An embed configured where it is used: paste a URL, pick how it behaves.
 *
 * The counterpart to {@see EmbedsField}, which references the library. Use this one when the
 * embed belongs to the entry — a video that appears on one page and nowhere else — and the
 * library one when the same embed appears in several places and should be changed once.
 */
class EmbedField extends Field
{
    /** Which of the options an author may set on the entry. Everything else uses the defaults. */
    public array $enabledOptions = ['mode', 'ratio', 'height', 'loading', 'caption', 'title'];

    /** Defaults for a new value in this field, as an {@see EmbedOptions} array. */
    public array $defaultOptions = [];

    public static function displayName(): string
    {
        return Craft::t('eye', 'Embed');
    }

    public static function icon(): string
    {
        return 'eye';
    }

    public static function phpType(): string
    {
        return InlineEmbed::class;
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof InlineEmbed) {
            return $value;
        }

        $embed = InlineEmbed::fromValue($value);

        // A brand-new value inherits the field's defaults; a stored one does not, or editing the
        // field settings would silently rewrite every entry that already has a value.
        if ($value === null && $this->defaultOptions) {
            $embed->setOptions(EmbedOptions::fromArray($this->defaultOptions));
        }

        return $embed;
    }

    /**
     * A posted value may only change the URL and the options this field lets authors set.
     *
     * The input only *shows* the enabled options; without this, anything else could still be
     * posted — fallback markup, CSS, a sandbox — by someone with nothing more than the right to
     * edit the entry. Everything else keeps the value it had (or the field's defaults).
     */
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        // A string is a URL. It is *not* decoded as JSON here, the way a stored value is: a
        // posted `{"url":…,"options":{…}}` would otherwise carry any option it liked straight
        // past the filter below.
        if (is_string($value)) {
            $value = ['url' => $value];
        }

        if (!is_array($value)) {
            return $this->normalizeValue($value, $element);
        }

        $current = $element?->getFieldValue($this->handle);
        $current = $current instanceof InlineEmbed ? $current : $this->normalizeValue(null, $element);

        $allowed = [];

        foreach ($this->enabledOptions as $choice) {
            array_push($allowed, ...(EmbedOptions::FIELD_OPTION_KEYS[$choice] ?? []));
        }

        $posted = is_array($value['options'] ?? null) ? $value['options'] : [];

        $embed = new InlineEmbed();
        $embed->url = trim((string)($value['url'] ?? ''));
        $embed->setOptions($current->getOptions()->merge(EmbedOptions::only($posted, $allowed)));

        return $embed;
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element) {
                    $value = $element->getFieldValue($this->handle);

                    if ($value instanceof InlineEmbed && !$value->getIsEmpty() && !EmbedOptions::isHttpUrl($value->url)) {
                        $element->addError("field:$this->handle", Craft::t('eye', 'The URL must start with http:// or https://.'));
                    }
                },
            ],
        ];
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        $embed = $value instanceof InlineEmbed ? $value : InlineEmbed::fromValue($value);

        return $embed->getIsEmpty() ? null : Json::encode($embed->forStorage());
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditorAsset::class);

        /** @var InlineEmbed $value */
        $value = $value instanceof InlineEmbed ? $value : InlineEmbed::fromValue($value);
        $settings = Plugin::getInstance()->getSettings();

        return $view->renderTemplate('eye/_field/input', [
            'field' => $this,
            'id' => $view->namespaceInputId($this->handle),
            'name' => $this->handle,
            'namespacedName' => $view->namespaceInputName($this->handle),
            'value' => $value,
            'options' => $value->getOptions(),
            'match' => $value->getProviderMatch(),
            'enabledOptions' => $this->enabledOptions,
            'proxyUsable' => $settings->getProxyIsUsable(),
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('eye/_field/settings', [
            'field' => $this,
            'optionChoices' => $this->optionChoices(),
        ]);
    }

    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        $embed = $value instanceof InlineEmbed ? $value : InlineEmbed::fromValue($value);

        return implode(' ', array_filter([$embed->url, $embed->getOptions()->caption, $embed->getOptions()->title]));
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        $embed = $value instanceof InlineEmbed ? $value : InlineEmbed::fromValue($value);

        return $embed->getIsEmpty();
    }

    /** What the element index shows in this field's column: the provider and the host. */
    protected function previewHtml(mixed $value, ElementInterface $element): string
    {
        $embed = $value instanceof InlineEmbed ? $value : InlineEmbed::fromValue($value);

        if ($embed->getIsEmpty()) {
            return '';
        }

        $match = $embed->getProviderMatch();
        $name = $match && !$match->provider->getIsGeneric()
            ? $match->provider->name
            : (parse_url($embed->url, PHP_URL_HOST) ?: $embed->url);

        return Html::encode($name);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function optionChoices(): array
    {
        return [
            ['label' => Craft::t('eye', 'Mode'), 'value' => 'mode'],
            ['label' => Craft::t('eye', 'Aspect ratio'), 'value' => 'ratio'],
            ['label' => Craft::t('eye', 'Height'), 'value' => 'height'],
            ['label' => Craft::t('eye', 'Loading'), 'value' => 'loading'],
            ['label' => Craft::t('eye', 'Caption'), 'value' => 'caption'],
            ['label' => Craft::t('eye', 'Accessible title'), 'value' => 'title'],
            ['label' => Craft::t('eye', 'Alignment'), 'value' => 'align'],
            ['label' => Craft::t('eye', 'Consent text'), 'value' => 'consent'],
            ['label' => Craft::t('eye', 'Extract selector'), 'value' => 'extract'],
        ];
    }
}
