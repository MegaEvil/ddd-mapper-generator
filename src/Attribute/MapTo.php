<?php

namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MapTo
{
    public function __construct(
        public string $targetProperty,
        public ?string $mapperMethod = null
    ) {}
}
