<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class Index
{
    public function __construct(public string $name, public array $columns, public bool $unique = false)
    {
    }

    public function getSignature(): string
    {
        return ($this->unique ? 'U:' : 'I:') . strtolower(implode(',', $this->columns));
    }

    public function covers(array $columns): bool
    {
        return array_map('strtolower', array_slice($this->columns, 0, count($columns))) === array_map('strtolower', $columns);
    }
}