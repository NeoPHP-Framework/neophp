<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Contract\ConstraintValidatorInterface;
use NeoPHP\Component\Validator\Exception\ValidationFailedException;
use NeoPHP\Component\Validator\Exception\ValidatorException;
use NeoPHP\Component\Validator\Metadata\MetadataFactory;
use NeoPHP\Component\Validator\Provider\ValidatorProvider;
use NeoPHP\Component\Validator\Violation\ViolationList;
use NeoPHP\Package\Translation\TranslationManagerInterface;

#[Component(provider: ValidatorProvider::class)]
final class ValidatorManager implements ValidatorManagerInterface
{
    public const TRANSLATION_DOMAIN = 'validators';

    protected ?ContainerManagerInterface $container = null;

    protected MetadataFactory $metadata;

    protected array $validators = [];

    public function __construct(?ContainerManagerInterface $container = null, ?MetadataFactory $metadata = null)
    {
        $this->container = $container;
        $this->metadata = $metadata ?? new MetadataFactory();
    }

    public function validate(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = [AbstractConstraint::DEFAULT_GROUP]): ViolationList
    {
        $context = new ExecutionContext($this, $value, $groups);

        if ($constraints === null) {
            if (!is_object($value)) {
                throw new ValidatorException('Constraints are required to validate a value of type "{type}".', 0, null, ['type' => get_debug_type($value)]);
            }

            $this->validateObjectInContext($context, $value, '');
        } elseif ($constraints instanceof ConstraintInterface) {
            $this->validateInContext($context, $value, [$constraints], '', null);
        } elseif ($this->isFieldMap($constraints)) {
            $this->validateFieldsInContext($context, $value, $constraints, '');
        } else {
            $this->validateInContext($context, $value, $constraints, '', null);
        }

        return $context->getViolations();
    }

    public function translateMessage(string $message): string
    {
        if ($message === '' || $this->container === null || !$this->container->has(TranslationManagerInterface::class)) {
            return $message;
        }

        return $this->container->get(TranslationManagerInterface::class)->translate($message, [], self::TRANSLATION_DOMAIN);
    }

    public function validateProperty(object $object, string $property, array $groups = [AbstractConstraint::DEFAULT_GROUP]): ViolationList
    {
        $context = new ExecutionContext($this, $object, $groups);
        $metadata = $this->metadata->get($object::class)['properties'][$property] ?? null;

        if ($metadata !== null) {
            $this->validateInContext($context, MetadataFactory::value($metadata['property'], $object), $metadata['constraints'], $property, $object);
        }

        return $context->getViolations();
    }

    public function validateOrFail(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = [AbstractConstraint::DEFAULT_GROUP]): void
    {
        $violations = $this->validate($value, $constraints, $groups);

        if (count($violations) > 0) {
            throw ValidationFailedException::create($violations);
        }
    }

    public function validateInContext(ExecutionContext $context, mixed $value, array $constraints, string $path, ?object $object): void
    {
        foreach ($constraints as $constraint) {
            if (!$constraint instanceof ConstraintInterface) {
                throw new ValidatorException('"{type}" is not a constraint: it must implement {interface}.', 0, null, [
                    'type' => get_debug_type($constraint),
                    'interface' => ConstraintInterface::class,
                ]);
            }

            if (!$constraint->inGroups($context->getGroups())) {
                continue;
            }

            $previous = $context->enter($path, $constraint, $value, $object);

            try {
                $this->constraintValidator($constraint)->validate($value, $constraint, $context);
            } finally {
                $context->leave($previous);
            }
        }
    }

    public function validateObjectInContext(ExecutionContext $context, object $object, string $path): void
    {
        if (!$context->markValidated($object, $path)) {
            return;
        }

        $metadata = $this->metadata->get($object::class);

        foreach ($metadata['properties'] as $name => $property) {
            $this->validateInContext($context, MetadataFactory::value($property['property'], $object), $property['constraints'], ExecutionContext::join($path, (string) $name), $object);
        }

        $this->validateInContext($context, $object, $metadata['class'], $path, $object);
    }

    public function validateFieldsInContext(ExecutionContext $context, mixed $data, array $fields, string $path): void
    {
        $data = is_object($data) ? get_object_vars($data) : (array) $data;

        foreach ($fields as $field => $constraints) {
            $this->validateInContext($context, $data[$field] ?? null, is_array($constraints) ? $constraints : [$constraints], ExecutionContext::join($path, (string) $field), null);
        }
    }

    protected function constraintValidator(ConstraintInterface $constraint): ConstraintValidatorInterface
    {
        $class = $constraint->validatedBy();

        if (!isset($this->validators[$class])) {
            if (!class_exists($class)) {
                throw new ValidatorException('The validator "{validator}" of the constraint "{constraint}" does not exist.', 0, null, [
                    'validator' => $class,
                    'constraint' => $constraint::class,
                ]);
            }

            $validator = $this->container !== null ? $this->container->get($class) : new $class();

            if (!$validator instanceof ConstraintValidatorInterface) {
                throw new ValidatorException('The validator "{validator}" must implement {interface}.', 0, null, [
                    'validator' => $class,
                    'interface' => ConstraintValidatorInterface::class,
                ]);
            }

            $this->validators[$class] = $validator;
        }

        return $this->validators[$class];
    }

    protected function isFieldMap(array $constraints): bool
    {
        foreach (array_keys($constraints) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }
}