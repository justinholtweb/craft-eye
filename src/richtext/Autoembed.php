<?php

namespace justinholtweb\eye\richtext;

use Craft;
use craft\base\ElementInterface;
use craft\events\ElementEvent;
use craft\htmlfield\HtmlField;
use craft\htmlfield\HtmlFieldData;
use craft\services\Elements;
use justinholtweb\eye\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Turn a bare URL on its own line into an embed, when a rich-text field is saved.
 *
 * Off by default, because it rewrites what an author typed. The editor integrations do the same
 * thing at paste time, where the author can see it happen and undo it — which is the version most
 * people want. This exists for content that arrives from somewhere else: an import, a feed, the
 * Element API, a migration from a CMS whose editor did this automatically.
 *
 * What it writes is the same block the editors write, so there is still exactly one rendering
 * path.
 */
class Autoembed
{
    /** A paragraph whose entire contents are one URL. Anything else is left alone. */
    private const PATTERN = '~<p>\s*(?:<a[^>]*>)?\s*(https?://[^\s<"]+)\s*(?:</a>)?\s*</p>~i';

    public function register(): void
    {
        if (!class_exists(HtmlField::class)) {
            return;
        }

        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(ElementEvent $event) {
            $element = $event->element;

            // Propagation copies an already-processed value, and a draft or revision is not the
            // content anybody reads — doing the work there would create library entries for
            // every autosave.
            if ($element->propagating || $element->getIsDraft() || $element->getIsRevision() || $element->resaving) {
                return;
            }

            try {
                $this->process($element);
            } catch (Throwable $e) {
                // Never fail somebody's save over a convenience.
                Craft::warning('Eye auto-embed skipped: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        });
    }

    private function process(ElementInterface $element): void
    {
        $layout = $element->getFieldLayout();

        if (!$layout) {
            return;
        }

        foreach ($layout->getCustomFields() as $field) {
            if (!$field instanceof HtmlField) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);
            $html = $value instanceof HtmlFieldData ? $value->getRawContent() : (is_string($value) ? $value : null);

            if (!$html || !str_contains($html, 'http')) {
                continue;
            }

            $replaced = $this->rewrite($html);

            if ($replaced !== $html) {
                $element->setFieldValue($field->handle, $replaced);
            }
        }
    }

    private function rewrite(string $html): string
    {
        $embeds = Plugin::getInstance()->embeds;

        return preg_replace_callback(self::PATTERN, function(array $matches) use ($embeds) {
            $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');

            // Reuse before creating, or a feed that mentions the same video twice a week fills
            // the library with duplicates.
            $embed = \justinholtweb\eye\elements\Embed::find()->url($url)->status(null)->one();

            if (!$embed) {
                $embed = $embeds->createFromUrl($url);

                if (!$embeds->saveEmbed($embed)) {
                    return $matches[0];
                }
            }

            return sprintf(
                '<div class="eye-embed" data-eye-handle="%s" data-eye-label="%s">%s</div>',
                htmlspecialchars((string)$embed->handle, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($embed->getUiLabel(), ENT_QUOTES, 'UTF-8'),
                $embed->getEmbedCode(),
            );
        }, $html) ?? $html;
    }
}
