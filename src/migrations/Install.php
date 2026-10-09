<?php

namespace justinholtweb\eye\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\eye\records\EmbedRecord;
use justinholtweb\eye\records\PosterRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(EmbedRecord::TABLE)) {
            self::createPostersTable($this);

            return true;
        }

        $this->createTable(EmbedRecord::TABLE, [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(64)->notNull(),
            'url' => $this->text(),
            'provider' => $this->string(64)->notNull()->defaultValue('generic'),
            'mode' => $this->string(16)->notNull()->defaultValue('ratio'),
            'config' => $this->text(),

            // The framability verdict is denormalised onto the row so the element index can show
            // a status dot without probing two dozen third-party sites to draw one screen.
            'framable' => $this->string(16),
            'checkMessage' => $this->text(),
            'checkedAt' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, EmbedRecord::TABLE, ['handle'], true);
        $this->createIndex(null, EmbedRecord::TABLE, ['provider'], false);
        $this->createIndex(null, EmbedRecord::TABLE, ['mode'], false);

        // CASCADE, so deleting the element takes the row with it — the element is the record's
        // reason to exist, not the other way round.
        $this->addForeignKey(null, EmbedRecord::TABLE, ['id'], Table::ELEMENTS, ['id'], 'CASCADE', null);

        self::createPostersTable($this);

        return true;
    }

    /**
     * Shared with the migration that added it, so a fresh install and an upgrade end up with the
     * same table.
     */
    public static function createPostersTable(Migration $migration): void
    {
        if ($migration->db->tableExists(PosterRecord::TABLE)) {
            return;
        }

        $migration->createTable(PosterRecord::TABLE, [
            'id' => $migration->primaryKey(),
            // sha1 of the URL: a URL is too long for a unique index, and the lookup is exact.
            'urlHash' => $migration->char(40)->notNull(),
            'url' => $migration->text()->notNull(),
            'assetId' => $migration->integer(),
            // pending · ready · failed
            'status' => $migration->string(16)->notNull()->defaultValue('pending'),
            'error' => $migration->text(),
            'attemptedAt' => $migration->dateTime(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, PosterRecord::TABLE, ['urlHash'], true);

        // CASCADE: someone deleting the asset is a reason to fetch the poster again, not to point
        // at nothing.
        $migration->addForeignKey(null, PosterRecord::TABLE, ['assetId'], Table::ASSETS, ['id'], 'CASCADE', null);
    }

    public function safeDown(): bool
    {
        $this->delete(Table::ELEMENTS, ['type' => \justinholtweb\eye\elements\Embed::class]);
        $this->dropTableIfExists(PosterRecord::TABLE);
        $this->dropTableIfExists(EmbedRecord::TABLE);

        return true;
    }
}
