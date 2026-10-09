<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Formatter;

use NeoPHP\Package\Translation\Exception\TranslationException;
use Stringable;

class MessageFormatter
{
    public const TYPES = ['plural', 'select'];

    public function format(string $message, array $parameters, string $locale): string
    {
        if ($parameters === []) {
            return $message;
        }

        $values = [];
        $percent = [];

        foreach ($parameters as $name => $value) {
            $name = trim((string) $name, '%{} ');
            $values[$name] = $value;
            $percent['%' . $name . '%'] = self::stringify($value);
        }

        $message = strtr($message, $percent);

        if (!str_contains($message, '{')) {
            return $message;
        }

        try {
            return $this->render($this->parse($message), $values, $locale, null);
        } catch (TranslationException) {
            $braces = [];

            foreach ($values as $name => $value) {
                $braces['{' . $name . '}'] = self::stringify($value);
            }

            return strtr($message, $braces);
        }
    }

    public function validate(string $message): ?string
    {
        try {
            $this->parse($message);
        } catch (TranslationException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public function parse(string $message): array
    {
        $position = 0;
        $nodes = $this->parseNodes($message, $position, false);

        return $nodes;
    }

    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    protected function parseNodes(string $message, int &$position, bool $branch): array
    {
        $nodes = [];
        $text = '';
        $length = strlen($message);

        while ($position < $length) {
            $char = $message[$position];

            if ($char === '}' && $branch) {
                break;
            }

            if ($char === '#' && $branch) {
                $nodes[] = $text;
                $nodes[] = ['type' => 'hash'];
                $text = '';
                $position++;
                continue;
            }

            if ($char === '{') {
                $argument = $this->parseArgument($message, $position);

                if ($argument !== null) {
                    $nodes[] = $text;
                    $nodes[] = $argument;
                    $text = '';
                    continue;
                }
            }

            $text .= $char;
            $position++;
        }

        $nodes[] = $text;

        return array_values(array_filter($nodes, static fn (mixed $node): bool => $node !== ''));
    }

    protected function parseArgument(string $message, int &$position): ?array
    {
        $start = $position;

        if (preg_match('/\G\{\s*([A-Za-z_][A-Za-z0-9_.\-]*)\s*(\}|,)/A', $message, $match, 0, $position) !== 1) {
            return null;
        }

        $name = $match[1];
        $position += strlen($match[0]);

        if ($match[2] === '}') {
            return ['type' => 'argument', 'name' => $name, 'raw' => substr($message, $start, $position - $start)];
        }

        if (preg_match('/\G\s*([A-Za-z]+)\s*(\}|,)/A', $message, $match, 0, $position) !== 1) {
            throw new TranslationException('Invalid argument "{name}" at offset {offset}: a type (plural, select, number) is expected.', 0, null, ['name' => $name, 'offset' => $start]);
        }

        $type = strtolower($match[1]);
        $position += strlen($match[0]);

        if ($type === 'number') {
            if ($match[2] === ',') {
                $end = strpos($message, '}', $position);

                if ($end === false) {
                    throw new TranslationException('Unclosed argument "{name}" at offset {offset}.', 0, null, ['name' => $name, 'offset' => $start]);
                }

                $position = $end + 1;
            }

            return ['type' => 'number', 'name' => $name, 'raw' => substr($message, $start, $position - $start)];
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new TranslationException('Unknown argument type "{type}" for "{name}" at offset {offset}: use plural, select or number.', 0, null, ['type' => $type, 'name' => $name, 'offset' => $start]);
        }

        if ($match[2] !== ',') {
            throw new TranslationException('The {type} argument "{name}" at offset {offset} has no options.', 0, null, ['type' => $type, 'name' => $name, 'offset' => $start]);
        }

        $options = [];
        $offset = 0;
        $length = strlen($message);

        while (true) {
            if (preg_match('/\G\s*/A', $message, $space, 0, $position) === 1) {
                $position += strlen($space[0]);
            }

            if ($position >= $length) {
                throw new TranslationException('Unclosed {type} argument "{name}" at offset {offset}.', 0, null, ['type' => $type, 'name' => $name, 'offset' => $start]);
            }

            if ($message[$position] === '}') {
                $position++;
                break;
            }

            if ($type === 'plural' && preg_match('/\Goffset\s*:\s*(\d+)/A', $message, $offsetMatch, 0, $position) === 1) {
                $offset = (int) $offsetMatch[1];
                $position += strlen($offsetMatch[0]);
                continue;
            }

            if (preg_match('/\G(=-?\d+(?:\.\d+)?|[A-Za-z0-9_\-]+)\s*\{/A', $message, $selector, 0, $position) !== 1) {
                throw new TranslationException('Invalid option in the {type} argument "{name}" at offset {offset}: "selector {message}" is expected.', 0, null, ['type' => $type, 'name' => $name, 'offset' => $position]);
            }

            $key = $selector[1];

            if ($type === 'plural' && !str_starts_with($key, '=') && !in_array($key, PluralRules::CATEGORIES, true)) {
                throw new TranslationException('Invalid plural category "{category}" for "{name}": use =N, zero, one, two, few, many or other.', 0, null, ['category' => $key, 'name' => $name]);
            }

            $position += strlen($selector[0]);
            $options[$key] = $this->parseNodes($message, $position, true);

            if ($position >= $length || $message[$position] !== '}') {
                throw new TranslationException('Unclosed option "{option}" in the {type} argument "{name}".', 0, null, ['option' => $key, 'type' => $type, 'name' => $name]);
            }

            $position++;
        }

        if (!isset($options['other'])) {
            throw new TranslationException('The {type} argument "{name}" must define the "other" option.', 0, null, ['type' => $type, 'name' => $name]);
        }

        return ['type' => $type, 'name' => $name, 'options' => $options, 'offset' => $offset, 'raw' => substr($message, $start, $position - $start)];
    }

    protected function render(array $nodes, array $values, string $locale, int|float|null $hash): string
    {
        $output = '';

        foreach ($nodes as $node) {
            if (is_string($node)) {
                $output .= $node;
                continue;
            }

            $output .= match ($node['type']) {
                'hash' => $hash === null ? '#' : self::number($hash),
                'argument' => array_key_exists($node['name'], $values) ? self::stringify($values[$node['name']]) : $node['raw'],
                'number' => array_key_exists($node['name'], $values) ? (is_numeric($values[$node['name']]) ? self::number($values[$node['name']] + 0) : self::stringify($values[$node['name']])) : $node['raw'],
                'plural' => $this->renderPlural($node, $values, $locale),
                'select' => $this->renderSelect($node, $values, $locale, $hash),
                default => '',
            };
        }

        return $output;
    }

    protected function renderPlural(array $node, array $values, string $locale): string
    {
        $value = $values[$node['name']] ?? null;

        if (!is_numeric($value)) {
            return $node['raw'];
        }

        $value += 0;
        $number = $value - $node['offset'];

        foreach ($node['options'] as $key => $branch) {
            if (str_starts_with((string) $key, '=') && (float) substr((string) $key, 1) === (float) $value) {
                return $this->render($branch, $values, $locale, $number);
            }
        }

        $category = PluralRules::category($locale, $number);

        return $this->render($node['options'][$category] ?? $node['options']['other'], $values, $locale, $number);
    }

    protected function renderSelect(array $node, array $values, string $locale, int|float|null $hash): string
    {
        $value = array_key_exists($node['name'], $values) ? self::stringify($values[$node['name']]) : 'other';

        return $this->render($node['options'][$value] ?? $node['options']['other'], $values, $locale, $hash);
    }

    protected static function number(int|float $number): string
    {
        if (is_int($number) || floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
    }
}