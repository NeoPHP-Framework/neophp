<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Model;

use DateTimeImmutable;
use GdImage;
use NeoPHP\Package\QrCode\Encoder\Encoder;
use NeoPHP\Package\QrCode\Encoder\ErrorCorrection;
use NeoPHP\Package\QrCode\Encoder\Matrix;
use NeoPHP\Package\QrCode\Exception\QrCodeException;
use Stringable;

class QrCode implements Stringable
{
    public const FORMATS = ['svg', 'png'];

    public const MIME_TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png'];

    public const LOGO_MIME_TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public const DEFAULT_OPTIONS = [
        'size' => 300,
        'margin' => 4,
        'error_correction' => 'medium',
        'foreground' => '#000000',
        'background' => '#FFFFFF',
        'logo' => null,
        'logo_size' => 0.2,
    ];

    protected int $size;

    protected int $margin;

    protected ErrorCorrection $errorCorrection;

    protected string $foreground;

    protected string $background;

    protected ?string $logo = null;

    protected float $logoSize = 0.2;

    protected ?Matrix $matrix = null;

    /**
     * @param array<string, mixed> $options size, margin, error_correction, foreground, background, logo, logo_size
     * @param string|null $code Short code typed by hand when the QR code cannot be scanned
     * @throws QrCodeException
     */
    public function __construct(protected string $content, array $options = [], protected ?string $code = null, protected ?DateTimeImmutable $expiresAt = null)
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULT_OPTIONS), ['code']);

        if ($unknown !== []) {
            throw new QrCodeException('Unknown QR code option(s) "{options}": use {allowed}.', 0, null, ['options' => implode('", "', $unknown), 'allowed' => implode(', ', array_keys(self::DEFAULT_OPTIONS))]);
        }

        $options = array_replace(self::DEFAULT_OPTIONS, array_filter($options, static fn (mixed $value): bool => $value !== null));
        $this->size((int) $options['size'])
            ->margin((int) $options['margin'])
            ->errorCorrection($options['error_correction'])
            ->colors((string) $options['foreground'], (string) $options['background'])
            ->logo($options['logo'] !== null && $options['logo'] !== '' ? (string) $options['logo'] : null, (float) $options['logo_size']);
    }

    /**
     * Width and height of the image in pixels.
     */
    public function size(int $size): static
    {
        if ($size < 21 || $size > 4096) {
            throw new QrCodeException('The size of a QR code must be between 21 and 4096 pixels, {size} given.', 0, null, ['size' => $size]);
        }

        $this->size = $size;

        return $this;
    }

    /**
     * Quiet zone around the code, in modules (4 recommended by the standard).
     */
    public function margin(int $margin): static
    {
        $this->margin = max(0, min(16, $margin));

        return $this;
    }

    public function errorCorrection(ErrorCorrection|string $level): static
    {
        $level = ErrorCorrection::fromName($level);
        $this->assertNotRendered();
        $this->errorCorrection = $this->logo !== null ? ErrorCorrection::High : $level;

        return $this;
    }

    /**
     * Colors in hexadecimal (#000, #111318), the background can be "transparent".
     */
    public function colors(string $foreground, string $background = '#FFFFFF'): static
    {
        $this->foreground = self::color($foreground, false);
        $this->background = self::color($background, true);

        return $this;
    }

    /**
     * Image placed in the middle (SVG, PNG, JPEG, WebP or GIF; SVG only in the SVG rendering): the error correction becomes high.
     *
     * @param float $ratio Width of the logo compared to the code (0.1 to 0.3)
     */
    public function logo(?string $path, float $ratio = 0.2): static
    {
        if ($path !== null && !is_file($path)) {
            throw new QrCodeException('The logo "{path}" of the QR code does not exist.', 0, null, ['path' => $path]);
        }

        $this->logo = $path;
        $this->logoSize = max(0.1, min(0.3, $ratio));

        if ($path !== null) {
            $this->assertNotRendered();
            $this->errorCorrection = ErrorCorrection::High;
        }

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Short code to type when the QR code cannot be scanned (e.g. 7Q2K-4MXA), null when none was registered.
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * Expiration of a signed QR code.
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getErrorCorrection(): ErrorCorrection
    {
        return $this->errorCorrection;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [
            'size' => $this->size,
            'margin' => $this->margin,
            'error_correction' => $this->errorCorrection->value,
            'foreground' => $this->foreground,
            'background' => $this->background,
            'logo' => $this->logo,
            'logo_size' => $this->logoSize,
        ];
    }

    /**
     * @throws QrCodeException When the content is too long
     */
    public function getMatrix(): Matrix
    {
        return $this->matrix ??= Encoder::encode($this->content, $this->errorCorrection);
    }

    public function toSvg(): string
    {
        $matrix = $this->getMatrix();
        $count = $matrix->getSize();
        $total = $count + 2 * $this->margin;
        $path = '';

        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                if (!$matrix->isDark($x, $y)) {
                    continue;
                }

                $start = $x;

                while ($x + 1 < $count && $matrix->isDark($x + 1, $y)) {
                    $x++;
                }

                $length = $x - $start + 1;
                $path .= sprintf('M%d %dh%dv1h-%dz', $start + $this->margin, $y + $this->margin, $length, $length);
            }
        }

        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="QR code">', $this->size, $total);

        if ($this->background !== 'transparent') {
            $svg .= sprintf('<rect width="%1$d" height="%1$d" fill="%2$s"/>', $total, $this->background);
        }

        $svg .= sprintf('<path fill="%s" d="%s"/>', $this->foreground, $path);

        if ($this->logo !== null) {
            $box = $total * $this->logoSize;
            $offset = ($total - $box) / 2;
            $pad = $box * 0.08;
            $svg .= sprintf('<rect x="%1$s" y="%1$s" width="%2$s" height="%2$s" fill="%3$s"/>', self::number($offset - $pad), self::number($box + 2 * $pad), $this->background === 'transparent' ? '#FFFFFF' : $this->background);
            $svg .= sprintf('<image x="%1$s" y="%1$s" width="%2$s" height="%2$s" href="%3$s" preserveAspectRatio="xMidYMid meet"/>', self::number($offset), self::number($box), $this->logoDataUri());
        }

        return $svg . '</svg>';
    }

    /**
     * @throws QrCodeException When GD is not available or the logo is an SVG
     */
    public function toPng(): string
    {
        if (!extension_loaded('gd')) {
            throw new QrCodeException('The PNG QR codes need the PHP extension gd: install it or use the SVG format.');
        }

        $matrix = $this->getMatrix();
        $count = $matrix->getSize();
        $total = $count + 2 * $this->margin;
        $scale = max(1, intdiv($this->size, $total));
        $source = imagecreatetruecolor($total * $scale, $total * $scale);
        imagesavealpha($source, true);
        imagealphablending($source, false);
        $background = $this->allocate($source, $this->background);
        $foreground = $this->allocate($source, $this->foreground);
        imagefilledrectangle($source, 0, 0, $total * $scale - 1, $total * $scale - 1, $background);

        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                if ($matrix->isDark($x, $y)) {
                    imagefilledrectangle($source, ($x + $this->margin) * $scale, ($y + $this->margin) * $scale, ($x + $this->margin + 1) * $scale - 1, ($y + $this->margin + 1) * $scale - 1, $foreground);
                }
            }
        }

        $image = $total * $scale === $this->size ? $source : imagescale($source, $this->size, $this->size, IMG_NEAREST_NEIGHBOUR);

        if (!$image instanceof GdImage) {
            throw new QrCodeException('The PNG QR code could not be resized.');
        }

        imagesavealpha($image, true);
        imagealphablending($image, true);

        if ($this->logo !== null) {
            $this->drawLogo($image);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Image in a data URI, for an <img src> or a CSS background.
     */
    public function toDataUri(string $format = 'svg'): string
    {
        $format = self::format($format);

        return 'data:' . self::MIME_TYPES[$format] . ';base64,' . base64_encode($format === 'png' ? $this->toPng() : $this->toSvg());
    }

    /**
     * @throws QrCodeException When the format is not svg or png
     */
    public function render(string $format = 'svg'): string
    {
        return self::format($format) === 'png' ? $this->toPng() : $this->toSvg();
    }

    /**
     * Text drawing with Unicode half blocks, to scan a code displayed in a console.
     */
    public function toTerminal(): string
    {
        $matrix = $this->getMatrix();
        $count = $matrix->getSize();
        $margin = max(2, min(4, $this->margin));
        $dark = static fn (int $x, int $y): bool => $x >= 0 && $y >= 0 && $x < $count && $y < $count && $matrix->isDark($x, $y);
        $lines = [];

        for ($y = -$margin; $y < $count + $margin; $y += 2) {
            $line = '';

            for ($x = -$margin; $x < $count + $margin; $x++) {
                $top = !$dark($x, $y);
                $bottom = !$dark($x, $y + 1);
                $line .= match (true) {
                    $top && $bottom => '█',
                    $top => '▀',
                    $bottom => '▄',
                    default => ' ',
                };
            }

            $lines[] = $line;
        }

        return implode(PHP_EOL, $lines);
    }

    public function __toString(): string
    {
        return $this->toSvg();
    }

    /**
     * @throws QrCodeException When the format is not svg or png
     */
    public static function format(string $format): string
    {
        $format = strtolower($format);

        if (!in_array($format, self::FORMATS, true)) {
            throw new QrCodeException('The QR code format "{format}" is invalid: use svg or png.', 0, null, ['format' => $format]);
        }

        return $format;
    }

    protected function drawLogo(GdImage $image): void
    {
        $extension = strtolower(pathinfo((string) $this->logo, PATHINFO_EXTENSION));

        if ($extension === 'svg') {
            throw new QrCodeException('An SVG logo can only be drawn in an SVG QR code: give a PNG, JPEG or WebP logo for the PNG format.');
        }

        $logo = @imagecreatefromstring((string) file_get_contents((string) $this->logo));

        if (!$logo instanceof GdImage) {
            throw new QrCodeException('The logo "{path}" of the QR code cannot be read by GD.', 0, null, ['path' => $this->logo]);
        }

        $box = (int) round($this->size * $this->logoSize);
        $pad = (int) round($box * 0.08);
        $offset = intdiv($this->size - $box, 2);
        imagefilledrectangle($image, $offset - $pad, $offset - $pad, $offset + $box + $pad - 1, $offset + $box + $pad - 1, $this->allocate($image, $this->background === 'transparent' ? '#FFFFFF' : $this->background));
        $ratio = min($box / imagesx($logo), $box / imagesy($logo));
        $width = (int) round(imagesx($logo) * $ratio);
        $height = (int) round(imagesy($logo) * $ratio);
        imagecopyresampled($image, $logo, $offset + intdiv($box - $width, 2), $offset + intdiv($box - $height, 2), 0, 0, $width, $height, imagesx($logo), imagesy($logo));
    }

    protected function logoDataUri(): string
    {
        $extension = strtolower(pathinfo((string) $this->logo, PATHINFO_EXTENSION));

        if (!isset(self::LOGO_MIME_TYPES[$extension])) {
            throw new QrCodeException('The logo "{path}" must be an SVG, PNG, JPEG, WebP or GIF image.', 0, null, ['path' => $this->logo]);
        }

        return 'data:' . self::LOGO_MIME_TYPES[$extension] . ';base64,' . base64_encode((string) file_get_contents((string) $this->logo));
    }

    protected function allocate(GdImage $image, string $color): int
    {
        if ($color === 'transparent') {
            return (int) imagecolorallocatealpha($image, 255, 255, 255, 127);
        }

        return (int) imagecolorallocate($image, (int) hexdec(substr($color, 1, 2)), (int) hexdec(substr($color, 3, 2)), (int) hexdec(substr($color, 5, 2)));
    }

    /**
     * @throws QrCodeException When the options change after the code was encoded
     */
    protected function assertNotRendered(): void
    {
        if ($this->matrix !== null) {
            throw new QrCodeException('The error correction and the logo of a QR code cannot change after its first rendering.');
        }
    }

    /**
     * @throws QrCodeException When the color is not #RGB or #RRGGBB
     */
    protected static function color(string $color, bool $allowTransparent): string
    {
        $color = strtolower(trim($color));

        if ($allowTransparent && $color === 'transparent') {
            return $color;
        }

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color) !== 1) {
            throw new QrCodeException('The QR code color "{color}" is invalid: use #RGB or #RRGGBB{transparent}.', 0, null, ['color' => $color, 'transparent' => $allowTransparent ? ' or transparent' : '']);
        }

        if (strlen($color) === 4) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }

        return strtoupper($color);
    }

    protected static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}