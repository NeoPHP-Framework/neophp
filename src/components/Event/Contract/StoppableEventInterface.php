<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Contract;

interface StoppableEventInterface
{
    public function isPropagationStopped(): bool;
}