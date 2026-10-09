<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event;

use Closure;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Contract\EventSubscriberInterface;
use NeoPHP\Component\Event\Contract\StoppableEventInterface;
use NeoPHP\Component\Event\Exception\EventException;
use NeoPHP\Component\Event\Provider\EventProvider;
use NeoPHP\Component\Kernel\Attribute\Component;

#[Component(provider: EventProvider::class)]
final class EventManager implements EventManagerInterface
{
    protected ?ContainerManagerInterface $container = null;

    protected array $listeners = [];

    protected array $sorted = [];

    protected int $order = 0;

    public function __construct(?ContainerManagerInterface $container = null, array $listeners = [])
    {
        $this->container = $container;

        foreach ($listeners as $event => $entries) {
            foreach ((array) $entries as $entry) {
                $this->addListener((string) $event, [(string) $entry[0], (string) $entry[1]], (int) ($entry[2] ?? 0));
            }
        }
    }

    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;

        foreach ($this->listenersFor($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }

            ($this->callable($listener))($event);
        }

        return $event;
    }

    public function addListener(string $event, callable|array $listener, int $priority = 0): static
    {
        $this->listeners[$event][] = ['listener' => $listener, 'priority' => $priority, 'order' => $this->order++];
        $this->sorted = [];

        return $this;
    }

    public function addSubscriber(string|EventSubscriberInterface $subscriber): static
    {
        $class = is_string($subscriber) ? $subscriber : $subscriber::class;

        if (!is_subclass_of($class, EventSubscriberInterface::class)) {
            throw new EventException('The subscriber "{subscriber}" must implement {interface}.', 0, null, [
                'subscriber' => $class,
                'interface' => EventSubscriberInterface::class,
            ]);
        }

        foreach (static::subscriptions($class) as [$event, $method, $priority]) {
            $this->addListener($event, [is_string($subscriber) ? $class : $subscriber, $method], $priority);
        }

        return $this;
    }

    public function getListeners(?string $event = null): array
    {
        if ($event !== null) {
            return array_column($this->sortedFor($event), 'listener');
        }

        $all = [];

        foreach (array_keys($this->listeners) as $name) {
            $all[$name] = array_map(static fn (array $entry): array => [$entry['listener'], $entry['priority']], $this->sortedFor($name));
        }

        ksort($all);

        return $all;
    }

    public function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    public static function subscriptions(string $class): array
    {
        $subscriptions = [];

        foreach ($class::getSubscribedEvents() as $event => $definition) {
            if (is_string($definition)) {
                $subscriptions[] = [(string) $event, $definition, 0];
                continue;
            }

            $definitions = is_array($definition) && isset($definition[0]) && is_array($definition[0]) ? $definition : [$definition];

            foreach ($definitions as $item) {
                $item = (array) $item;

                if (!isset($item[0]) || !is_string($item[0])) {
                    throw new EventException('Invalid subscription for "{event}" in "{subscriber}": use "method", ["method", priority] or [["method", priority], ...].', 0, null, [
                        'event' => (string) $event,
                        'subscriber' => $class,
                    ]);
                }

                $subscriptions[] = [(string) $event, $item[0], (int) ($item[1] ?? 0)];
            }
        }

        return $subscriptions;
    }

    public static function describe(mixed $listener): string
    {
        if (is_array($listener) && count($listener) === 2) {
            return (is_object($listener[0]) ? $listener[0]::class : (string) $listener[0]) . '::' . (string) $listener[1] . '()';
        }

        if ($listener instanceof Closure) {
            return 'Closure';
        }

        if (is_object($listener)) {
            return $listener::class . '::__invoke()';
        }

        return is_string($listener) ? $listener : get_debug_type($listener);
    }

    protected function listenersFor(object $event): array
    {
        $types = [$event::class, ...array_values(class_parents($event) ?: []), ...array_values(class_implements($event) ?: [])];
        $entries = [];

        foreach ($types as $type) {
            array_push($entries, ...($this->listeners[$type] ?? []));
        }

        usort($entries, static fn (array $a, array $b): int => [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']]);

        return array_column($entries, 'listener');
    }

    protected function sortedFor(string $event): array
    {
        if (!isset($this->sorted[$event])) {
            $entries = $this->listeners[$event] ?? [];
            usort($entries, static fn (array $a, array $b): int => [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']]);
            $this->sorted[$event] = $entries;
        }

        return $this->sorted[$event];
    }

    protected function callable(callable|array $listener): callable
    {
        if (is_array($listener) && count($listener) === 2 && is_string($listener[0])) {
            $instance = $this->container !== null ? $this->container->get($listener[0]) : new $listener[0]();
            $listener = [$instance, (string) $listener[1]];
        }

        if (!is_callable($listener)) {
            throw new EventException('The listener "{listener}" is not callable.', 0, null, ['listener' => static::describe($listener)]);
        }

        return $listener instanceof Closure ? $listener : Closure::fromCallable($listener);
    }
}