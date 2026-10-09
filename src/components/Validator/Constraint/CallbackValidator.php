<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Exception\ValidatorException;

class CallbackValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Callback::class);

        $callback = $constraint->callback;
        $object = $context->getObject();

        if (is_string($callback) && $object !== null && method_exists($object, $callback)) {
            $method = new \ReflectionMethod($object, $callback);
            $method->invoke($method->isStatic() ? null : $object, ...($value === $object ? [$context] : [$value, $context]));

            return;
        }

        if (!is_callable($callback)) {
            throw new ValidatorException('The callback of the Callback constraint is not callable.');
        }

        $callback($value, $context);
    }
}