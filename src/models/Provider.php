<?php

namespace justinholtweb\eye\models;

use craft\base\Model;

/**
 * What Eye knows about one embeddable service.
 *
 * A provider is the difference between an author pasting a YouTube URL and an author hand-writing
 * an `<iframe>` with the right `allow` tokens, the right aspect ratio and the privacy-friendly
 * host. Everything here is a *default*: an embed may override any of it.
 */
class Provider extends Model
{
    public string $handle = 'generic';

    public string $name = 'Embed';

    /** The service's own home page, shown on fallback cards. */
    public string $homepage = '';

    /** Natural aspect ratio, when the service has one. */
    public ?string $ratio = null;

    /** A sensible fixed height, for services that do not scale (audio players, forms). */
    public ?int $height = null;

    /** The render mode this service wants. */
    public string $mode = EmbedOptions::MODE_RATIO;

    /** Permissions-Policy features the service needs to work. */
    public array $allow = [];

    public bool $allowFullscreen = true;

    /**
     * Whether Eye has a privacy-friendlier way to embed this service — a cookie-less host, a
     * do-not-track flag. Surfaced in the CP so an author can see which embeds are quiet.
     */
    public bool $hasPrivacyVariant = false;

    /** Sentence shown on a click-to-load card, explaining who the reader is about to contact. */
    public string $consentText = '';

    /**
     * The generic provider is what an unrecognised URL gets: framed as-is, with no assumptions.
     */
    public function getIsGeneric(): bool
    {
        return $this->handle === 'generic';
    }

    /** Provider defaults as an {@see EmbedOptions} overlay. */
    public function getOptionDefaults(): array
    {
        $defaults = [
            'mode' => $this->mode,
            'allow' => $this->allow,
            'allowFullscreen' => $this->allowFullscreen,
        ];

        if ($this->ratio !== null) {
            $defaults['ratio'] = $this->ratio;
        }

        if ($this->height !== null) {
            $defaults['height'] = $this->height;
        }

        if ($this->consentText !== '') {
            $defaults['consentText'] = $this->consentText;
        }

        return $defaults;
    }
}
