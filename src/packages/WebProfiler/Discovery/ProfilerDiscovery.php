<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Package\WebProfiler\Attribute\AsProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerElementInterface;
use ReflectionClass;

class ProfilerDiscovery
{
    public const DIRECTORY = 'Helper' . DIRECTORY_SEPARATOR . 'Profiler';

    protected ClassFinder $finder;

    protected array $resources = [];

    public function __construct(protected array $frameworkSources = [], protected array $applicationPaths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $elements = [];

        foreach ($this->frameworkSources as $source) {
            $source = rtrim((string) $source, '/\\');

            if (!is_dir($source)) {
                continue;
            }

            $this->track($source);

            foreach (glob($source . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $feature) {
                $this->track($feature);
                $this->track($feature . DIRECTORY_SEPARATOR . 'Helper');
                $directory = $feature . DIRECTORY_SEPARATOR . self::DIRECTORY;

                if (!is_dir($directory)) {
                    continue;
                }

                foreach ($this->finder->find($directory) as $class) {
                    if ($this->isElement($class)) {
                        $elements[$class] = $this->priority($class);
                    }
                }
            }
        }

        foreach ($this->applicationPaths as $path) {
            foreach ($this->finder->find((string) $path, 'AsProfiler') as $class) {
                if ($this->isElement($class) && (new ReflectionClass($class))->getAttributes(AsProfiler::class) !== []) {
                    $elements[$class] = $this->priority($class);
                }
            }
        }

        return $elements;
    }

    public function getResources(): array
    {
        return [...$this->finder->getResources(), ...$this->resources];
    }

    protected function track(string $path): void
    {
        if (is_dir($path)) {
            $this->resources[$path] = (int) filemtime($path);
        }
    }

    protected function isElement(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, ProfilerElementInterface::class)) {
            return false;
        }

        return (new ReflectionClass($class))->isInstantiable();
    }

    protected function priority(string $class): ?int
    {
        $attributes = (new ReflectionClass($class))->getAttributes(AsProfiler::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->priority;
    }
}