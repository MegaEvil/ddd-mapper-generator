<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class MapperConfig
{
    /**
     * @param array<array{entity: string, dto: string, mapper_name?: string}> $mappings
     */
    public function __construct(
        public array $mappings = []
    ) {}

    public static function fromYamlFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            return new self();
        }

        $data = Yaml::parseFile($filePath);
        return new self($data['mappers'] ?? []);
    }

    public function getMappingsForEntity(string $entityClass): array
    {
        return array_filter($this->mappings, fn($m) => $m['entity'] === $entityClass);
    }

    public function isMapped(string $entityClass, string $dtoClass): bool
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping['entity'] === $entityClass && $mapping['dto'] === $dtoClass) {
                return true;
            }
        }
        return false;
    }

    public function getMapperName(string $entityClass, string $dtoClass): ?string
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping['entity'] === $entityClass && $mapping['dto'] === $dtoClass) {
                return $mapping['mapper_name'] ?? null;
            }
        }
        return null;
    }

    public function addConfig(string $entityClass, string $dtoClass, ?string $mapper = null): void
    {
        if (!$this->isMapped($entityClass, $dtoClass)) {
            $mapping = [
                'entity' => $entityClass,
                'dto' => $dtoClass,
            ];

            if (!is_null($mapper)) {
                $mapping['mapper_name'] = $mapper;
            }

            $this->mappings[] = $mapping;
        }
    }
}
