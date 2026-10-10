<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event;

use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Component\Event\Contract\EventSubscriberInterface;
use NeoPHP\Component\Event\Exception\EventException;

interface EventManagerInterface
{
    /**
     * Calls the listeners of the event class, of its parent classes and of its interfaces, highest priority first, until the propagation is stopped.
     *
     * @param object $event The event
     * @return object The same event, possibly changed by the listeners
     * @throws EventException When a listener is not callable
     * @throws ContainerException When the class of a listener cannot be built by the container
     */
    public function dispatch(object $event): object;

    /**
     * Registers a listener of an event; listeners of the same priority are called in the order they were added.
     *
     * @param string $event Class of the event (or of a parent class or an interface)
     * @param callable|array{0: object|string, 1: string} $listener A callable, or [class, method] built by the container on the first dispatch
     * @param int $priority Priority of the listener, highest first
     * @return static The event manager
     */
    public function addListener(string $event, callable|array $listener, int $priority = 0): static;

    /**
     * Registers the listeners returned by getSubscribedEvents() of a subscriber.
     *
     * @param string|EventSubscriberInterface $subscriber The subscriber, or its class built by the container on the first dispatch
     * @return static The event manager
     * @throws EventException When the class does not implement EventSubscriberInterface or a subscription is invalid
     */
    public function addSubscriber(string|EventSubscriberInterface $subscriber): static;

    /**
     * Returns the listeners of an event, or of every event, sorted by priority.
     *
     * @param string|null $event Class of the event, or null for every event
     * @return array<mixed> The listeners of the event, or, for every event, the list of [listener, priority] by event class
     */
    public function getListeners(?string $event = null): array;

    /**
     * Tells whether listeners are registered for an event class, without its parent classes and interfaces.
     *
     * @param string $event Class of the event
     * @return bool True when the event has listeners
     */
    public function hasListeners(string $event): bool;
}