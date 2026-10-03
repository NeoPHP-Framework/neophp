<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Contract;

use NeoPHP\Package\Queue\Envelope;

interface MessageBusInterface
{
    public function dispatch(object $message, array $options = []): Envelope;
}