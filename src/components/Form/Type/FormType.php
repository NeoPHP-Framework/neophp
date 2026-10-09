<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Accessor\PropertyAccessor;
use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Exception\InvalidTypeException;
use NeoPHP\Component\Form\Model\Form;
use ReflectionClass;

class FormType extends AbstractType
{
    public const NOT_BLANK_MESSAGE = 'This value should not be blank.';

    protected PropertyAccessor $accessor;

    public function __construct(?PropertyAccessor $accessor = null)
    {
        $this->accessor = $accessor ?? new PropertyAccessor();
    }

    public function getParent(): ?string
    {
        return null;
    }

    public function getBlockPrefix(): string
    {
        return 'form';
    }

    public function configureOptions(): array
    {
        return [
            'compound' => true,
            'data_class' => null,
            'data' => null,
            'empty_data' => null,
            'required' => true,
            'disabled' => false,
            'label' => null,
            'label_attr' => [],
            'attr' => [],
            'row_attr' => [],
            'help' => null,
            'help_attr' => [],
            'translation_domain' => null,
            'mapped' => true,
            'property_path' => null,
            'constraints' => [],
            'invalid_message' => 'This value is not valid.',
            'error_bubbling' => false,
            'method' => 'POST',
            'action' => '',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => null,
            'allow_extra_fields' => false,
            'validation_groups' => null,
            'trim' => true,
            'block_prefix' => null,
            'theme' => null,
        ];
    }

    public function mapDataToForms(mixed $data, array $forms, array $options, FormInterface $form): void
    {
        foreach ($forms as $child) {
            $child->setData($data === null ? null : $this->accessor->getValue($data, $child->getPropertyPath()));
        }
    }

    public function mapFormsToData(array $forms, mixed $data, array $options, FormInterface $form): mixed
    {
        if ($data === null) {
            $data = $this->createData($options);
        }

        foreach ($forms as $child) {
            try {
                $this->accessor->setValue($data, $child->getPropertyPath(), $child->getData());
            } catch (InvalidTypeException $exception) {
                if ($child instanceof Form) {
                    $child->markTransformationFailed($exception->isNull() ? self::NOT_BLANK_MESSAGE : (string) $child->getOption('invalid_message'));
                }
            }
        }

        return $data;
    }

    protected function createData(array $options): mixed
    {
        $empty = $options['empty_data'] ?? null;

        if (is_callable($empty) && !is_string($empty)) {
            return $empty();
        }

        $class = $options['data_class'] ?? null;

        if ($class === null) {
            return is_array($empty) ? $empty : [];
        }

        if (!class_exists($class)) {
            throw new FormException('The data_class "{class}" does not exist.', 0, null, ['class' => $class]);
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0 ? $reflection->newInstance() : $reflection->newInstanceWithoutConstructor();
    }
}