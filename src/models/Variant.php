<?php

namespace jholt\wink\models;

use craft\base\Model;

class Variant extends Model
{
    public ?int $id = null;
    public ?int $experimentId = null;
    public string $handle = '';
    public string $title = '';
    public ?string $content = null;
    public int $weight = 50;
    public int $sortOrder = 0;
    public bool $isControl = false;

    protected function defineRules(): array
    {
        return [
            [['handle', 'title', 'experimentId'], 'required'],
            [['handle'], 'string', 'max' => 255],
            [['title'], 'string', 'max' => 255],
            [['weight'], 'integer', 'min' => 0, 'max' => 100],
            [['sortOrder'], 'integer', 'min' => 0],
        ];
    }
}
