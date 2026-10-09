<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Component\Serializer\Attribute\AsNormalizer;
use ReflectionClass;

/**
 * @internal
 */
class NormalizerDiscovery
{
    protected ClassFinder $finder;

    public function __construct(protected array $paths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $normalizers = [];

        foreach ($this->paths as $path) {
            foreach ($this->finder->find((string) $path, 'AsNormalizer') as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                $attributes = $reflection->getAttributes(AsNormalizer::class);

                if ($attributes === [] || !$reflection->isInstantiable()) {
                    continue;
                }

                $normalizers[$class] = $attributes[0]->newInstance()->priority;
            }
        }

        return $normalizers;
    }

    public function getResources(): array
    {
        return $this->finder->getResources();
    }
}