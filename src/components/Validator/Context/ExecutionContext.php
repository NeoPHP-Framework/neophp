<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Context;

use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\ValidatorManager;
use NeoPHP\Component\Validator\Violation\Violation;
use NeoPHP\Component\Validator\Violation\ViolationList;

class ExecutionContext
{
    protected ViolationList $violations;

    protected string $path = '';

    protected ?object $object = null;

    protected ?ConstraintInterface $constraint = null;

    protected mixed $value = null;

    protected array $validated = [];

    public function __construct(protected ValidatorManager $validator, protected mixed $root, protected array $groups)
    {
        $this->violations = new ViolationList();
    }

    public function addViolation(string $message, array $parameters = [], ?string $path = null, mixed $invalidValue = null): void
    {
        $replacements = [];

        foreach ($parameters as $name => $value) {
            $replacements['{{ ' . trim((string) $name, '{} ') . ' }}'] = (string) $value;
        }

        $this->violations->add(new Violation(
            strtr($this->validator->translateMessage($message), $replacements),
            $message,
            $parameters,
            $path === null ? $this->path : static::join($this->path, $path),
            func_num_args() >= 4 ? $invalidValue : $this->value,
            $this->constraint,
        ));
    }

    public function validate(mixed $value, ConstraintInterface|array $constraints, string $path = ''): void
    {
        $this->validator->validateInContext($this, $value, is_array($constraints) ? $constraints : [$constraints], static::join($this->path, $path), $this->object);
    }

    public function validateObject(object $object, string $path = ''): void
    {
        $this->validator->validateObjectInContext($this, $object, static::join($this->path, $path));
    }

    public function enter(string $path, ?ConstraintInterface $constraint, mixed $value, ?object $object): array
    {
        $previous = [$this->path, $this->constraint, $this->value, $this->object];
        $this->path = $path;
        $this->constraint = $constraint;
        $this->value = $value;
        $this->object = $object;

        return $previous;
    }

    public function leave(array $previous): void
    {
        [$this->path, $this->constraint, $this->value, $this->object] = $previous;
    }

    public function markValidated(object $object, string $path): bool
    {
        $key = spl_object_id($object);

        if (isset($this->validated[$key])) {
            return false;
        }

        $this->validated[$key] = true;

        return true;
    }

    public function getViolations(): ViolationList
    {
        return $this->violations;
    }

    public function getPropertyPath(): string
    {
        return $this->path;
    }

    public function getObject(): ?object
    {
        return $this->object;
    }

    public function getRoot(): mixed
    {
        return $this->root;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getConstraint(): ?ConstraintInterface
    {
        return $this->constraint;
    }

    public static function join(string $parent, string $child): string
    {
        if ($child === '') {
            return $parent;
        }

        if ($parent === '' || str_starts_with($child, '[')) {
            return $parent . $child;
        }

        return $parent . '.' . $child;
    }
}