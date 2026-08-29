<?php

namespace justinholtweb\eye\twig;

use Craft;
use justinholtweb\eye\elements\db\EmbedQuery;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\FramabilityResult;
use justinholtweb\eye\models\Provider;
use justinholtweb\eye\models\ProviderMatch;
use justinholtweb\eye\Plugin;
use Twig\Markup;

/**
 * `craft.eye` — the template API.
 *
 * Every method is spelled one way only. A bare `embeds()` beside a `getEmbeds()` getter is a trap
 * in Twig, which resolves the bare method first and re-enters it until the C stack gives out.
 */
class EyeVariable
{
    /** `craft.eye.embed('pricing')` — by handle or by id. */
    public function embed(string|int|null $reference): ?Embed
    {
        return Plugin::getInstance()->embeds->resolve($reference);
    }

    /**
     * `{{ craft.eye.render('pricing') }}` — the short way to put a library embed on a page.
     *
     * An unknown handle costs you an embed, not the page.
     */
    public function render(string|int|null $reference, array $options = []): Markup
    {
        $embed = $this->embed($reference);

        return $embed ? $embed->render($options) : new Markup('', Craft::$app->charset);
    }

    /**
     * `{{ craft.eye.url('https://youtu.be/…') }}` — everything Eye knows, applied to a URL that
     * is not in the library.
     */
    public function url(?string $url, array $options = []): Markup
    {
        if (!$url) {
            return new Markup('', Craft::$app->charset);
        }

        $defaults = Plugin::getInstance()->getSettings()->getDefaultEmbedOptions();

        return Plugin::getInstance()->renderer->renderUrl($url, $defaults->merge($options));
    }

    /** `craft.eye.all()` — a normal element query, for listing or filtering. */
    public function all(array $criteria = []): EmbedQuery
    {
        return Plugin::getInstance()->embeds->find($criteria);
    }

    /** The reference tag to paste into a rich-text field. */
    public function embedCode(string|int|null $reference, array $overrides = []): string
    {
        return $this->embed($reference)?->getEmbedCode($overrides) ?? '';
    }

    /** What Eye makes of a URL, without rendering anything. */
    public function match(?string $url): ?ProviderMatch
    {
        return $url ? Plugin::getInstance()->providers->match($url) : null;
    }

    /** @return array<string, Provider> */
    public function providers(): array
    {
        return Plugin::getInstance()->providers->getAll();
    }

    /**
     * Whether a URL will let this site frame it. Cached — this is a network call.
     */
    public function framability(?string $url): ?FramabilityResult
    {
        return $url ? Plugin::getInstance()->framability->check($url) : null;
    }

    /** The URL of the child script, for a page that wants to report its own height. */
    public function childScriptUrl(): string
    {
        return \craft\helpers\UrlHelper::siteUrl('eye/child.js');
    }

    /** A fresh options model, for a template that wants to build one up. */
    public function options(array $values = []): EmbedOptions
    {
        return Plugin::getInstance()->getSettings()->getDefaultEmbedOptions()->merge($values);
    }
}
