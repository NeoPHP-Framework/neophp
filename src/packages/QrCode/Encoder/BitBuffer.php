<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Encoder;

/**
 * @internal
 */
class BitBuffer
{
    /** @var list<int> */
    private array $bits = [];

    public function append(int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $this->bits[] = ($value >> $i) & 1;
        }
    }

    public function length(): int
    {
        return count($this->bits);
    }

    /**
     * @return list<int>
     */
    public function toBytes(): array
    {
        $bytes = [];

        foreach (array_chunk($this->bits, 8) as $chunk) {
            $bytes[] = (int) bindec(implode('', array_pad($chunk, 8, 0)));
        }

        return $bytes;
    }
}