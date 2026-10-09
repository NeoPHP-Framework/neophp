<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Extractor;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TranslationExtractor
{
    public const EXTENSIONS = ['php', 'twig', 'html'];

    protected const CALL_PATTERN = '/(?<![A-Za-z0-9_$])(?:->\s*)?translate\s*\(/';

    protected const FILTER_PATTERN = '/(?<quote>[\'"])(?<key>(?:\\\\.|(?!\k<quote>).)*)\k<quote>\s*\|\s*trans\b(?<call>\s*\()?/s';

    public function __construct(protected string $defaultDomain = 'messages')
    {
    }

    public function extract(array $paths): array
    {
        $messages = [];

        foreach ($paths as $path) {
            foreach ($this->files((string) $path) as $file) {
                foreach ($this->extractFromString((string) file_get_contents($file), $file) as $domain => $keys) {
                    foreach ($keys as $key => $locations) {
                        $messages[$domain][$key] = [...($messages[$domain][$key] ?? []), ...$locations];
                    }
                }
            }
        }

        ksort($messages);

        foreach ($messages as &$keys) {
            ksort($keys);
        }

        return $messages;
    }

    public function extractFromString(string $code, string $file = ''): array
    {
        $messages = [];

        if (preg_match_all(self::CALL_PATTERN, $code, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as [$match, $offset]) {
                $arguments = self::arguments($code, $offset + strlen($match));
                $key = self::literal($arguments[0] ?? null);

                if ($key === null || $key === '') {
                    continue;
                }

                $domain = self::named($arguments, 'domain') ?? self::literal($arguments[2] ?? null) ?? $this->defaultDomain;
                $messages[$domain][$key][] = $file . ':' . (substr_count($code, "\n", 0, $offset) + 1);
            }
        }

        if (preg_match_all(self::FILTER_PATTERN, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches as $match) {
                $key = stripcslashes($match['key'][0]);

                if ($key === '') {
                    continue;
                }

                $domain = $this->defaultDomain;

                if (isset($match['call']) && $match['call'][1] >= 0) {
                    $arguments = self::arguments($code, $match['call'][1] + strlen($match['call'][0]));
                    $domain = self::named($arguments, 'domain') ?? self::literal($arguments[1] ?? null) ?? $this->defaultDomain;
                }

                $messages[$domain][$key][] = $file . ':' . (substr_count($code, "\n", 0, $match[0][1]) + 1);
            }
        }

        return $messages;
    }

    protected function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    protected static function arguments(string $code, int $offset): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($code);

        for ($i = $offset; $i < $length; $i++) {
            $char = $code[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $code[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    $arguments[] = trim($current);

                    return $arguments;
                }

                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        return [];
    }

    protected static function named(array $arguments, string $name): ?string
    {
        foreach ($arguments as $argument) {
            if (preg_match('/^' . $name . '\s*[:=]\s*(.+)$/s', (string) $argument, $match) === 1) {
                return self::literal(trim($match[1]));
            }
        }

        return null;
    }

    protected static function literal(?string $argument): ?string
    {
        if ($argument === null || preg_match('/^key\s*[:=]\s*(.+)$/s', $argument, $named) === 1 && ($argument = trim($named[1])) === '') {
            return null;
        }

        if (preg_match('/^(\'|")((?:\\\\.|(?!\1).)*)\1$/s', $argument, $match) !== 1) {
            return null;
        }

        return $match[1] === '\'' ? strtr($match[2], ['\\\'' => '\'', '\\\\' => '\\']) : stripcslashes($match[2]);
    }
}