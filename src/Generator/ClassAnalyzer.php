<?php

namespace App\Generator;

use App\Attribute\MapTo;
use App\Attribute\MapCollection;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

class ClassAnalyzer
{
    public function getPublicProperties(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;
            $properties[] = $property->getName();
        }

        return $properties;
    }

    public function getGetters(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $getters = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $methodName = $method->getName();
            if (str_starts_with($methodName, 'get') && strlen($methodName) > 3) {
                $propertyName = lcfirst(substr($methodName, 3));
                $getters[$propertyName] = $methodName;
            } elseif (str_starts_with($methodName, 'is') && strlen($methodName) > 2) {
                $propertyName = lcfirst(substr($methodName, 2));
                $getters[$propertyName] = $methodName;
            }
        }

        return $getters;
    }

    public function getSetters(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $setters = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() != 1) {
                continue;
            }

            $methodName = $method->getName();
            if (str_starts_with($methodName, 'set') && strlen($methodName) > 3) {
                $propertyName = lcfirst(substr($methodName, 3));
                $setters[$propertyName] = $methodName;
            }
        }

        return $setters;
    }

    public function getConstructorParams(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor) return [];

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $params[$param->getName()] = [
                'type' => $param->getType()?->getName(),
                'has_default' => $param->isDefaultValueAvailable(),
                'default_value' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $params;
    }

    public function invertEntityMapping(string $entityClass): array
    {
        $propertyMapping = $this->getPropertyMapping($entityClass);
        $inverted = [];

        foreach ($propertyMapping as $entityProperty => $mapping) {
            $dtoProperty = $entityProperty; // по умолчанию — такое же имя
            if (isset($mapping['target'])) {
                $dtoProperty = $mapping['target']; // например, 'userId'
            }

            // Инвертируем: dtoProperty → entityProperty
            $inverted[$dtoProperty] = [
                'target' => $entityProperty,
                'collection_type' => $mapping['collection_type'] ?? null,
                'custom_mapper' => $mapping['custom_mapper'] ?? null,
            ];
        }

        return $inverted;
    }

    public function getPropertyMapping(string $className, array $invertedMapping = []): array
    {
        $reflection = new ReflectionClass($className);
        $mapping = [];

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            $custom_mapper = null;
            $attributes = $property->getAttributes(MapTo::class);
            if (!empty($attributes)) {
                /** @var MapTo $mapTo */
                $mapTo = $attributes[0]->newInstance();

                $target = $mapTo->targetProperty;
                $custom_mapper = $mapTo->mapperMethod;
            } elseif (!empty($invertedMapping[$propertyName])) {
                $target = $invertedMapping[$propertyName]['target'];
                $custom_mapper = $invertedMapping[$propertyName]['custom_mapper'];
            } else {
                $target = $propertyName;
            }

            $mapping[$propertyName] = [
                'target' => $target,
                'custom_mapper' => $custom_mapper,
            ];
            $collectionAttrs = $property->getAttributes(MapCollection::class);
            if (!empty($collectionAttrs)) {
                /** @var MapCollection $collAttr */
                $collAttr = $collectionAttrs[0]->newInstance();
                $mapping[$propertyName]['collection_type'] = $collAttr->itemType;
                continue;
            }

            if (!empty($invertedMapping[$propertyName]['collection_type'])) {
                $mapping[$propertyName]['collection_type'] = $invertedMapping[$propertyName]['collection_type'];
            }
        }

        return $mapping;
    }

    public function getPublicPropertyType(string $className, string $propertyName): ?string
    {
        $reflection = new ReflectionClass($className);
        try {
            $property = $reflection->getProperty($propertyName);
            $type = $property->getType();
            return $type ? $type->getName() : null;
        } catch (ReflectionException $e) {
            return null;
        }
    }

    public function getGetterReturnType(string $className, string $getter): ?string
    {
        $reflection = new ReflectionMethod($className, $getter);
        $type = $reflection->getReturnType();
        return $type ? $type->getName() : null;
    }
}
