<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Trace;

use BackedEnum;
use Stringable;
use UnitEnum;

/**
 * @internal
 */
class TranslationTrace
{
    public const MAX_MESSAGES = 1000;

    public const MAX_LENGTH = 120;

    public const STATE_DEFINED = 'defined';

    public const STATE_FALLBACK = 'fallback';

    public const STATE_MISSING = 'missing';

    protected array $messages = [];

    protected int $dropped = 0;

    public function add(string $id, string $domain, string $locale, ?string $resolvedLocale, string $result, array $parameters = []): void
    {
        $key = $locale . "\0" . $domain . "\0" . $id;

        if (isset($this->messages[$key])) {
            $this->messages[$key]['count']++;

            return;
        }

        if (count($this->messages) >= self::MAX_MESSAGES) {
            $this->dropped++;

            return;
        }

        $this->messages[$key] = [
            'id' => $id,
            'domain' => $domain,
            'locale' => $locale,
            'resolved_locale' => $resolvedLocale,
            'state' => match (true) {
                $resolvedLocale === null => self::STATE_MISSING,
                $resolvedLocale === $locale => self::STATE_DEFINED,
                default => self::STATE_FALLBACK,
            },
            'result' => self::truncate($result),
            'parameters' => array_map(static fn (mixed $value): string => self::describe($value), $parameters),
            'count' => 1,
        ];
    }

    public function getMessages(?string $state = null): array
    {
        $messages = array_values($this->messages);

        return $state === null ? $messages : array_values(array_filter($messages, static fn (array $message): bool => $message['state'] === $state));
    }

    public function getDropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->messages = [];
        $this->dropped = 0;
    }

    public static function describe(mixed $value): string
    {
        return self::truncate(match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            is_array($value) => sprintf('array(%d)', count($value)),
            default => get_debug_type($value),
        });
    }

    public static function truncate(string $value): string
    {
        return mb_strlen($value) <= self::MAX_LENGTH ? $value : mb_substr($value, 0, self::MAX_LENGTH) . '…';
    }
}