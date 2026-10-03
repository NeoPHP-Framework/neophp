<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Trace;

class QueueTrace
{
    public const MAX_MESSAGES = 200;

    protected array $messages = [];

    protected int $dropped = 0;

    public function record(array $message): void
    {
        if (count($this->messages) >= self::MAX_MESSAGES) {
            $this->dropped++;

            return;
        }

        $this->messages[] = $message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getDropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->messages = [];
        $this->dropped = 0;
    }
}