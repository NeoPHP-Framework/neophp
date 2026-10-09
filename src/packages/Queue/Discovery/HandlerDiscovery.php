<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Package\Queue\Attribute\AsMessageHandler;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @internal
 */
class HandlerDiscovery
{
    protected ClassFinder $finder;

    public function __construct(protected array $paths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $handlers = [];

        foreach ($this->paths as $path) {
            foreach ($this->finder->find((string) $path, 'AsMessageHandler') as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if (!$reflection->isInstantiable()) {
                    continue;
                }

                foreach ($reflection->getAttributes(AsMessageHandler::class) as $attribute) {
                    $handler = $attribute->newInstance();
                    $method = $handler->method ?? '__invoke';
                    $handlers[$this->messageClass($reflection, $method, $handler)][] = [$class, $method, $handler->priority];
                }

                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    foreach ($method->getAttributes(AsMessageHandler::class) as $attribute) {
                        $handler = $attribute->newInstance();
                        $handlers[$this->messageClass($reflection, $method->getName(), $handler)][] = [$class, $method->getName(), $handler->priority];
                    }
                }
            }
        }

        return $handlers;
    }

    public function getResources(): array
    {
        return $this->finder->getResources();
    }

    protected function messageClass(ReflectionClass $class, string $method, AsMessageHandler $handler): string
    {
        if ($handler->handles !== null && $handler->handles !== '') {
            return ltrim($handler->handles, '\\');
        }

        if (!$class->hasMethod($method)) {
            throw new ConfigurationException('The message handler "{class}" has no public method "{method}".', 0, null, ['class' => $class->getName(), 'method' => $method]);
        }

        $parameters = $class->getMethod($method)->getParameters();
        $type = $parameters === [] ? null : $parameters[0]->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new ConfigurationException('The first argument of "{class}::{method}()" must be typed with the message class, or set #[AsMessageHandler(handles: ...)].', 0, null, ['class' => $class->getName(), 'method' => $method]);
        }

        return $type->getName();
    }
}