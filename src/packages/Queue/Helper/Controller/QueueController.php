<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Controller;

use NeoPHP\Package\Queue\Contract\MessageBusInterface;
use NeoPHP\Package\Queue\Message\Envelope;

trait QueueController
{
    abstract protected function get(string $id): mixed;

    protected function dispatchMessage(object $message, array $options = []): Envelope
    {
        return $this->get(MessageBusInterface::class)->dispatch($message, $options);
    }
}