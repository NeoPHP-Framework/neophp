<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Component\Middleware\Attribute\AsMiddleware;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Exception\MiddlewareException;
use ReflectionClass;

/**
 * @internal
 */
class MiddlewareDiscovery
{
    protected ClassFinder $finder;

    public function __construct(protected array $paths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $aliases = [];
        $global = [];

        foreach ($this->paths as $path) {
            foreach ($this->finder->find((string) $path, 'AsMiddleware') as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                $attributes = $reflection->getAttributes(AsMiddleware::class);

                if ($attributes === [] || !$reflection->isInstantiable()) {
                    continue;
                }

                if (!$reflection->implementsInterface(MiddlewareInterface::class)) {
                    throw new MiddlewareException('The middleware "{middleware}" must implement {interface}.', 0, null, [
                        'middleware' => $class,
                        'interface' => MiddlewareInterface::class,
                    ]);
                }

                $attribute = $attributes[0]->newInstance();

                if ($attribute->name !== null && $attribute->name !== '') {
                    $aliases[$attribute->name] = $class;
                }

                if ($attribute->global) {
                    $global[] = ['class' => $class, 'priority' => $attribute->priority];
                }
            }
        }

        usort($global, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return [
            'aliases' => $aliases,
            'global' => array_column($global, 'class'),
        ];
    }

    public function getResources(): array
    {
        return $this->finder->getResources();
    }
}