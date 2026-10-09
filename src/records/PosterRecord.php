<?php

namespace justinholtweb\eye\records;

use craft\db\ActiveRecord;

/**
 * A self-hosted copy of one remote poster image.
 *
 * @property int $id
 * @property string $urlHash
 * @property string $url
 * @property int|null $assetId
 * @property string $status
 * @property string|null $error
 * @property string|null $attemptedAt
 */
class PosterRecord extends ActiveRecord
{
    public const TABLE = '{{%eye_posters}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
