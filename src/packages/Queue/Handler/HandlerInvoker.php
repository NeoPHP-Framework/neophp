<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Handler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Queue\Contract\JobInterface;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Exception\NoHandlerException;

class HandlerInvoker
{
    public function __construct(protected HandlerLocator $locator, protected ?ContainerManagerInterface $container = null)
    {
    }

    public function getLocator(): HandlerLocator
    {
        return $this->locator;
    }

    public function invoke(object $message): int
    {
        $handlers = $this->locator->getHandlers($message);

        if ($handlers === [] && $message instanceof JobInterface) {
            if (!method_exists($message, 'handle')) {
                throw new ConfigurationException('The job "{class}" implements {interface} but has no handle() method.', 0, null, ['class' => $message::class, 'interface' => JobInterface::class]);
            }

            $callable = [$message, 'handle'];

            if ($this->container !== null) {
                $this->container->call($callable);
            } else {
                $callable();
            }

            return 1;
        }

        if ($handlers === []) {
            throw new NoHandlerException('No handler for the message "{class}": add a class with #[AsMessageHandler] or implement {interface}.', 0, null, ['class' => $message::class, 'interface' => JobInterface::class]);
        }

        foreach ($handlers as [$class, $method]) {
            $handler = $this->container !== null ? $this->container->get((string) $class) : new $class();

            if (!is_object($handler) || !method_exists($handler, (string) $method)) {
                throw new ConfigurationException('The message handler "{class}::{method}()" does not exist.', 0, null, ['class' => $class, 'method' => $method]);
            }

            $handler->{$method}($message);
        }

        return count($handlers);
    }
}