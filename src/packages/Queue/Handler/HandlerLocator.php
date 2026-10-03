<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Handler;

class HandlerLocator
{
    protected array $handlers = [];

    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $message => $list) {
            foreach ((array) $list as $handler) {
                $handler = (array) $handler;
                $this->add((string) $message, (string) ($handler[0] ?? $handler['handler'] ?? ''), (string) ($handler[1] ?? $handler['method'] ?? '__invoke'), (int) ($handler[2] ?? $handler['priority'] ?? 0));
            }
        }
    }

    public function add(string $message, string $class, string $method = '__invoke', int $priority = 0): static
    {
        if ($class === '') {
            return $this;
        }

        $message = ltrim($message, '\\');

        foreach ($this->handlers[$message] ?? [] as $handler) {
            if ($handler[0] === $class && $handler[1] === $method) {
                return $this;
            }
        }

        $this->handlers[$message][] = [ltrim($class, '\\'), $method, $priority];
        usort($this->handlers[$message], static fn (array $a, array $b): int => $b[2] <=> $a[2]);

        return $this;
    }

    public function getHandlers(object|string $message): array
    {
        $class = is_object($message) ? $message::class : ltrim($message, '\\');
        $types = [$class, ...array_values(class_exists($class) ? (array) class_parents($class) : []), ...array_values(class_exists($class) ? (array) class_implements($class) : [])];
        $handlers = [];

        foreach ($types as $type) {
            foreach ($this->handlers[(string) $type] ?? [] as $handler) {
                $handlers[] = [$handler[0], $handler[1]];
            }
        }

        return $handlers;
    }

    public function all(): array
    {
        return $this->handlers;
    }
}