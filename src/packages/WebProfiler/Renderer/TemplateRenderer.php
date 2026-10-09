<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Renderer;

use NeoPHP\Package\WebProfiler\Exception\WebProfilerException;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Util\ValueExporter;
use Throwable;

class TemplateRenderer
{
    public const VIEWS = __DIR__ . '/../Resources/views';

    public const ASSETS = __DIR__ . '/../Resources/assets';

    public function __construct(protected BlockRenderer $blocks, protected string $directory = self::VIEWS)
    {
    }

    public function getBlockRenderer(): BlockRenderer
    {
        return $this->blocks;
    }

    public function render(string $template, array $parameters = []): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . $template . '.php';

        if (!is_file($file)) {
            throw new WebProfilerException('The profiler template "{template}" does not exist.', 0, null, ['template' => $file]);
        }

        $level = ob_get_level();
        ob_start();

        try {
            (function (string $__file, array $__parameters): void {
                extract($__parameters, EXTR_SKIP);
                include $__file;
            })->call($this, $file, $parameters);
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    public function asset(string $name): string
    {
        $file = self::ASSETS . DIRECTORY_SEPARATOR . basename($name);

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function e(mixed $value): string
    {
        return $this->blocks->escape($value);
    }

    public function icon(string $icon, int $size = 16): string
    {
        return Icons::svg($icon, $size);
    }

    public function bytes(int|float $bytes): string
    {
        return ValueExporter::formatBytes($bytes);
    }

    public function duration(float $milliseconds): string
    {
        return ValueExporter::formatDuration($milliseconds);
    }

    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function siteUrl(?Profile $profile): string
    {
        if ($profile === null) {
            return '/';
        }

        $url = $profile->getUrl();
        $parts = parse_url($url);

        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || !isset($parts['host'])) {
            return '/';
        }

        $ajax = (bool) ($profile->getData('ajax')['ajax'] ?? false);

        if ($profile->getMethod() === 'GET' && !$ajax && str_contains(strtolower((string) $profile->getContentType()), 'html')) {
            return $url;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
    }
}