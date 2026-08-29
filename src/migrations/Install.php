<?php

namespace justinholtweb\eye\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\eye\records\EmbedRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(EmbedRecord::TABLE)) {
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

        return true;
    }

    public function safeDown(): bool
    {
        $this->delete(Table::ELEMENTS, ['type' => \justinholtweb\eye\elements\Embed::class]);
        $this->dropTableIfExists(EmbedRecord::TABLE);

        return true;
    }
}
