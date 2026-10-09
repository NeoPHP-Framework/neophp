<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Compiler;

class JsCompiler implements CompilerInterface
{
    public const REGEX_PREFIXES = '(,=:[!&|?{};+-*%<>~^';

    public const REGEX_KEYWORDS = ['return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'new', 'delete', 'void', 'throw', 'yield', 'await'];

    public function supports(string $extension): bool
    {
        return in_array($extension, ['js', 'mjs'], true);
    }

    public function compile(string $content, string $path, callable $resolve, bool $minify = false): string
    {
        return $minify ? $this->minify($content) : $content;
    }

    protected function minify(string $content): string
    {
        $output = '';
        $pending = '';
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            $next = $content[$i + 1] ?? '';

            if ($char === '/' && $next === '/') {
                $end = strpos($content, "\n", $i);
                $i = $end === false ? $length : $end - 1;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($content, '*/', $i + 2);
                $comment = substr($content, $i, $end === false ? null : $end + 2 - $i);
                $pending = str_contains($comment, "\n") ? "\n" : ($pending === '' ? ' ' : $pending);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if (ctype_space($char)) {
                if ($char === "\n" || $char === "\r") {
                    $pending = "\n";
                } elseif ($pending === '') {
                    $pending = ' ';
                }

                continue;
            }

            $regex = $char === '/' && $this->startsRegex($output);

            if ($pending !== '' && $output !== '') {
                $output .= $pending;
            }

            $pending = '';

            if ($char === '"' || $char === "'" || $char === '`') {
                $end = $this->skipString($content, $i, $char);
                $output .= substr($content, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            if ($regex) {
                $end = $this->skipRegex($content, $i);
                $output .= substr($content, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            $output .= $char;
        }

        return $output . "\n";
    }

    protected function startsRegex(string $output): bool
    {
        $trimmed = rtrim($output);

        if ($trimmed === '') {
            return true;
        }

        if (str_contains(static::REGEX_PREFIXES, substr($trimmed, -1))) {
            return true;
        }

        return preg_match('/(?:^|[^\w$])(' . implode('|', static::REGEX_KEYWORDS) . ')$/', $trimmed) === 1;
    }

    protected function skipString(string $content, int $start, string $quote): int
    {
        $length = strlen($content);
        $i = $start + 1;

        while ($i < $length && $content[$i] !== $quote) {
            $i += $content[$i] === '\\' ? 2 : 1;
        }

        return min($i, $length - 1);
    }

    protected function skipRegex(string $content, int $start): int
    {
        $length = strlen($content);
        $class = false;
        $i = $start + 1;

        while ($i < $length) {
            $char = $content[$i];

            if ($char === '\\') {
                $i += 2;
                continue;
            }

            if ($char === "\n") {
                return $i - 1;
            }

            if ($char === '[') {
                $class = true;
            } elseif ($char === ']') {
                $class = false;
            } elseif ($char === '/' && !$class) {
                break;
            }

            $i++;
        }

        while ($i + 1 < $length && ctype_alpha($content[$i + 1])) {
            $i++;
        }

        return min($i, $length - 1);
    }
}