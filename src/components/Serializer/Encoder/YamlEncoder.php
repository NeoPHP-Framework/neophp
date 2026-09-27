<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Encoder;

use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\DecoderInterface;
use NeoPHP\Component\Serializer\Contract\EncoderInterface;
use NeoPHP\Component\Serializer\Exception\NotEncodableValueException;
use NeoPHP\Component\Serializer\Exception\UnexpectedValueException;
use NeoPHP\Package\Yaml\Contract\YamlInterface;
use NeoPHP\Package\Yaml\YamlManager;
use Throwable;

class YamlEncoder implements EncoderInterface, DecoderInterface
{
    public const FORMATS = ['yaml', 'yml'];

    public const RESERVED = ['null', '~', 'true', 'false', 'yes', 'no', 'on', 'off', '.inf', '-.inf', '+.inf', '.nan'];

    public function __construct(protected ?YamlInterface $yaml = null)
    {
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        $inline = max(0, (int) ($context[AbstractSerializer::YAML_INLINE] ?? 4));
        $indent = max(1, (int) ($context[AbstractSerializer::YAML_INDENT] ?? 2));

        if (!is_array($data) || $data === [] || $inline === 0) {
            return $this->inline($data) . "\n";
        }

        return $this->block($data, 0, $inline, $indent);
    }

    public function decode(string $data, string $format, array $context = []): mixed
    {
        try {
            return ($this->yaml ??= new YamlManager())->parse($data);
        } catch (Throwable $exception) {
            throw new UnexpectedValueException('Unable to decode YAML: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }

    public function supportsEncoding(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    public function supportsDecoding(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    protected function block(array $data, int $level, int $inline, int $indent): string
    {
        $prefix = str_repeat(' ', $level * $indent);
        $list = array_is_list($data);
        $yaml = '';

        foreach ($data as $key => $value) {
            $head = $prefix . ($list ? '-' : $this->key((string) $key) . ':');

            if (!is_array($value) || $value === [] || $level + 1 >= $inline) {
                $yaml .= $head . ' ' . $this->inline($value) . "\n";
                continue;
            }

            $child = $this->block($value, $level + 1, $inline, $indent);

            if ($list && $indent === 2 && !array_is_list($value)) {
                $yaml .= $prefix . '- ' . substr($child, strlen($prefix) + $indent);
                continue;
            }

            $yaml .= $head . "\n" . $child;
        }

        return $yaml;
    }

    protected function inline(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            if (array_is_list($value)) {
                return '[' . implode(', ', array_map(fn (mixed $item): string => $this->inline($item), $value)) . ']';
            }

            $items = [];

            foreach ($value as $key => $item) {
                $items[] = $this->key((string) $key) . ': ' . $this->inline($item);
            }

            return '{ ' . implode(', ', $items) . ' }';
        }

        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->float($value),
            is_string($value) => $this->string($value),
            default => throw new NotEncodableValueException('Unable to encode a value of type "{type}" as YAML: normalize it first.', 0, null, ['type' => get_debug_type($value)]),
        };
    }

    protected function float(float $value): string
    {
        return match (true) {
            is_nan($value) => '.nan',
            is_infinite($value) => $value > 0 ? '.inf' : '-.inf',
            floor($value) === $value && abs($value) < 1e15 => number_format($value, 1, '.', ''),
            default => (string) $value,
        };
    }

    protected function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][\w.-]*$/', $key) === 1 && !in_array(strtolower($key), self::RESERVED, true) ? $key : $this->quote($key);
    }

    protected function string(string $value): string
    {
        if (preg_match('/^[A-Za-z_\/][\w .\/@-]*$/', $value) === 1 && !str_ends_with($value, ' ') && !in_array(strtolower($value), self::RESERVED, true) && !is_numeric($value)) {
            return $value;
        }

        return $this->quote($value);
    }

    protected function quote(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new NotEncodableValueException('Unable to encode a string as YAML: {error}', 0, null, ['error' => json_last_error_msg()]);
        }

        return $encoded;
    }
}