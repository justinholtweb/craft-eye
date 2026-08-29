<?php

namespace justinholtweb\eye\richtext;

use craft\htmlfield\events\ModifyPurifierConfigEvent;
use craft\htmlfield\HtmlField;
use yii\base\Event;

/**
 * Keeps `<div class="eye-embed" data-eye-handle="…">` alive through HTML Purifier.
 *
 * Purifier drops attributes it has never heard of, and `data-eye-handle` is one — so without this
 * an embed survives its first save as a bare div and the editor can no longer recognise it on the
 * way back in. (The reference tag inside is plain text and was never at risk; this is about the
 * editor's ability to show a card instead of raw braces.)
 *
 * Registered against `HtmlField` rather than against CKEditor's and Redactor's field classes
 * separately: Yii matches class-level handlers up the inheritance chain, so one handler covers
 * both, and any other `craftcms/html-field` field that ever ships.
 */
class PurifierSupport
{
    public function register(): void
    {
        if (!class_exists(HtmlField::class)) {
            return;
        }

        Event::on(HtmlField::class, 'modifyPurifierConfig', function(ModifyPurifierConfigEvent $event) {
            $definition = $event->config?->getDefinition('HTML', true);

            if (!$definition) {
                return;
            }

            /** @phpstan-ignore-next-line */
            $definition->addAttribute('div', 'data-eye-handle', 'Text');
            /** @phpstan-ignore-next-line */
            $definition->addAttribute('div', 'data-eye-label', 'Text');
            /** @phpstan-ignore-next-line */
            $definition->addAttribute('div', 'data-eye-options', 'Text');
        });
    }
}
