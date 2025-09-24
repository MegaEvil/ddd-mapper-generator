<?php

namespace App\Generator;

use PhpParser\Builder\Method;
use PhpParser\Builder\Param;
use PhpParser\BuilderFactory;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Stmt\Namespace_;

class MapperGenerator
{
    private ClassAnalyzer $analyzer;
    private BuilderFactory $factory;
    private Standard $printer;
    private array $dependencies = [];

    private array $usedClasses = [];

    private string $namespace;

    public function __construct()
    {
        $this->analyzer = new ClassAnalyzer();
        $this->factory = new BuilderFactory();
        $this->printer = new Standard();
    }

    public function generateMapperClass(
        string $sourceClass,
        string $targetClass,
        string $mapperClassName,
        string $namespace = 'App\\Generated\\Mapper'
    ): string {
        $this->dependencies = [];
        $this->usedClasses = [];
        $this->namespace = $namespace;

        $this->collectDependencies($sourceClass, $targetClass, $namespace);

        // Регистрируем основные классы
        $this->addUse($sourceClass);
        $this->addUse($targetClass);

        $classBuilder = $this->factory->class($mapperClassName);

        $constructor = $this->generateConstructor();
        if ($constructor) {
            $classBuilder->addStmt($constructor);
        }

        $classBuilder
            ->addStmt($this->generateToDtoMethod($sourceClass, $targetClass))
            ->addStmt($this->generateToEntityMethod($targetClass, $sourceClass));

        $classNode = $classBuilder->getNode();

        $useStatements = $this->buildUseStatements();

        // Оборачиваем класс в неймспейс
        $namespaceNode = new Namespace_(
            name: $this->parseNamespace($namespace), // разбиваем строку на части
            stmts: array_merge($useStatements, [$classNode])
        );

        return $this->printer->prettyPrintFile([$namespaceNode]);
    }

    /**
     * Преобразует строку неймспейса (например, "App\\Generated\\Mapper")
     * в массив для PhpParser\Node\Name
     */
    private function parseNamespace(string $namespace): Name
    {
        return new Name($namespace);
    }

    private function collectDependencies(string $sourceClass, string $targetClass, string $namespace): void
    {
        $this->dependencies = [];

        $propertyMapping = $this->analyzer->getPropertyMapping($sourceClass);
        $getters = $this->analyzer->getGetters($sourceClass);
        $publicProps = $this->analyzer->getPublicProperties($sourceClass);

        // 1. Обрабатываем геттеры
        foreach ($getters as $property => $getter) {
            $mapped = $propertyMapping[$property] ?? ['target' => $property];

            if (isset($mapped['collection_type'])) {
                $itemType = $mapped['collection_type'];
                $itemDtoClass = $this->guessDtoClass($itemType);
                $mapperName = $this->getMapperClassName($itemType, $itemDtoClass);
                $this->dependencies[$mapperName] = $mapperName;
            } elseif ($this->isComplexType($this->analyzer->getGetterReturnType($sourceClass, $getter))) {
                $nestedType = $this->analyzer->getGetterReturnType($sourceClass, $getter);
                $nestedDtoClass = $this->guessDtoClass($nestedType);
                $mapperName = $this->getMapperClassName($nestedType, $nestedDtoClass);
                $this->dependencies[$mapperName] = $mapperName;
            }
        }

        // 2. Обрабатываем публичные свойства
        foreach ($publicProps as $property) {
            $mapped = $propertyMapping[$property] ?? ['target' => $property];

            if (isset($mapped['collection_type'])) {
                $itemType = $mapped['collection_type'];
                $itemDtoClass = $this->guessDtoClass($itemType);
                $mapperName = $this->getMapperClassName($itemType, $itemDtoClass);
                $this->dependencies[$mapperName] = $mapperName;
            } else {
                // Получаем тип публичного свойства
                $propertyType = $this->analyzer->getPublicPropertyType($sourceClass, $property);
                if ($this->isComplexType($propertyType)) {
                    $nestedDtoClass = $this->guessDtoClass($propertyType);
                    $mapperName = $this->getMapperClassName($propertyType, $nestedDtoClass);
                    $this->dependencies[$mapperName] = $mapperName;
                }
            }
        }

        $this->dependencies = array_values($this->dependencies);
    }

    private function isComplexType(?string $type): bool
    {
        if (!$type) return false;
        return !in_array($type, ['int', 'string', 'bool', 'float', 'array']);
    }

    private function guessDtoClass(string $entityClass): string
    {
//        // Заменяем "Entity" на "Dto" и добавляем "Dto" в конец, если нужно
//        if (str_contains($entityClass, 'Entity')) {
//            $dtoClass = str_replace('Entity', 'Dto', $entityClass);
//            // Если имя не заканчивается на Dto — добавим
//            if (!str_ends_with($dtoClass, 'Dto')) {
//                $dtoClass .= 'Dto';
//            }
//            return $dtoClass;
//        }
//
//        // Fallback: просто добавляем Dto в конец
//        return $entityClass . 'Dto';
        $parts = explode('\\', $entityClass);
        $shortName = end($parts);
        // Заменяем последний сегмент неймспейса: Entity → Dto
        $namespace = implode('\\', array_slice($parts, 0, -1));
        $dtoNamespace = str_replace('Entity', 'Dto', $namespace);
        return $dtoNamespace . '\\' . $shortName . 'Dto';
    }

    private function getMapperClassName(string $source, string $target): string
    {
        // Извлекаем последнюю часть FQCN: App\Entity\Address → Address
        $parts = explode('\\', $source);
        $shortName = end($parts);
        return $this->namespace . '\\' . $shortName . 'Mapper';
    }

    private function generateConstructor(): ?Stmt\ClassMethod
    {
        if (empty($this->dependencies)) {
            return null;
        }

        $params = [];
        $stmts = [];

        foreach ($this->dependencies as $mapperClass) {
            // Регистрируем use
            $shortMapperName = $this->addUse($mapperClass);
            // Генерируем короткое имя переменной
            $shortClassName = basename(str_replace('\\', '/', $mapperClass));
            $paramName = lcfirst($shortClassName); // → addressMapper

            $param = ((new Param($paramName))->setType($shortMapperName))->makePrivate()->makeReadonly()->getNode();
            $params[] = $param;
        }

        $constructor = $this->factory->method('__construct')
            ->makePublic()
            ->addParams($params);

        if (!empty($stmts)) {
            $constructor->addStmts($stmts);
        }

        return $constructor->getNode();
    }

    private function generateToDtoMethod(string $entityClass, string $dtoClass): Method
    {
        // Используем короткие имена
        $entityShort = $this->addUse($entityClass);
        $dtoShort = $this->addUse($dtoClass);

        $method = $this->factory->method('toDto')
            ->addParam((new Param('entity'))->setType($entityShort)->getNode())
            ->setReturnType($dtoShort);

        $body = [];

        $dtoConstructorParams = $this->analyzer->getConstructorParams($dtoClass);
        $dtoUsesConstructor = !empty($dtoConstructorParams);
        $invertedMapping = $this->analyzer->invertEntityMapping($entityClass);

        if ($dtoUsesConstructor) {
            $args = [];
            $propertyMapping = $this->analyzer->getPropertyMapping($entityClass, $invertedMapping);
            foreach ($dtoConstructorParams as $paramName => $paramInfo) {
                $expr = $this->buildConstructorArgExpr($paramName, $paramInfo, $entityClass, $dtoClass, $propertyMapping);
                $args[] = $expr;
            }
            $return = new Expr\New_(new Name($dtoShort), $args);
            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('dto'),
                    new Expr\New_(new Name($dtoShort), $args)
                )
            );
        } else {
            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('dto'),
                    new Expr\New_(new Name($dtoShort))
                )
            );
            $return = new Expr\New_(new Name($dtoShort));

            $entityGetters = $this->analyzer->getGetters($entityClass);
            $dtoPublicProps = $this->analyzer->getPublicProperties($dtoClass);
            $propertyMapping = $this->analyzer->getPropertyMapping($dtoClass, $invertedMapping);

            foreach ($entityGetters as $property => $getter) {
                $mapped = $propertyMapping[$property] ?? ['target' => $property];
                $targetProp = $mapped['target'];

                if (!in_array($targetProp, $dtoPublicProps)) continue;

                $sourceExpr = new Expr\MethodCall(new Expr\Variable('entity'), $getter);

                if (isset($mapped['collection_type'])) {
                    $sourceExpr = $this->wrapCollectionMapping(
                        $sourceExpr,
                        $mapped['collection_type'],
                        $mapped['collection_mapper'],
                        $dtoClass,
                        $targetProp
                    );
                }
                $return = $sourceExpr;
                $body[] = new Stmt\Expression(
                    new Expr\Assign(
                        new Expr\PropertyFetch(new Expr\Variable('dto'), $targetProp),
                        $sourceExpr
                    )
                );
            }
        }

        if (count($body) == 1) {
            return $method->addStmts([new Stmt\Return_($return)]);
        }
        $body[] = new Stmt\Return_(new Expr\Variable('dto'));
        return $method->addStmts($body);
    }

    private function buildConstructorArgExpr(
        string $paramName,
        array $paramInfo,
        string $entityClass,
        string $dtoClass,
        array $propertyMapping
    ): Expr {
        $entityGetters = $this->analyzer->getGetters($entityClass);
        $entityPublicProps = $this->analyzer->getPublicProperties($entityClass);

        foreach ($entityGetters as $prop => $getter) {
            $mapped = $propertyMapping[$prop] ?? ['target' => $prop];
            if ($mapped['target'] === $paramName) {
                $expr = new Expr\MethodCall(new Expr\Variable('entity'), $getter);

                if (isset($mapped['collection_type'])) {
                    return $this->wrapCollectionMapping($expr, $mapped['collection_type'], $dtoClass, $paramName);
                }

                // Обработка одиночного вложенного объекта
                $getterReturnType = $this->analyzer->getGetterReturnType($entityClass, $getter);
                if ($this->isComplexType($getterReturnType)) {
                    // Найдём маппер для этого типа
                    $nestedDtoClass = $this->guessDtoClass($getterReturnType);
                    $mapperClass = $this->getMapperClassName($getterReturnType, $nestedDtoClass);
                    $mapperProperty = lcfirst(basename(str_replace('\\', '/', $mapperClass)));
                    return new Expr\MethodCall(
                        new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                        'toDto',
                        [$expr]
                    );
                }

                if (!empty($mapped['custom_mapper'])) {
                    return new Expr\StaticCall(
                        new Name($dtoClass),
                        $mapped['custom_mapper'],
                        [$expr]
                    );
                }

                return $expr;
            }
        }

        foreach ($entityPublicProps as $prop) {
            $mapped = $propertyMapping[$prop] ?? ['target' => $prop];
            if ($mapped['target'] === $paramName) {
                $expr = new Expr\PropertyFetch(new Expr\Variable('entity'), $prop);

                if (isset($mapped['collection_type'])) {
                    return $this->wrapCollectionMapping($expr, $mapped['collection_type'], $dtoClass, $paramName);
                }

                $getterReturnType = $this->analyzer->getPublicPropertyType($entityClass, $prop);
                if ($this->isComplexType($getterReturnType)) {
                    // Найдём маппер для этого типа
                    $nestedDtoClass = $this->guessDtoClass($getterReturnType);
                    $mapperClass = $this->getMapperClassName($getterReturnType, $nestedDtoClass);
                    $mapperProperty = lcfirst(basename(str_replace('\\', '/', $mapperClass)));
                    return new Expr\MethodCall(
                        new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                        'toDto',
                        [$expr]
                    );
                }

                if (!empty($mapped['custom_mapper'])) {
                    return new Expr\StaticCall(
                        new Name($dtoClass),
                        $mapped['custom_mapper'],
                        [$expr]
                    );
                }

                return $expr;
            }
        }

        return new Expr\ConstFetch(new Name('null'));
    }

    private function wrapCollectionMapping(
        Expr $sourceExpr,
        string $collectionType,
        string $dtoClass,
        string $context
    ): Expr
    {
        $itemDtoClass = $this->guessDtoClass($collectionType);

        // Регистрируем use для обоих классов
        $itemShort = $this->addUse($collectionType);
        $itemDtoShort = $this->addUse($itemDtoClass);

        // Получаем короткое имя свойства маппера
        $mapperClass = $this->getMapperClassName($collectionType, $itemDtoClass);
        $mapperProperty = lcfirst(basename(str_replace('\\', '/', $mapperClass)));

//        $stmts = [];
//
//        $nameCollection = lcfirst($collectionType);
//        $collectionVariable = new Expr\Variable($nameCollection);
//        $stmts[] = new Stmt\Expression(
//            new Expr\Assign(
//                $collectionVariable,
//                new Expr\New_(new Name($collectionType))
//            )
//        );
//
//        $value = new Expr\Variable('item');
//        $foreach = (new Stmt\Foreach_($sourceExpr, $value));
//        $foreach->stmts[] = new Expr\MethodCall(
//            $collectionVariable, 'add', [
//                new Arg(
//                    new Expr\MethodCall(
//                        new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
//                        'toDto',
//                        [new Expr\Variable('item')]
//                    )
//                )
//            ]
//        );
//        $stmts[] = $foreach;

        return new Expr\FuncCall(
            new Name('array_map'),
            [
                new Expr\Closure([
                    'params' => [
                        (new Param('item'))->setType($itemShort)->getNode() // ← короткое имя
                    ],
                    'stmts' => [
                        new Stmt\Return_(
                            new Expr\MethodCall(
                                new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                                'toDto',
                                [new Expr\Variable('item')]
                            )
                        )
                    ],
                    'returnType' => new Name($itemDtoShort), // ← короткое имя
                ]),
                $sourceExpr
            ]
        );
    }

    private function generateToEntityMethod(string $dtoClass, string $entityClass): Method
    {
        $dtoShort = $this->addUse($dtoClass);
        $entityShort = $this->addUse($entityClass);

        $method = $this->factory->method('toEntity')
            ->addParam((new Param('dto'))->setType($dtoShort)->getNode())
            ->setReturnType($entityShort);

        $body = [];
        // Получаем инвертированный маппинг из Entity
        $invertedMapping = $this->analyzer->invertEntityMapping($entityClass);

        $entityConstructorParams = $this->analyzer->getConstructorParams($entityClass);
        $entityUsesConstructor = !empty($entityConstructorParams);
        $propertyMapping = $this->analyzer->getPropertyMapping($dtoClass, $invertedMapping);
        if ($entityUsesConstructor) {
            $args = [];

            foreach ($entityConstructorParams as $paramName => $paramInfo) {
                $expr = $this->buildEntityConstructorArg($paramName, $paramInfo, $dtoClass, $entityClass, $propertyMapping);
                $args[] = $expr;
            }

            $return = new Expr\New_(new Name($entityShort), $args);
            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('entity'),
                    $return
                )
            );
        } else {
            $return = new Expr\New_(new Name($entityShort));
            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('entity'),
                    $return
                )
            );

            $dtoPublicProps = $this->analyzer->getPublicProperties($dtoClass);
            $entitySetters = $this->analyzer->getSetters($entityClass);

            foreach ($dtoPublicProps as $property) {
                $mapped = $propertyMapping[$property] ?? ['target' => $property];
                $targetProp = $mapped['target'];

                if (!isset($entitySetters[$targetProp])) continue;

                $sourceExpr = new Expr\PropertyFetch(new Expr\Variable('dto'), $property);

                if (isset($mapped['collection_type'])) {
                    $sourceExpr = $this->wrapCollectionMappingBack($sourceExpr, $mapped['collection_type'], $entityClass, $targetProp);
                }

                $propertyType = $this->analyzer->getPublicPropertyType($dtoClass, $property);
                if ($this->isComplexType($propertyType)) {
                    $nestedEntityClass = str_replace('Dto', 'Entity', $propertyType);
                    if (!class_exists($nestedEntityClass)) {
                        $nestedEntityClass = $propertyType;
                    }
                    $mapperClass = $this->getMapperClassName($nestedEntityClass, $propertyType);
                    $mapperProperty = lcfirst(basename(str_replace('\\', '/', $mapperClass)));
                    $sourceExpr = new Expr\MethodCall(
                        new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                        'toEntity',
                        [new Expr\PropertyFetch(new Expr\Variable('dto'), $property)]
                    );
                }

                $return = $sourceExpr;
                $body[] = new Stmt\Expression(
                    new Expr\MethodCall(
                        new Expr\Variable('entity'),
                        $entitySetters[$targetProp],
                        [$sourceExpr]
                    )
                );
            }
        }

        if (count($body) == 1) {
            return $method->addStmts([new Stmt\Return_($return)]);
        }

        $body[] = new Stmt\Return_(new Expr\Variable('entity'));
        return $method->addStmts($body);
    }

    private function buildEntityConstructorArg(
        string $paramName,
        array $paramInfo,
        string $dtoClass,
        string $entityClass,
        array $propertyMapping,
    ): Expr {
        $dtoPublicProps = $this->analyzer->getPublicProperties($dtoClass);

        foreach ($dtoPublicProps as $prop) {
            $mapped = $propertyMapping[$prop] ?? ['target' => $prop];

            if ($mapped['target'] === $paramName) {
                $expr = new Expr\PropertyFetch(new Expr\Variable('dto'), $prop);

                if (isset($mapped['collection_type'])) {
                    return $this->wrapCollectionMappingBack($expr, $mapped['collection_type'], $entityClass, $paramName);
                }

                // Обработка одиночного вложенного объекта
                $propertyType = $this->analyzer->getPublicPropertyType($dtoClass, $prop);
                if ($this->isComplexType($propertyType)) {
                    $nestedEntityClass = str_replace('Dto', 'Entity', $propertyType);
                    if (!class_exists($nestedEntityClass)) {
                        $nestedEntityClass = $propertyType; // fallback
                    }
                    $mapperClass = $this->getMapperClassName($nestedEntityClass, $propertyType);
                    if (!class_exists($mapperClass)) {
                        $mapperClass = str_replace('Dto', '', $mapperClass);; // fallback
                    }

                    $mapperProperty = lcfirst(basename(str_replace('\\', '/', $mapperClass)));
                    return new Expr\MethodCall(
                        new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                        'toEntity',
                        [$expr]
                    );
                }

                return $expr;
            }
        }

        return new Expr\ConstFetch(new Name('null'));
    }

    private function wrapCollectionMappingBack(Expr $sourceExpr, string $itemType, string $entityClass, string $context): Expr
    {
        $itemEntityClass = str_replace('Dto', 'Entity', $itemType);
        if (!class_exists($itemEntityClass)) {
            $itemEntityClass = $itemType;
        }

        // Регистрируем use
        $itemShort = $this->addUse($itemType);
        $itemEntityShort = $this->addUse($itemEntityClass);

        $itemMapperClass = $this->getMapperClassName($itemEntityClass, $itemType);
        $mapperProperty = lcfirst(basename(str_replace('\\', '/', $itemMapperClass)));

        return new Expr\FuncCall(
            new Name('array_map'),
            [
                new Expr\Closure([
                    'params' => [
                        (new Param('item'))->setType($itemShort)->getNode() // ← короткое имя
                    ],
                    'stmts' => [
                        new Stmt\Return_(
                            new Expr\MethodCall(
                                new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                                'toEntity',
                                [new Expr\Variable('item')]
                            )
                        )
                    ],
                    'returnType' => new Name($itemEntityShort), // ← короткое имя
                ]),
                $sourceExpr
            ]
        );
    }

    private function addUse(string $class): string
    {
        if (!isset($this->usedClasses[$class])) {
            $shortName = $this->getShortName($class);

            // Проверяем, есть ли конфликт
            $conflict = false;
            $alias = null;

            foreach ($this->usedClasses as $existingClass => $existingShort) {
                if ($existingShort === $shortName && $existingClass !== $class) {
                    $conflict = true;
                    // Генерируем алиас: например, "EntityUser"
                    $parts = explode('\\', $class);
                    $alias = $parts[count($parts) - 2] . $shortName;
                    break;
                }
            }

            if ($conflict && $alias) {
                $this->usedClasses[$class] = $alias;
            } else {
                $this->usedClasses[$class] = $shortName;
            }
        }

        return $this->usedClasses[$class];
    }

    private function getShortName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    private function buildUseStatements(): array
    {
        $uses = [];
        foreach ($this->usedClasses as $fullClass => $shortName) {
            $uses[] = new Stmt\Use_([
                new Stmt\UseUse(new Name($fullClass), $shortName)
            ], Stmt\Use_::TYPE_NORMAL);
        }
        return $uses;
    }
}
