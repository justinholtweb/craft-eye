<?php

namespace justinholtweb\eye\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \justinholtweb\eye\elements\Embed[] all($db = null)
 * @method \justinholtweb\eye\elements\Embed|null one($db = null)
 * @method \justinholtweb\eye\elements\Embed|null nth(int $n, $db = null)
 */
class EmbedQuery extends ElementQuery
{
    public mixed $handle = null;

    public mixed $url = null;

    public mixed $provider = null;

    public mixed $mode = null;

    public mixed $framable = null;

    public function handle(mixed $value): self
    {
        $this->handle = $value;

        return $this;
    }

    public function url(mixed $value): self
    {
        $this->url = $value;

        return $this;
    }

    public function provider(mixed $value): self
    {
        $this->provider = $value;

        return $this;
    }

    public function mode(mixed $value): self
    {
        $this->mode = $value;

        return $this;
    }

    public function framable(mixed $value): self
    {
        $this->framable = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->handle === []) {
            return false;
        }

        $this->joinElementTable('eye_embeds');

        $this->query->select([
            'eye_embeds.handle',
            'eye_embeds.url',
            'eye_embeds.provider',
            'eye_embeds.mode',
            'eye_embeds.config',
            'eye_embeds.framable',
            'eye_embeds.checkMessage',
            'eye_embeds.checkedAt',
        ]);

        foreach (['handle', 'url', 'provider', 'mode', 'framable'] as $attribute) {
            if ($this->$attribute !== null) {
                $this->subQuery->andWhere(Db::parseParam("eye_embeds.$attribute", $this->$attribute));
            }
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'enabled' => ['elements.enabled' => true],
            'disabled' => ['elements.enabled' => false],
            default => parent::statusCondition($status),
        };
    }
}
