<?php

namespace justinholtweb\eye\models;

use craft\base\Model;

/**
 * The result of pointing {@see \justinholtweb\eye\services\Providers} at a URL.
 *
 * Separate from {@see Provider} because a provider is a static description of a service, while
 * this is what that service says about *one* URL: where the frame should point, what the poster
 * image is, and which of the provider's defaults survived contact with the URL's own parameters.
 */
class ProviderMatch extends Model
{
    public Provider $provider;

    /** The URL the author gave. */
    public string $sourceUrl = '';

    /** The URL the `<iframe>` should point at. */
    public string $embedUrl = '';

    /** A poster image for the click-to-load card, when the service exposes a predictable one. */
    public string $posterUrl = '';

    /** Anything the URL itself decided — a start time, an unlisted-video hash, a ratio. */
    public array $options = [];

    /** A human label, when the URL carries one. */
    public string $label = '';

    public function getOptionDefaults(): array
    {
        $defaults = $this->provider->getOptionDefaults();

        if ($this->posterUrl !== '') {
            $defaults['posterUrl'] = $this->posterUrl;
        }

        return array_merge($defaults, $this->options);
    }
}
