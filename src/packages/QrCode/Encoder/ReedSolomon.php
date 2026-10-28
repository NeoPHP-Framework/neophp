<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Encoder;

/**
 * @internal
 */
class ReedSolomon
{
    /** @var array<int, list<int>> Divisors already computed, by degree */
    private static array $divisors = [];

    /**
     * Returns the error correction codewords of a block.
     *
     * @param list<int> $data Data codewords (0 to 255)
     *
     * @return list<int>
     */
    public static function remainder(array $data, int $degree): array
    {
        $divisor = self::divisor($degree);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /**
     * Generator polynomial of the given degree, highest coefficient first and the leading 1 omitted.
     *
     * @return list<int>
     */
    private static function divisor(int $degree): array
    {
        if (isset(self::$divisors[$degree])) {
            return self::$divisors[$degree];
        }

        $result = array_fill(0, $degree - 1, 0);
        $result[] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02);
        }

        return self::$divisors[$degree] = $result;
    }

    /**
     * Product of two elements of GF(2^8) modulo 0x11D.
     */
    private static function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }
}