<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Discovery;

use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Event\Contract\EventSubscriberInterface;
use NeoPHP\Component\Event\EventManager;
use NeoPHP\Component\Event\Exception\EventException;
use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * @internal
 */
class ListenerDiscovery
{
    protected ClassFinder $finder;

    public function __construct(protected array $paths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $listeners = [];

        foreach ($this->paths as $path) {
            foreach ($this->finder->find((string) $path, ['AsListener', 'EventSubscriberInterface']) as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if (!$reflection->isInstantiable()) {
                    continue;
                }

                foreach ($this->listenersOf($reflection) as [$event, $method, $priority]) {
                    $listeners[$event][] = [$class, $method, $priority];
                }
            }
        }

        return $listeners;
    }

    public function getResources(): array
    {
        return $this->finder->getResources();
    }

    protected function listenersOf(ReflectionClass $class): array
    {
        $listeners = [];

        foreach ($class->getAttributes(AsListener::class) as $attribute) {
            $listener = $attribute->newInstance();
            $method = $listener->method ?? '__invoke';

            if (!$class->hasMethod($method)) {
                throw new EventException('The listener "{class}" must define the method "{method}()".', 0, null, ['class' => $class->getName(), 'method' => $method]);
            }

            $listeners[] = [$listener->event ?? $this->eventOf($class->getMethod($method)), $method, $listener->priority];
        }

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(AsListener::class) as $attribute) {
                $listener = $attribute->newInstance();
                $listeners[] = [$listener->event ?? $this->eventOf($method), $method->getName(), $listener->priority];
            }
        }

        if ($class->implementsInterface(EventSubscriberInterface::class)) {
            array_push($listeners, ...EventManager::subscriptions($class->getName()));
        }

        return $listeners;
    }

    protected function eventOf(ReflectionMethod $method): string
    {
        $parameter = $method->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new EventException('Unable to guess the event of "{listener}": type its first parameter with the event class or use #[AsListener(event: ...)].', 0, null, [
                'listener' => $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()',
            ]);
        }

        return $type->getName();
    }
}