<?php

namespace App\Generator;

use App\Attribute\MapTo;
use App\Attribute\MapCollection;
use ReflectionClass;
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

    public function getPropertyMapping(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $mapping = [];

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            $attributes = $property->getAttributes(MapTo::class);
            if (!empty($attributes)) {
                /** @var MapTo $mapTo */
                $mapTo = $attributes[0]->newInstance();
                $mapping[$propertyName] = [
                    'target' => $mapTo->targetProperty,
                    'custom_mapper' => $mapTo->mapperMethod,
                ];
            } else {
                $mapping[$propertyName] = [
                    'target' => $propertyName,
                    'custom_mapper' => null,
                ];
            }

            $collectionAttrs = $property->getAttributes(MapCollection::class);
            if (!empty($collectionAttrs)) {
                /** @var MapCollection $collAttr */
                $collAttr = $collectionAttrs[0]->newInstance();
                $mapping[$propertyName]['collection_type'] = $collAttr->itemType;
            }
        }

        return $mapping;
    }
}
