<?php

namespace App\Dto;

class AddressDto
{
    public function __construct(
        public string $street,
        public string $city
    ) {}
}
