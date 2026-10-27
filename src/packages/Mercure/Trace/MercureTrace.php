<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Trace;

/**
 * @internal
 */
class MercureTrace
{
    public const MAX_ENTRIES = 200;

    public const DATA_PREVIEW = 500;

    protected array $updates = [];

    protected array $authorizations = [];

    protected int $dropped = 0;

    public function recordUpdate(array $update): void
    {
        if (count($this->updates) >= self::MAX_ENTRIES) {
            $this->dropped++;

            return;
        }

        $this->updates[] = $update;
    }

    public function recordAuthorization(array $authorization): void
    {
        if (count($this->authorizations) >= self::MAX_ENTRIES) {
            $this->dropped++;

            return;
        }

        $this->authorizations[] = $authorization;
    }

    public function getUpdates(): array
    {
        return $this->updates;
    }

    public function getAuthorizations(): array
    {
        return $this->authorizations;
    }

    public function getDropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->updates = [];
        $this->authorizations = [];
        $this->dropped = 0;
    }
}