<?php

namespace App\Dto;

use App\Attribute\MapTo;

class UserProfileDto
{
    #[MapTo('id')]
    public int $id;

    #[MapTo('name')]
    public string $displayName;
    public string $city;
}
