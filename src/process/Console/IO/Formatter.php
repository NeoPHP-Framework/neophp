<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

class Formatter
{
    public const STYLES = [
        'info' => '32',
        'success' => '1;32',
        'comment' => '33',
        'warning' => '1;33',
        'error' => '1;31',
        'question' => '36',
        'title' => '1;35',
        'muted' => '2',
        'bold' => '1',
        'underline' => '4',
        'block-success' => '30;42',
        'block-error' => '97;41',
        'block-warning' => '30;43',
        'block-caution' => '97;41',
        'block-info' => '30;46',
    ];

    public function __construct(protected array $styles = self::STYLES)
    {
    }

    public function hasStyle(string $name): bool
    {
        return isset($this->styles[$name]);
    }

    public function setStyle(string $name, string $code): static
    {
        $this->styles[$name] = $code;

        return $this;
    }

    public function format(string $message, bool $decorated): string
    {
        $pattern = '#(\\\\?)<(/?)([a-z][a-z-]*)>#';
        $output = '';
        $stack = [];
        $offset = 0;

        preg_match_all($pattern, $message, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$full, $position] = $match[0];
            $escaped = $match[1][0] === '\\';
            $closing = $match[2][0] === '/';
            $name = $match[3][0];
            $output .= substr($message, $offset, $position - $offset);
            $offset = $position + strlen($full);

            if ($escaped) {
                $output .= substr($full, 1);
                continue;
            }

            if (!isset($this->styles[$name])) {
                $output .= $full;
                continue;
            }

            if ($closing) {
                $index = array_search($name, array_reverse($stack, true), true);

                if ($index === false) {
                    $output .= $full;
                    continue;
                }

                array_splice($stack, (int) $index, 1);

                if ($decorated) {
                    $output .= "\033[0m" . implode('', array_map(fn (string $style): string => "\033[" . $this->styles[$style] . 'm', $stack));
                }

                continue;
            }

            $stack[] = $name;

            if ($decorated) {
                $output .= "\033[" . $this->styles[$name] . 'm';
            }
        }

        $output .= substr($message, $offset);

        if ($decorated && $stack !== []) {
            $output .= "\033[0m";
        }

        return $output;
    }

    public function strip(string $message): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $this->format($message, false));
    }

    public static function escape(string $message): string
    {
        return (string) preg_replace('#(?<!\\\\)<(/?[a-z][a-z-]*)>#', '\\\\<$1>', $message);
    }

    public static function width(string $text): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : (int) preg_match_all('/./us', $text);
    }
}