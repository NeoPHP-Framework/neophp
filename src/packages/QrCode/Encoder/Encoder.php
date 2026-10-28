<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Encoder;

use NeoPHP\Package\QrCode\Exception\QrCodeException;

final class Encoder
{
    public const MODE_NUMERIC = 'numeric';

    public const MODE_ALPHANUMERIC = 'alphanumeric';

    public const MODE_BYTE = 'byte';

    public const ALPHANUMERIC_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private const MODE_BITS = [self::MODE_NUMERIC => 0x1, self::MODE_ALPHANUMERIC => 0x2, self::MODE_BYTE => 0x4];

    private const COUNT_BITS = [self::MODE_NUMERIC => [10, 12, 14], self::MODE_ALPHANUMERIC => [9, 11, 13], self::MODE_BYTE => [8, 16, 16]];

    private const PENALTY_N1 = 3;

    private const PENALTY_N2 = 3;

    private const PENALTY_N3 = 40;

    private const PENALTY_N4 = 10;

    /** @var list<list<bool>> */
    private array $modules = [];

    /** @var list<list<bool>> */
    private array $function = [];

    private int $size = 0;

    /**
     * @param int|null $mask 0 to 7, null = the mask with the lowest penalty
     *
     * @throws QrCodeException When the text is too long for a version 40 QR code at this error correction
     */
    public static function encode(string $text, ErrorCorrection $errorCorrection = ErrorCorrection::Medium, int $minVersion = Tables::MIN_VERSION, int $maxVersion = Tables::MAX_VERSION, ?int $mask = null, bool $boostErrorCorrection = true): Matrix
    {
        if ($minVersion < Tables::MIN_VERSION || $maxVersion > Tables::MAX_VERSION || $minVersion > $maxVersion || ($mask !== null && ($mask < 0 || $mask > 7))) {
            throw new QrCodeException('Invalid QR code version range {min}-{max} or mask {mask}.', 0, null, ['min' => $minVersion, 'max' => $maxVersion, 'mask' => $mask ?? 'auto']);
        }

        $mode = self::mode($text);
        $characters = strlen($text);

        for ($version = $minVersion; ; $version++) {
            $bits = self::segmentBits($mode, $characters, $version);

            if ($bits <= Tables::dataCodewords($version, $errorCorrection) * 8) {
                break;
            }

            if ($version >= $maxVersion) {
                throw new QrCodeException('The content is too long for a QR code ({length} bytes, error correction {level}): shorten it or lower the error correction.', 0, null, [
                    'length' => strlen($text), 'level' => $errorCorrection->value,
                ]);
            }
        }

        if ($boostErrorCorrection) {
            foreach ([ErrorCorrection::Medium, ErrorCorrection::Quartile, ErrorCorrection::High] as $level) {
                if ($level->ordinal() > $errorCorrection->ordinal() && $bits <= Tables::dataCodewords($version, $level) * 8) {
                    $errorCorrection = $level;
                }
            }
        }

        return (new self())->build(self::dataCodewords($text, $mode, $version, $errorCorrection), $version, $errorCorrection, $mask);
    }

    /**
     * Most compact mode able to hold the whole text.
     */
    public static function mode(string $text): string
    {
        if ($text !== '' && ctype_digit($text)) {
            return self::MODE_NUMERIC;
        }

        if ($text !== '' && strspn($text, self::ALPHANUMERIC_CHARSET) === strlen($text)) {
            return self::MODE_ALPHANUMERIC;
        }

        return self::MODE_BYTE;
    }

    private static function segmentBits(string $mode, int $characters, int $version): int
    {
        $countBits = self::COUNT_BITS[$mode][$version <= 9 ? 0 : ($version <= 26 ? 1 : 2)];

        if ($characters >= 1 << $countBits) {
            return PHP_INT_MAX;
        }

        $data = match ($mode) {
            self::MODE_NUMERIC => intdiv($characters, 3) * 10 + [0, 4, 7][$characters % 3],
            self::MODE_ALPHANUMERIC => intdiv($characters, 2) * 11 + ($characters % 2) * 6,
            default => $characters * 8,
        };

        return 4 + $countBits + $data;
    }

    /**
     * Bit stream of the segment, terminator and padding, as codewords.
     *
     * @return list<int>
     */
    private static function dataCodewords(string $text, string $mode, int $version, ErrorCorrection $errorCorrection): array
    {
        $bits = new BitBuffer();
        $bits->append(self::MODE_BITS[$mode], 4);
        $bits->append(strlen($text), self::COUNT_BITS[$mode][$version <= 9 ? 0 : ($version <= 26 ? 1 : 2)]);

        if ($mode === self::MODE_NUMERIC) {
            foreach (str_split($text, 3) as $chunk) {
                $bits->append((int) $chunk, strlen($chunk) * 3 + 1);
            }
        } elseif ($mode === self::MODE_ALPHANUMERIC) {
            foreach (str_split($text, 2) as $chunk) {
                $value = strpos(self::ALPHANUMERIC_CHARSET, $chunk[0]);
                $bits->append(strlen($chunk) === 2 ? (int) $value * 45 + (int) strpos(self::ALPHANUMERIC_CHARSET, $chunk[1]) : (int) $value, strlen($chunk) === 2 ? 11 : 6);
            }
        } else {
            foreach (str_split($text) as $byte) {
                $bits->append(ord($byte), 8);
            }
        }

        $capacity = Tables::dataCodewords($version, $errorCorrection) * 8;
        $bits->append(0, min(4, $capacity - $bits->length()));
        $bits->append(0, (8 - $bits->length() % 8) % 8);

        for ($pad = 0xEC; $bits->length() < $capacity; $pad ^= 0xEC ^ 0x11) {
            $bits->append($pad, 8);
        }

        return $bits->toBytes();
    }

    /**
     * @param list<int> $data
     */
    private function build(array $data, int $version, ErrorCorrection $errorCorrection, ?int $mask): Matrix
    {
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->function = $this->modules;

        $this->drawFunctionPatterns($version, $errorCorrection);
        $this->drawCodewords($this->interleave($data, $version, $errorCorrection));

        if ($mask === null) {
            $lowest = PHP_INT_MAX;

            for ($candidate = 0; $candidate < 8; $candidate++) {
                $this->applyMask($candidate);
                $this->drawFormatBits($errorCorrection, $candidate);
                $penalty = $this->penalty();

                if ($penalty < $lowest) {
                    $mask = $candidate;
                    $lowest = $penalty;
                }

                $this->applyMask($candidate);
            }
        }

        $this->applyMask((int) $mask);
        $this->drawFormatBits($errorCorrection, (int) $mask);

        return new Matrix($this->modules, $version, $errorCorrection, (int) $mask);
    }

    /**
     * Splits the data in blocks, adds their error correction and interleaves them.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function interleave(array $data, int $version, ErrorCorrection $errorCorrection): array
    {
        $blockCount = Tables::NUM_ERROR_CORRECTION_BLOCKS[$errorCorrection->ordinal()][$version];
        $eccLength = Tables::ECC_CODEWORDS_PER_BLOCK[$errorCorrection->ordinal()][$version];
        $rawCodewords = intdiv(Tables::rawDataModules($version), 8);
        $shortBlocks = $blockCount - $rawCodewords % $blockCount;
        $shortLength = intdiv($rawCodewords, $blockCount);
        $blocks = [];

        for ($i = 0, $offset = 0; $i < $blockCount; $i++) {
            $length = $shortLength - $eccLength + ($i < $shortBlocks ? 0 : 1);
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $ecc = ReedSolomon::remainder($block, $eccLength);

            if ($i < $shortBlocks) {
                $block[] = 0;
            }

            $blocks[] = [...$block, ...$ecc];
        }

        $result = [];

        for ($i = 0; $i <= $shortLength; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLength - $eccLength || $j >= $shortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    private function drawFunctionPatterns(int $version, ErrorCorrection $errorCorrection): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = Tables::alignmentPositions($version);
        $count = count($positions);

        foreach ($positions as $i => $x) {
            foreach ($positions as $j => $y) {
                if (!($i === 0 && $j === 0) && !($i === 0 && $j === $count - 1) && !($i === $count - 1 && $j === 0)) {
                    $this->drawAlignment($x, $y);
                }
            }
        }

        $this->drawFormatBits($errorCorrection, 0);
        $this->drawVersion($version);
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;

                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFormatBits(ErrorCorrection $errorCorrection, int $mask): void
    {
        $data = $errorCorrection->formatBits() << 3 | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = ($data << 10 | $remainder) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, self::bit($bits, $i));
        }

        $this->setFunction(8, 7, self::bit($bits, 6));
        $this->setFunction(8, 8, self::bit($bits, 7));
        $this->setFunction(7, 8, self::bit($bits, 8));

        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, self::bit($bits, $i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, self::bit($bits, $i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, self::bit($bits, $i));
        }

        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(int $version): void
    {
        if ($version < 7) {
            return;
        }

        $remainder = $version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = $version << 12 | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, self::bit($bits, $i));
            $this->setFunction($b, $a, self::bit($bits, $i));
        }
    }

    /**
     * @param list<int> $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $this->size - 1 - $vertical : $vertical;

                    if (!$this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = self::bit($codewords[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    /**
     * XORs the data modules with the mask: applying it twice removes it.
     */
    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->function[$y][$x]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /**
     * Penalty score of the standard: runs of a color, 2x2 blocks, finder-like patterns and dark / light balance.
     */
    private function penalty(): int
    {
        $result = 0;

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $this->size; $a++) {
                $color = false;
                $run = 0;
                $history = [0, 0, 0, 0, 0, 0, 0];

                for ($b = 0; $b < $this->size; $b++) {
                    $module = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];

                    if ($module === $color) {
                        $run++;

                        if ($run === 5) {
                            $result += self::PENALTY_N1;
                        } elseif ($run > 5) {
                            $result++;
                        }
                    } else {
                        $this->addHistory($run, $history);

                        if (!$color) {
                            $result += $this->countFinderPatterns($history) * self::PENALTY_N3;
                        }

                        $color = $module;
                        $run = 1;
                    }
                }

                if ($color) {
                    $this->addHistory($run, $history);
                    $run = 0;
                }

                $this->addHistory($run + $this->size, $history);
                $result += $this->countFinderPatterns($history) * self::PENALTY_N3;
            }
        }

        $dark = 0;

        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $color = $this->modules[$y][$x];
                $dark += $color ? 1 : 0;

                if ($x < $this->size - 1 && $y < $this->size - 1 && $color === $this->modules[$y][$x + 1] && $color === $this->modules[$y + 1][$x] && $color === $this->modules[$y + 1][$x + 1]) {
                    $result += self::PENALTY_N2;
                }
            }
        }

        $total = $this->size * $this->size;
        $result += ((int) ceil(abs($dark * 20 - $total * 10) / $total) - 1) * self::PENALTY_N4;

        return $result;
    }

    /**
     * @param list<int> $history
     */
    private function addHistory(int $run, array &$history): void
    {
        if ($history[0] === 0) {
            $run += $this->size;
        }

        array_pop($history);
        array_unshift($history, $run);
    }

    /**
     * @param list<int> $history
     */
    private function countFinderPatterns(array $history): int
    {
        $n = $history[1];
        $core = $n > 0 && $history[2] === $n && $history[3] === $n * 3 && $history[4] === $n && $history[5] === $n;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0) + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->function[$y][$x] = true;
    }

    private static function bit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) !== 0;
    }
}