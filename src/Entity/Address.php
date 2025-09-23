<?php

namespace App\Entity;

class Address
{
    public function __construct(
        public string $street,
        public string $city
    ) {}
}
