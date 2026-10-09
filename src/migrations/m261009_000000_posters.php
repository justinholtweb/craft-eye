<?php

namespace justinholtweb\eye\migrations;

use craft\db\Migration;
use justinholtweb\eye\records\PosterRecord;

/**
 * Self-hosted posters: one row per remote poster URL, pointing at the asset Eye saved it as.
 *
 * Keyed by URL rather than hung off an embed, because a poster belongs to whatever renders it — a
 * library embed, an Embed field value, a `craft.eye.url()` call — and only one of those has an
 * element to hang anything off.
 */
class m261009_000000_posters extends Migration
{
    public function safeUp(): bool
    {
        Install::createPostersTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(PosterRecord::TABLE);

        return true;
    }
}
