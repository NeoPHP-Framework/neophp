<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Encoder;

use NeoPHP\Package\QrCode\Exception\QrCodeException;

enum ErrorCorrection: string
{
    /** About 7 % */
    case Low = 'low';

    /** About 15 % */
    case Medium = 'medium';

    /** About 25 % */
    case Quartile = 'quartile';

    /** About 30 %, required by a logo */
    case High = 'high';

    /**
     * Index in the tables of Tables (L, M, Q, H).
     */
    public function ordinal(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::Quartile => 2,
            self::High => 3,
        };
    }

    /**
     * The two bits of the format information.
     */
    public function formatBits(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 0,
            self::Quartile => 3,
            self::High => 2,
        };
    }

    /**
     * Accepts the name of the level (low, medium, quartile, high) or its letter (L, M, Q, H).
     *
     * @throws QrCodeException When the level is unknown
     */
    public static function fromName(self|string $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        return match (strtolower(trim($level))) {
            'l', 'low' => self::Low,
            'm', 'medium' => self::Medium,
            'q', 'quartile' => self::Quartile,
            'h', 'high' => self::High,
            default => throw new QrCodeException('The error correction "{level}" is invalid: use low, medium, quartile or high.', 0, null, ['level' => $level]),
        };
    }
}