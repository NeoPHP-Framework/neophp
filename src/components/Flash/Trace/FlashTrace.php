<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash\Trace;

class FlashTrace
{
    public const MAX_MESSAGES = 200;

    protected array $added = [];

    protected array $read = [];

    public function add(string $type, string $message): void
    {
        if (count($this->added) < self::MAX_MESSAGES) {
            $this->added[] = ['type' => $type, 'message' => $message];
        }
    }

    public function read(array $flashes, string $method): void
    {
        foreach ($flashes as $type => $messages) {
            foreach ((array) $messages as $message) {
                if (count($this->read) < self::MAX_MESSAGES) {
                    $this->read[] = ['type' => (string) $type, 'message' => is_scalar($message) ? (string) $message : get_debug_type($message), 'method' => $method];
                }
            }
        }
    }

    public function getAdded(): array
    {
        return $this->added;
    }

    public function getRead(): array
    {
        return $this->read;
    }

    public function reset(): void
    {
        $this->added = [];
        $this->read = [];
    }
}