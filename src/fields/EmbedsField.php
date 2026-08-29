<?php

namespace justinholtweb\eye\fields;

use Craft;
use craft\fields\BaseRelationField;
use justinholtweb\eye\elements\Embed;

/**
 * A relation to one or more embeds in the library.
 *
 * Deliberately a thin `BaseRelationField`: everything an author expects from a relation field —
 * the element selector, eager loading, the index, GraphQL, revisions — already works, and
 * reimplementing any of it would only make it work differently.
 */
class EmbedsField extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('eye', 'Embeds');
    }

    public static function icon(): string
    {
        return 'eye';
    }

    public static function elementType(): string
    {
        return Embed::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('eye', 'Add an embed');
    }
}
