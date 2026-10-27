<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Exif;

/**
 * @internal
 */
class OrientationReader
{
    public const MAX_SCAN = 131072;

    public static function fromFile(string $path): int
    {
        if (function_exists('exif_read_data')) {
            $data = @exif_read_data($path, 'IFD0');

            if (is_array($data) && isset($data['Orientation'])) {
                return static::normalize((int) $data['Orientation']);
            }
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 1;
        }

        $bytes = (string) fread($handle, self::MAX_SCAN);
        fclose($handle);

        return static::fromString($bytes);
    }

    public static function fromString(string $bytes): int
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $offset = 2;
        $length = strlen($bytes);

        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return 1;
            }

            $marker = ord($bytes[$offset + 1]);
            $size = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);

            if ($marker === 0xDA || $size < 2) {
                return 1;
            }

            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                return static::fromTiff(substr($bytes, $offset + 10, $size - 8));
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    protected static function fromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $little = substr($tiff, 0, 2) === 'II';
        $short = static fn (int $at): int => strlen($tiff) >= $at + 2 ? (int) unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] : 0;
        $long = static fn (int $at): int => strlen($tiff) >= $at + 4 ? (int) unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1] : 0;
        $ifd = $long(4);
        $entries = $short($ifd);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($short($entry) === 0x0112) {
                return static::normalize($short($entry + 8));
            }
        }

        return 1;
    }

    protected static function normalize(int $orientation): int
    {
        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }
}