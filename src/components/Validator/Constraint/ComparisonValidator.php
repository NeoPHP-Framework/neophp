<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use DateTimeImmutable;
use DateTimeInterface;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Exception\ValidatorException;
use Throwable;

class ComparisonValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, AbstractComparison::class);

        if ($value === null) {
            return;
        }

        $compared = $constraint->propertyPath !== null ? $this->propertyValue($context, $constraint->propertyPath) : $constraint->value;

        if ($value instanceof DateTimeInterface && is_string($compared)) {
            try {
                $compared = new DateTimeImmutable($compared);
            } catch (Throwable) {
                throw new ValidatorException('The compared value "{value}" is not a valid date.', 0, null, ['value' => $compared]);
            }
        }

        if (!$constraint->compare($value, $compared)) {
            $context->addViolation($constraint->message, [
                'value' => static::formatValue($value),
                'compared_value' => static::formatValue($compared),
                'compared_value_path' => (string) $constraint->propertyPath,
            ]);
        }
    }

    protected function propertyValue(ExecutionContext $context, string $path): mixed
    {
        $object = $context->getObject();

        if ($object === null) {
            throw new ValidatorException('The "propertyPath" option needs an object: "{path}" cannot be read.', 0, null, ['path' => $path]);
        }

        $current = $object;

        foreach (explode('.', $path) as $segment) {
            if (is_array($current)) {
                $current = $current[$segment] ?? null;
            } elseif (is_object($current) && property_exists($current, $segment)) {
                $property = new \ReflectionProperty($current, $segment);
                $current = $property->isInitialized($current) ? $property->getValue($current) : null;
            } else {
                return null;
            }
        }

        return $current;
    }
}