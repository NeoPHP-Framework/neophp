<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Encoder;

class Matrix
{
    /**
     * @param list<list<bool>> $modules Rows of modules
     */
    public function __construct(
        private array $modules,
        private int $version,
        private ErrorCorrection $errorCorrection,
        private int $mask,
    ) {
    }

    /**
     * Number of modules on each side, without the quiet zone (21 for version 1, 177 for version 40).
     */
    public function getSize(): int
    {
        return count($this->modules);
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x] ?? false;
    }

    /**
     * @return list<list<bool>>
     */
    public function toArray(): array
    {
        return $this->modules;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getErrorCorrection(): ErrorCorrection
    {
        return $this->errorCorrection;
    }

    public function getMask(): int
    {
        return $this->mask;
    }
}