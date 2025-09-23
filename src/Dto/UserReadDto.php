<?php

namespace App\Dto;

use App\Attribute\MapsFromEntity;

#[MapsFromEntity(entityClass: \App\Entity\User::class, mapperName: 'UserReadMapper')]
class UserReadDto
{
    public function __construct(
        public int $userId,
        public string $fullName,
        public AddressDto $address,
        public array $emails
    ) {}
}
