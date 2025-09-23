<?php

namespace App\Entity;

use App\Attribute\MapTo;
use App\Attribute\MapCollection;

class User
{
    #[MapTo('userId')]
    private int $id;

    #[MapTo('fullName')]
    private string $name;

    private Address $address;

    #[MapTo('emails'), MapCollection(Address::class)]
    private array $addresses;

    public function __construct(int $id, string $name, Address $address, array $addresses = [])
    {
        $this->id = $id;
        $this->name = $name;
        $this->address = $address;
        $this->addresses = $addresses;
    }

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getAddress(): Address { return $this->address; }
    public function getAddresses(): array { return $this->addresses; }
}
