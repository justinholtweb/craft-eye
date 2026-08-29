<?php

namespace justinholtweb\eye\twig;

use Craft;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\models\InlineEmbed;
use justinholtweb\eye\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The `eye` filter and function.
 *
 * `{{ 'https://youtu.be/x'|eye }}`, `{{ 'pricing'|eye }}`, `{{ entry.video|eye({ loading: 'click' }) }}`
 * and `{{ eye('pricing') }}` all land on the same renderer, so a template author never has to
 * know whether they are holding a URL, a handle, an element or an inline field value.
 */
class Extension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('eye', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('eye', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function render(mixed $value, array $options = []): Markup
    {
        if ($value instanceof Embed || $value instanceof InlineEmbed) {
            return $value->render($options);
        }

        if (is_string($value) && preg_match('~^https?://~i', trim($value))) {
            $defaults = Plugin::getInstance()->getSettings()->getDefaultEmbedOptions();

            return Plugin::getInstance()->renderer->renderUrl(trim($value), $defaults->merge($options));
        }

        if (is_string($value) || is_int($value)) {
            $embed = Plugin::getInstance()->embeds->resolve($value);

            if ($embed) {
                return $embed->render($options);
            }
        }

        return new Markup('', Craft::$app->charset);
    }
}
