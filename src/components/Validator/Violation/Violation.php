<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Violation;

use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class Violation
{
    public function __construct(
        protected string $message,
        protected string $messageTemplate,
        protected array $parameters,
        protected string $propertyPath,
        protected mixed $invalidValue,
        protected ?ConstraintInterface $constraint = null,
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMessageTemplate(): string
    {
        return $this->messageTemplate;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getPropertyPath(): string
    {
        return $this->propertyPath;
    }

    public function getInvalidValue(): mixed
    {
        return $this->invalidValue;
    }

    public function getConstraint(): ?ConstraintInterface
    {
        return $this->constraint;
    }

    public function __toString(): string
    {
        return ($this->propertyPath !== '' ? $this->propertyPath . ': ' : '') . $this->message;
    }
}