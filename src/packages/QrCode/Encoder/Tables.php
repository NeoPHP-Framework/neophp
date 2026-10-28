<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Encoder;

/**
 * @internal
 */
class Tables
{
    public const MIN_VERSION = 1;

    public const MAX_VERSION = 40;

    /** Error correction codewords in each block. */
    public const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Number of error correction blocks. */
    public const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /**
     * Modules left for the data and the error correction once the function patterns are drawn.
     */
    public static function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $alignments = intdiv($version, 7) + 2;
            $result -= (25 * $alignments - 10) * $alignments - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    /**
     * Codewords available for the data (8 bits each) at this version and error correction.
     */
    public static function dataCodewords(int $version, ErrorCorrection $level): int
    {
        $index = $level->ordinal();

        return intdiv(self::rawDataModules($version), 8) - self::ECC_CODEWORDS_PER_BLOCK[$index][$version] * self::NUM_ERROR_CORRECTION_BLOCKS[$index][$version];
    }

    /**
     * Centers of the alignment patterns on each axis.
     *
     * @return list<int>
     */
    public static function alignmentPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        $count = intdiv($version, 7) + 2;
        $step = $version === 32 ? 26 : intdiv($version * 4 + $count * 2 + 1, $count * 2 - 2) * 2;
        $positions = [];

        for ($i = 0, $position = $version * 4 + 10; $i < $count - 1; $i++, $position -= $step) {
            array_unshift($positions, $position);
        }

        array_unshift($positions, 6);

        return $positions;
    }
}