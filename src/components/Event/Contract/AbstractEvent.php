<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Contract;

abstract class AbstractEvent implements StoppableEventInterface
{
    protected bool $propagationStopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}