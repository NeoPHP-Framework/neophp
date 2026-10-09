<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Exception\ValidatorException;

class ChoiceValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Choice::class);

        if ($value === null) {
            return;
        }

        $choices = $this->choices($constraint, $context);
        $formatted = implode(', ', array_map([static::class, 'formatValue'], $choices));

        if (!$constraint->multiple) {
            if (!in_array($value, $choices, $constraint->strict)) {
                $context->addViolation($constraint->message, ['value' => static::formatValue($value), 'choices' => $formatted]);
            }

            return;
        }

        if (!is_array($value)) {
            $context->addViolation($constraint->multipleMessage, ['value' => static::formatValue($value), 'choices' => $formatted]);

            return;
        }

        foreach ($value as $item) {
            if (!in_array($item, $choices, $constraint->strict)) {
                $context->addViolation($constraint->multipleMessage, ['value' => static::formatValue($item), 'choices' => $formatted]);

                return;
            }
        }

        if ($constraint->min !== null && count($value) < $constraint->min) {
            $context->addViolation($constraint->minMessage, ['limit' => $constraint->min]);
        } elseif ($constraint->max !== null && count($value) > $constraint->max) {
            $context->addViolation($constraint->maxMessage, ['limit' => $constraint->max]);
        }
    }

    protected function choices(Choice $constraint, ExecutionContext $context): array
    {
        if ($constraint->callback === null) {
            return $constraint->choices;
        }

        $callback = $constraint->callback;
        $object = $context->getObject();

        if (is_string($callback) && $object !== null && method_exists($object, $callback)) {
            $callback = [$object, $callback];
        }

        if (!is_callable($callback)) {
            throw new ValidatorException('The callback of the Choice constraint is not callable.');
        }

        return (array) $callback();
    }
}