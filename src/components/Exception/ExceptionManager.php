<?php

declare(strict_types=1);

namespace NeoPHP\Component\Exception;

use Closure;
use NeoPHP\Component\Exception\Contract\ExceptionInterface;
use Throwable;

class ExceptionManager
{
    public const PHRASES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    protected ?Closure $dumper = null;

    public function __construct(protected bool $debug = false)
    {
    }

    public function setDumper(?Closure $dumper): static
    {
        $this->dumper = $dumper;

        return $this;
    }

    public function getDumper(): ?Closure
    {
        return $this->dumper;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;

        return $this;
    }

    public function getStatusCode(Throwable $exception): int
    {
        return $exception instanceof ExceptionInterface ? $exception->getStatusCode() : 500;
    }

    public function getHeaders(Throwable $exception): array
    {
        return $exception instanceof ExceptionInterface ? $exception->getHeaders() : [];
    }

    public function render(Throwable $exception): string
    {
        $status = $this->getStatusCode($exception);

        if ($status >= 500) {
            error_log(sprintf('[NeoPHP] %s: %s in %s:%d', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        return $this->debug ? $this->renderDebug($exception, $status) : $this->renderSimple($exception, $status);
    }

    public function renderJson(Throwable $exception): array
    {
        $status = $this->getStatusCode($exception);
        $error = [
            'status' => $status,
            'message' => $this->debug || $status < 500 ? $exception->getMessage() : (self::PHRASES[$status] ?? 'Error'),
        ];

        if ($this->debug) {
            $error['exception'] = [
                'class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_map(
                    static fn (array $frame): string => $frame['call'] . ' ' . ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? '?'),
                    $this->framesOf($exception),
                ),
            ];
        }

        return ['error' => $error];
    }

    protected function renderSimple(Throwable $exception, int $status): string
    {
        $title = $this->escape($status . ' ' . (self::PHRASES[$status] ?? 'Error'));
        $message = $status < 500 ? $this->escape($exception->getMessage()) : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
            body{font-family:system-ui,sans-serif;background:#f6f7f9;color:#1d2433;display:grid;place-items:center;min-height:100vh;margin:0}
            main{text-align:center;padding:2rem}h1{font-size:2.5rem;margin:0 0 .5rem}p{color:#5b6475}
            </style>
            </head>
            <body><main><h1>{$title}</h1><p>{$message}</p></main></body>
            </html>
            HTML;
    }

    protected function renderDebug(Throwable $exception, int $status): string
    {
        $blocks = '';
        $current = $exception;

        while ($current !== null) {
            $frames = '';
            $trace = $current->getTrace();

            foreach ($this->framesOf($current) as $frame) {
                $location = $frame['file'] !== null ? $frame['file'] . ':' . ($frame['line'] ?? '?') : '[internal]';
                $arguments = $trace[$frame['index']]['args'] ?? [];
                $dump = $this->dumper !== null && $arguments !== [] ? '<details><summary>arguments (' . count($arguments) . ')</summary>' . ($this->dumper)($arguments) . '</details>' : '';
                $frames .= sprintf('<li><code>#%d %s</code><span>%s</span>%s</li>', $frame['index'], $this->escape($frame['call']), $this->escape($location), $dump);
            }

            $context = $current instanceof ExceptionInterface ? $current->getContext() : [];

            $blocks .= sprintf(
                '<section><p class="class">%s</p><h2>%s</h2><p class="where">%s:%d</p>%s%s<ol>%s</ol></section>',
                $this->escape($current::class),
                $this->escape($current->getMessage()),
                $this->escape($current->getFile()),
                $current->getLine(),
                $this->excerpt($current->getFile(), $current->getLine()),
                $this->dumper !== null && $context !== [] ? '<h3>Context</h3>' . ($this->dumper)($context) : '',
                $frames,
            );

            $current = $current->getPrevious();
        }

        $title = $this->escape($status . ' ' . (self::PHRASES[$status] ?? 'Error'));

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title} | NeoPHP</title>
            <style>
            body{font-family:system-ui,sans-serif;margin:0;background:#f6f7f9;color:#1d2433}
            header{background:#3b1f6e;color:#fff;padding:1rem 1.5rem;font-weight:600}
            section{background:#fff;margin:1.5rem;padding:1.5rem;border-radius:8px;box-shadow:0 1px 3px #0002;overflow-x:auto}
            .class{color:#7a3fd1;font-family:monospace;margin:0}.where{color:#5b6475;font-family:monospace;word-break:break-all}
            h2{margin:.3rem 0;font-size:1.3rem}ol{padding-left:0;list-style:none;font-size:.85rem}
            li{padding:.4rem 0;border-top:1px solid #eef0f3;display:flex;flex-direction:column;gap:.2rem}
            li span{color:#5b6475;font-family:monospace;word-break:break-all}
            pre{background:#1d2433;color:#e6e9ef;padding:1rem;border-radius:6px;overflow-x:auto;font-size:.8rem}
            pre b{background:#7a3fd155;display:block}
            h3{font-size:.95rem;margin:1rem 0 .3rem}details summary{cursor:pointer;color:#7a3fd1;font-size:.8rem}
            </style>
            </head>
            <body><header>NeoPHP &middot; {$title}</header>{$blocks}</body>
            </html>
            HTML;
    }

    protected function framesOf(Throwable $exception): array
    {
        if ($exception instanceof ExceptionInterface) {
            return $exception->getStackTrace();
        }

        $frames = [];

        foreach ($exception->getTrace() as $index => $frame) {
            $frames[] = [
                'index' => $index,
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()',
            ];
        }

        return $frames;
    }

    protected function excerpt(string $file, int $line): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $html = '';

        foreach (array_slice($lines, max(0, $line - 6), 11, true) as $index => $content) {
            $row = sprintf('%4d  %s', $index + 1, $this->escape($content));
            $html .= $index + 1 === $line ? '<b>' . $row . '</b>' : $row . "\n";
        }

        return '<pre>' . $html . '</pre>';
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}