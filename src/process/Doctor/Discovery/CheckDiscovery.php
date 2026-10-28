<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use ReflectionClass;

/**
 * @internal
 */
class CheckDiscovery
{
    public const DIRECTORY = 'Helper' . DIRECTORY_SEPARATOR . 'Doctor';

    public const FIRST = [
        'NeoPHP\\Component\\Kernel\\Helper\\Doctor\\PhpCheck',
        'NeoPHP\\Component\\Kernel\\Helper\\Doctor\\ApplicationCheck',
    ];

    protected ClassFinder $finder;

    /**
     * @param list<string> $frameworkSources The components, packages and process directories of the framework
     * @param list<string> $applicationPaths The src/ directory of the project and the directories of the external packages
     */
    public function __construct(protected array $frameworkSources = [], protected array $applicationPaths = [])
    {
        $this->finder = new ClassFinder();
    }

    /**
     * @return list<class-string<CheckInterface>>
     */
    public function discover(): array
    {
        $framework = [];
        $application = [];

        foreach ($this->frameworkSources as $source) {
            foreach (glob(rtrim((string) $source, '/\\') . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . self::DIRECTORY, GLOB_ONLYDIR) ?: [] as $directory) {
                foreach ($this->finder->find($directory) as $class) {
                    if ($this->isCheck($class)) {
                        $framework[] = $class;
                    }
                }
            }
        }

        foreach ($this->applicationPaths as $path) {
            foreach ($this->finder->find((string) $path, 'CheckInterface') as $class) {
                if ($this->isCheck($class) && !in_array($class, $framework, true)) {
                    $application[] = $class;
                }
            }
        }

        $rank = static fn (string $class): int => ($index = array_search($class, self::FIRST, true)) === false ? count(self::FIRST) : $index;
        usort($framework, static fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);
        sort($application);

        return array_values(array_unique([...$framework, ...$application]));
    }

    /**
     * @phpstan-assert-if-true class-string<CheckInterface> $class
     */
    protected function isCheck(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, CheckInterface::class) && (new ReflectionClass($class))->isInstantiable();
    }
}