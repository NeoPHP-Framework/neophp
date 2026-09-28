<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Contract;

use DateTimeInterface;
use NeoPHP\Component\Validator\Exception\ValidatorException;
use Stringable;

abstract class AbstractConstraintValidator implements ConstraintValidatorInterface
{
    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    protected function toString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @template T of ConstraintInterface
     * @param class-string<T> $class
     * @phpstan-assert T $constraint
     * @throws ValidatorException
     */
    protected function expect(ConstraintInterface $constraint, string $class): void
    {
        if (!$constraint instanceof $class) {
            throw new ValidatorException('The validator "{validator}" expects a "{expected}" constraint, "{given}" given.', 0, null, [
                'validator' => static::class,
                'expected' => $class,
                'given' => $constraint::class,
            ]);
        }
    }

    public static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"' . $value . '"',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => 'array',
            is_object($value) => 'object',
            default => get_debug_type($value),
        };
    }
}