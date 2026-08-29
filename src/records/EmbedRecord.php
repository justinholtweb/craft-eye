<?php

namespace justinholtweb\eye\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $handle
 * @property string $url
 * @property string $provider
 * @property string $mode
 * @property string|null $config
 * @property string|null $framable
 * @property string|null $checkMessage
 * @property string|null $checkedAt
 */
class EmbedRecord extends ActiveRecord
{
    public const TABLE = '{{%eye_embeds}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
