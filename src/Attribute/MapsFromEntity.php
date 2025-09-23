<?php

namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class MapsFromEntity
{
    public function __construct(
        public string $entityClass,
        public ?string $mapperName = null
    ) {}
}
