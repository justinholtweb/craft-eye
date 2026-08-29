<?php

namespace justinholtweb\eye\models;

use craft\base\Model;
use craft\helpers\Json;
use justinholtweb\eye\Plugin;
use Twig\Markup;

/**
 * An embed that has no element behind it — one URL, configured where it is used.
 *
 * The value of {@see \justinholtweb\eye\fields\EmbedField}. Deliberately renders through the same
 * {@see \justinholtweb\eye\services\Renderer} as a library embed, so a template author never has
 * to know which kind they are holding: both answer to `{{ entry.video }}`.
 */
class InlineEmbed extends Model
{
    public string $url = '';

    private ?EmbedOptions $_options = null;

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = Json::decodeIfJson($value);
            $value = is_array($decoded) ? $decoded : ['url' => $value];
        }

        $embed = new self();

        if (is_array($value)) {
            $embed->url = trim((string)($value['url'] ?? ''));
            $embed->setOptions($value['options'] ?? null);
        }

        return $embed;
    }

    public function setOptions(mixed $value): void
    {
        $this->_options = $value instanceof EmbedOptions
            ? $value
            : EmbedOptions::fromArray(is_array($value) || is_string($value) ? $value : null);
    }

    public function getOptions(): EmbedOptions
    {
        if ($this->_options === null) {
            $this->_options = Plugin::getInstance()->getSettings()->getDefaultEmbedOptions();
        }

        return $this->_options;
    }

    public function getIsEmpty(): bool
    {
        return trim($this->url) === '';
    }

    public function getProviderMatch(): ?ProviderMatch
    {
        return $this->url !== '' ? Plugin::getInstance()->providers->match($this->url) : null;
    }

    public function render(array $overrides = []): Markup
    {
        if ($this->getIsEmpty()) {
            return new Markup('', 'UTF-8');
        }

        return Plugin::getInstance()->renderer->renderUrl($this->url, $this->getOptions()->merge($overrides));
    }

    /** So `{{ entry.video }}` on its own renders the embed, which is what everyone types first. */
    public function __toString(): string
    {
        return (string)$this->render();
    }

    /** @return array<string, mixed> */
    public function forStorage(): array
    {
        return [
            'url' => $this->url,
            'options' => $this->getOptions()->toStorageArray(),
        ];
    }
}
