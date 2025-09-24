<?php

namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MapCollection
{
    public function __construct(
        public string $itemType,
    ) {}
}
