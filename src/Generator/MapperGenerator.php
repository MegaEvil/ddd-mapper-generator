<?php

namespace App\Generator;

use PhpParser\Builder\Class_;
use PhpParser\Builder\Method;
use PhpParser\Builder\Param;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Stmt\Namespace_;

class MapperGenerator
{
    private ClassAnalyzer $analyzer;
    private BuilderFactory $factory;
    private Standard $printer;
    private array $dependencies = [];

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
        $this->collectDependencies($sourceClass, $targetClass);

        $classBuilder = $this->factory->class($mapperClassName);

        $constructor = $this->generateConstructor();
        if ($constructor) {
            $classBuilder->addStmt($constructor);
        }

        $classBuilder
            ->addStmt($this->generateToDtoMethod($sourceClass, $targetClass))
            ->addStmt($this->generateToEntityMethod($targetClass, $sourceClass));

        $classNode = $classBuilder->getNode();

        // Оборачиваем класс в неймспейс
        $namespaceNode = new Namespace_(
            name: $this->parseNamespace($namespace), // разбиваем строку на части
            stmts: [$classNode]
        );

        return "<?php\n\n" . $this->printer->prettyPrintFile([$namespaceNode]);
    }

    /**
     * Преобразует строку неймспейса (например, "App\\Generated\\Mapper")
     * в массив для PhpParser\Node\Name
     */
    private function parseNamespace(string $namespace): \PhpParser\Node\Name
    {
        return new \PhpParser\Node\Name($namespace);
    }

    private function collectDependencies(string $sourceClass, string $targetClass): void
    {
        $propertyMapping = $this->analyzer->getPropertyMapping($sourceClass);
        $getters = $this->analyzer->getGetters($sourceClass);

        foreach ($getters as $property => $getter) {
            $mapped = $propertyMapping[$property] ?? ['target' => $property];

            if (isset($mapped['collection_type'])) {
                $itemType = $mapped['collection_type'];
                $itemDtoClass = $this->guessDtoClass($itemType);
                $mapperName = $this->getMapperClassName($itemType, $itemDtoClass);
                $this->dependencies[$property] = $mapperName;
            } elseif ($this->isComplexType($this->getGetterReturnType($sourceClass, $getter))) {
                $nestedType = $this->getGetterReturnType($sourceClass, $getter);
                $nestedDtoClass = $this->guessDtoClass($nestedType);
                $mapperName = $this->getMapperClassName($nestedType, $nestedDtoClass);
                $this->dependencies[$property] = $mapperName;
            }
        }
    }

    private function getGetterReturnType(string $className, string $getter): ?string
    {
        $reflection = new \ReflectionMethod($className, $getter);
        $type = $reflection->getReturnType();
        return $type ? $type->getName() : null;
    }

    private function isComplexType(?string $type): bool
    {
        if (!$type) return false;
        return !in_array($type, ['int', 'string', 'bool', 'float', 'array']);
    }

    private function guessDtoClass(string $entityClass): string
    {
        return str_replace('Entity\\', 'Dto\\', $entityClass);
    }

    private function getMapperClassName(string $source, string $target): string
    {
        return 'App\\Generated\\Mapper\\' . basename(str_replace('\\', '', $source)) . 'Mapper';
    }

    private function generateConstructor(): ?Stmt\ClassMethod
    {
        if (empty($this->dependencies)) {
            return null;
        }

        $params = [];
        $stmts = [];

        foreach ($this->dependencies as $propertyName => $mapperClass) {
            $paramName = lcfirst(basename(str_replace('\\', '', $mapperClass)));
            $params[] = (new Param($paramName))->setType($mapperClass)->getNode();
            $stmts[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\PropertyFetch(new Expr\Variable('this'), $paramName),
                    new Expr\Variable($paramName)
                )
            );
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
        $method = $this->factory->method('toDto')
            ->addParam((new Param('entity'))->setType($entityClass)->getNode())
            ->setReturnType($dtoClass);

        $body = [];

        $dtoConstructorParams = $this->analyzer->getConstructorParams($dtoClass);
        $dtoUsesConstructor = !empty($dtoConstructorParams);

        if ($dtoUsesConstructor) {
            $args = [];
            $propertyMapping = $this->analyzer->getPropertyMapping($entityClass);

            foreach ($dtoConstructorParams as $paramName => $paramInfo) {
                $expr = $this->buildConstructorArgExpr($paramName, $paramInfo, $entityClass, $dtoClass, $propertyMapping);
                $args[] = $expr;
            }

            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('dto'),
                    new Expr\New_(new Name($dtoClass), $args)
                )
            );
        } else {
            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('dto'),
                    new Expr\New_(new Name($dtoClass))
                )
            );

            $entityGetters = $this->analyzer->getGetters($entityClass);
            $dtoPublicProps = $this->analyzer->getPublicProperties($dtoClass);
            $propertyMapping = $this->analyzer->getPropertyMapping($entityClass);

            foreach ($entityGetters as $property => $getter) {
                $mapped = $propertyMapping[$property] ?? ['target' => $property];
                $targetProp = $mapped['target'];

                if (!in_array($targetProp, $dtoPublicProps)) continue;

                $sourceExpr = new Expr\MethodCall(new Expr\Variable('entity'), $getter);

                if (isset($mapped['collection_type'])) {
                    $sourceExpr = $this->wrapCollectionMapping($sourceExpr, $mapped['collection_type'], $dtoClass, $targetProp);
                }

                $body[] = new Stmt\Expression(
                    new Expr\Assign(
                        new Expr\PropertyFetch(new Expr\Variable('dto'), $targetProp),
                        $sourceExpr
                    )
                );
            }
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

        foreach ($entityGetters as $prop => $getter) {
            $mapped = $propertyMapping[$prop] ?? ['target' => $prop];
            if ($mapped['target'] === $paramName) {
                $expr = new Expr\MethodCall(new Expr\Variable('entity'), $getter);

                if (isset($mapped['collection_type'])) {
                    return $this->wrapCollectionMapping($expr, $mapped['collection_type'], $dtoClass, $paramName);
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

    private function wrapCollectionMapping(Expr $sourceExpr, string $itemType, string $dtoClass, string $context): Expr
    {
        $itemDtoClass = $this->guessDtoClass($itemType);
        $mapperClass = $this->getMapperClassName($itemType, $itemDtoClass);
        $mapperProperty = lcfirst(basename(str_replace('\\', '', $mapperClass)));

        return new Expr\FuncCall(
            new Name('array_map'),
            [
                new Expr\Closure([
                    'params' => [(new Param('item'))->setType($itemType)->getNode()],
                    'stmts' => [
                        new Stmt\Return_(
                            new Expr\MethodCall(
                                new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                                'toDto',
                                [new Expr\Variable('item')]
                            )
                        )
                    ],
                    'returnType' => new Name($itemDtoClass),
                ]),
                $sourceExpr
            ]
        );
    }

    private function generateToEntityMethod(string $dtoClass, string $entityClass): Method
    {
        $method = $this->factory->method('toEntity')
            ->addParam((new Param('dto'))->setType($dtoClass)->getNode())
            ->setReturnType($entityClass);

        $body = [];

        $entityConstructorParams = $this->analyzer->getConstructorParams($entityClass);
        $entityUsesConstructor = !empty($entityConstructorParams);

        if ($entityUsesConstructor) {
            $args = [];
            $propertyMapping = $this->analyzer->getPropertyMapping($dtoClass);

            foreach ($entityConstructorParams as $paramName => $paramInfo) {
                $expr = $this->buildEntityConstructorArg($paramName, $paramInfo, $dtoClass, $entityClass, $propertyMapping);
                $args[] = $expr;
            }

            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('entity'),
                    new Expr\New_(new Name($entityClass), $args)
                )
            );
        } else {
            $body[] = new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('entity'),
                    new Expr\New_(new Name($entityClass))
                )
            );

            $dtoPublicProps = $this->analyzer->getPublicProperties($dtoClass);
            $entitySetters = $this->analyzer->getSetters($entityClass);
            $propertyMapping = $this->analyzer->getPropertyMapping($dtoClass);

            foreach ($dtoPublicProps as $property) {
                $mapped = $propertyMapping[$property] ?? ['target' => $property];
                $targetProp = $mapped['target'];

                if (!isset($entitySetters[$targetProp])) continue;

                $sourceExpr = new Expr\PropertyFetch(new Expr\Variable('dto'), $property);

                if (isset($mapped['collection_type'])) {
                    $sourceExpr = $this->wrapCollectionMappingBack($sourceExpr, $mapped['collection_type'], $entityClass, $targetProp);
                }

                $body[] = new Stmt\Expression(
                    new Expr\MethodCall(
                        new Expr\Variable('entity'),
                        $entitySetters[$targetProp],
                        [$sourceExpr]
                    )
                );
            }
        }

        $body[] = new Stmt\Return_(new Expr\Variable('entity'));
        return $method->addStmts($body);
    }

    private function buildEntityConstructorArg(
        string $paramName,
        array $paramInfo,
        string $dtoClass,
        string $entityClass,
        array $propertyMapping
    ): Expr {
        $dtoPublicProps = $this->analyzer->getPublicProperties($dtoClass);

        foreach ($dtoPublicProps as $prop) {
            $mapped = $propertyMapping[$prop] ?? ['target' => $prop];
            if ($mapped['target'] === $paramName) {
                $expr = new Expr\PropertyFetch(new Expr\Variable('dto'), $prop);

                if (isset($mapped['collection_type'])) {
                    return $this->wrapCollectionMappingBack($expr, $mapped['collection_type'], $entityClass, $paramName);
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

        $itemMapperClass = $this->getMapperClassName($itemEntityClass, $itemType);
        $mapperProperty = lcfirst(basename(str_replace('\\', '', $itemMapperClass)));

        return new Expr\FuncCall(
            new Name('array_map'),
            [
                new Expr\Closure([
                    'params' => [(new Param('item'))->setType($itemType)->getNode()],
                    'stmts' => [
                        new Stmt\Return_(
                            new Expr\MethodCall(
                                new Expr\PropertyFetch(new Expr\Variable('this'), $mapperProperty),
                                'toEntity',
                                [new Expr\Variable('item')]
                            )
                        )
                    ],
                    'returnType' => $itemEntityClass,
                ]),
                $sourceExpr
            ]
        );
    }
}
