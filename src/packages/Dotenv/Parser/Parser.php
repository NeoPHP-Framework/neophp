<?php

declare(strict_types=1);

namespace NeoPHP\Package\Dotenv\Parser;

use NeoPHP\Package\Dotenv\Exception\DotenvException;

class Parser
{
    public function parse(string $content, ?string $path = null, array $known = []): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        $count = count($lines);
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/s', $line, $m) !== 1) {
                throw DotenvException::syntax('Invalid line, expected "NAME=value"', $i + 1, $path);
            }

            $name = $m[1];
            $raw = $m[2];
            $startLine = $i;

            if ($raw !== '' && $raw[0] === '"') {
                while (!$this->hasClosingDoubleQuote($raw)) {
                    if (++$i >= $count) {
                        throw DotenvException::syntax(sprintf('Missing closing quote for "%s"', $name), $startLine + 1, $path);
                    }

                    $raw .= "\n" . $lines[$i];
                }

                $end = $this->closingDoubleQuotePosition($raw);
                $this->assertOnlyComment(substr($raw, $end + 1), $name, $i + 1, $path);
                $value = $this->unescape(substr($raw, 1, $end - 1));
                $value = $this->interpolate($value, $values + $known);
            } elseif ($raw !== '' && $raw[0] === "'") {
                $end = strpos($raw, "'", 1);

                if ($end === false) {
                    throw DotenvException::syntax(sprintf('Missing closing quote for "%s"', $name), $i + 1, $path);
                }

                $this->assertOnlyComment(substr($raw, $end + 1), $name, $i + 1, $path);
                $value = substr($raw, 1, $end - 1);
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));
                $value = $this->interpolate($value, $values + $known);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    private function hasClosingDoubleQuote(string $raw): bool
    {
        return $this->closingDoubleQuotePosition($raw) !== null;
    }

    private function closingDoubleQuotePosition(string $raw): ?int
    {
        $length = strlen($raw);

        for ($i = 1; $i < $length; $i++) {
            if ($raw[$i] === '\\') {
                $i++;
                continue;
            }

            if ($raw[$i] === '"') {
                return $i;
            }
        }

        return null;
    }

    private function assertOnlyComment(string $rest, string $name, int $line, ?string $path): void
    {
        $rest = trim($rest);

        if ($rest !== '' && !str_starts_with($rest, '#')) {
            throw DotenvException::syntax(sprintf('Unexpected characters after the value of "%s"', $name), $line, $path);
        }
    }

    private function unescape(string $value): string
    {
        return strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\', '\\$' => "\0DOLLAR\0"]);
    }

    private function interpolate(string $value, array $values): string
    {
        $value = (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_.]*)(?::-([^}]*))?\}/',
            static function (array $m) use ($values): string {
                $resolved = $values[$m[1]] ?? $_SERVER[$m[1]] ?? $_ENV[$m[1]] ?? getenv($m[1]);

                if ($resolved === false || $resolved === '') {
                    return $m[2] ?? '';
                }

                return is_scalar($resolved) ? (string) $resolved : '';
            },
            $value,
        );

        return str_replace("\0DOLLAR\0", '$', $value);
    }
}