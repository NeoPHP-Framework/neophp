<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class ForeignKey
{
    public function __construct(
        public string $name,
        public array $columns,
        public string $foreignTable,
        public array $foreignColumns,
        public ?string $onDelete = null,
    ) {
        $this->onDelete = self::normalizeAction($onDelete);
    }

    public static function normalizeAction(?string $action): ?string
    {
        $action = $action === null ? null : strtoupper(trim($action));

        return in_array($action, [null, '', 'NO ACTION', 'RESTRICT'], true) ? null : $action;
    }

    public function getSignature(): string
    {
        return strtolower(implode(',', $this->columns) . '>' . $this->foreignTable . '(' . implode(',', $this->foreignColumns) . ')') . ':' . ($this->onDelete ?? '');
    }
}