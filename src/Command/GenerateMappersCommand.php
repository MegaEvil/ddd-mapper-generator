<?php

namespace App\Command;

use App\Attribute\MapsFromEntity;
use App\Config\MapperConfig;
use App\Generator\MapperGenerator;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Helper\ProgressBar;

#[AsCommand(name: 'generate:mappers', description: 'Генерирует мапперы между Entity и DTO')]
class GenerateMappersCommand extends Command
{
    protected string $entityPath = __DIR__ . '/../../src/Entity';
    protected string $entityNamespace = 'App\\Entity';
    protected string $dtoNamespace = 'App\\Dto';
    protected string $dtoPath = __DIR__ . '/../../src/Dto';
    protected string $outputPath = __DIR__ . '/../../generated/Mapper';
    protected string $namespace = 'App\\Generated\\Mapper';
    protected string $configPath = 'config/mappers.yaml';
    protected ?MapperConfig $config = null;

    protected function configure(): void
    {
        $this
            ->addOption('entity-path', null, InputOption::VALUE_OPTIONAL, 'Путь к директории Entity', $this->entityPath)
            ->addOption('dto-path', null, InputOption::VALUE_OPTIONAL, 'Путь к директории DTO', $this->dtoPath)
            ->addOption('entity-namespace', null, InputOption::VALUE_OPTIONAL, 'Путь к директории DTO', $this->entityNamespace)
            ->addOption('dto-namespace', null, InputOption::VALUE_OPTIONAL, 'Путь к директории DTO', $this->dtoNamespace)
            ->addOption('output-path', null, InputOption::VALUE_OPTIONAL, 'Путь для генерации мапперов', $this->outputPath)
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Пространство имён для мапперов', $this->namespace)
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Путь к конфигурации мапперов', $this->configPath)
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Очистить директорию перед генерацией');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Генерация мапперов DDD');

        $configPath = $input->getOption('config');
        $this->config = MapperConfig::fromYamlFile($configPath);

        $this->entityPath = $input->getOption('entity-path');
        $this->dtoPath = $input->getOption('dto-path');
        $this->outputPath = $input->getOption('output-path');
        $this->namespace = $input->getOption('namespace');
        $this->dtoNamespace = $input->getOption('dto-namespace');
        $this->entityNamespace = $input->getOption('entity-namespace');

        if (!is_dir($this->entityPath)) {
            $io->error("Директория Entity не найдена: {$this->entityPath}");
            return Command::FAILURE;
        }

        if (!is_dir($this->dtoPath)) {
            $io->error("Директория DTO не найдена: {$this->dtoPath}");
            return Command::FAILURE;
        }

        if ($input->getOption('clear') && is_dir($this->outputPath)) {
            $io->note("Очистка директории: {$this->outputPath}");
            $this->clearDirectory($this->outputPath);
        }

        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0777, true);
        }

        $pairs = $this->collectMappingPairs();

        if (empty($pairs)) {
            $io->warning('Не найдено ни одной пары Entity → DTO для генерации.');
            return Command::SUCCESS;
        }

        $mapperGenerator = new MapperGenerator();
        $progressBar = new ProgressBar($output, count($pairs));
        $progressBar->start();

        $generatedCount = 0;
        foreach ($pairs as [$entityFqcn, $dtoFqcn]) {
            $mapperName = $this->determineMapperName($entityFqcn, $dtoFqcn);

            try {
                $outputFile = "{$this->outputPath}/{$mapperName}.php";
                if (file_exists($outputFile)) {
                    continue;
                }

                $mapperCode = $mapperGenerator->generateMapperClass($entityFqcn, $dtoFqcn, $mapperName, $this->namespace);

                $res = file_put_contents($outputFile, $mapperCode);
                $io->text($res);
                $generatedCount++;
                $io->text("✅ $mapperName");
            } catch (\Exception $e) {
                $io->error("❌ Ошибка генерации $mapperName: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success("Сгенерировано $generatedCount мапперов в {$this->outputPath}");

        return Command::SUCCESS;
    }

    private function collectMappingPairs(): array
    {
        $pairs = [];

        foreach ($this->config->mappings as $mapping) {
            $pairs[] = [$mapping['entity'], $mapping['dto']];
        }

        $finder = new Finder();
        $finder->files()->in($this->entityPath)->name('*.php');

        foreach ($finder as $file) {
            $entityName = $file->getBasename('.php');
            $entityFqcn = "{$this->entityNamespace}\\$entityName";

            $dtoFinder = new Finder();
            $dtoFinder->files()->in($this->dtoPath)->name("{$entityName}*Dto.php");

            foreach ($dtoFinder as $dtoFile) {
                $dtoName = $dtoFile->getBasename('.php');
                $dtoFqcn = "{$this->dtoNamespace}\\$dtoName";

                if ($this->config->isMapped($entityFqcn, $dtoFqcn)) {
                    continue;
                }

                $reflection = new ReflectionClass($dtoFqcn);
                $attributes = $reflection->getAttributes(MapsFromEntity::class);
                if (!empty($attributes)) {
                    /** @var MapsFromEntity $mapsFromEntity */
                    $mapsFromEntity = $attributes[0]->newInstance();
                    $this->config->addConfig($mapsFromEntity->entityClass, $dtoFqcn, $mapsFromEntity->mapperName);
                }

                $pairs[] = [$entityFqcn, $dtoFqcn];
            }
        }

        return $pairs;
    }

    private function determineMapperName(string $entityFqcn, string $dtoFqcn): string
    {
        $nameFromConfig = $this->config->getMapperName($entityFqcn, $dtoFqcn);
        if ($nameFromConfig) {
            return $nameFromConfig;
        }

        $entityShort = basename(str_replace('\\', '/', $entityFqcn));
        $dtoShort = basename(str_replace('\\', '/', $dtoFqcn));

        if (str_ends_with($dtoShort, 'Dto')) {
            $dtoShort = substr($dtoShort, 0, -3);
        }

        if ($entityShort == $dtoShort) {
            return $entityShort . 'Mapper';
        }

        if (str_starts_with($dtoShort, $entityShort)) {
            $suffix = substr($dtoShort, strlen($entityShort));
            if ($suffix !== '') {
                return $entityShort . 'To' . $suffix . 'Mapper';
            }
        }

        return $entityShort . 'To' . $dtoShort . 'Mapper';
    }

    private function clearDirectory(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
