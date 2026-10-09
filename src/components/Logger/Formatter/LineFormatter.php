<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger\Formatter;

use DateTimeInterface;
use JsonSerializable;
use Stringable;
use Throwable;

class LineFormatter
{
    public const DEFAULT_FORMAT = '[%datetime%] %channel%.%level% %message% %context%';

    public const DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        protected string $format = self::DEFAULT_FORMAT,
        protected string $dateFormat = self::DEFAULT_DATE_FORMAT,
    ) {
    }

    public function format(string $channel, string $level, string|Stringable $message, array $context, DateTimeInterface $datetime): string
    {
        $line = strtr($this->format, [
            '%datetime%' => $datetime->format($this->dateFormat),
            '%channel%' => $channel,
            '%level%' => strtoupper($level),
            '%type%' => strtoupper($level),
            '%message%' => $this->interpolate((string) $message, $context),
            '%context%' => $context === [] ? '' : $this->encode($this->normalize($context)),
        ]);

        return rtrim(str_replace(["\r\n", "\r", "\n"], ' ', $line)) . PHP_EOL;
    }

    public function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_array($value) || (is_object($value) && !$value instanceof Stringable && !$value instanceof DateTimeInterface)) {
                continue;
            }

            $replacements['{' . $key . '}'] = $this->stringify($value);
        }

        return strtr($message, $replacements);
    }

    protected function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format($this->dateFormat),
            default => (string) $value,
        };
    }

    protected function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[...]';
        }

        return match (true) {
            $value instanceof Throwable => [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ],
            $value instanceof DateTimeInterface => $value->format($this->dateFormat),
            $value instanceof JsonSerializable => $this->normalize($value->jsonSerialize(), $depth + 1),
            $value instanceof Stringable => (string) $value,
            is_array($value) => array_map(fn (mixed $item): mixed => $this->normalize($item, $depth + 1), $value),
            is_object($value) => '[object ' . $value::class . ']',
            is_resource($value) => '[resource ' . get_resource_type($value) . ']',
            default => $value,
        };
    }

    protected function encode(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '' : $json;
    }
}