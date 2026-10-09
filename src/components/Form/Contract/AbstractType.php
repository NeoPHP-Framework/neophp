<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Contract;

use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Type\FormType;

abstract class AbstractType implements FormTypeInterface
{
    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function getBlockPrefix(): string
    {
        $class = static::class;
        $name = substr($class, (int) strrpos($class, '\\') + (str_contains($class, '\\') ? 1 : 0));

        foreach (['Type', 'Form'] as $suffix) {
            if (str_ends_with($name, $suffix) && $name !== $suffix) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        return strtolower((string) preg_replace(['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1_$2', $name));
    }

    public function configureOptions(): array
    {
        return [];
    }

    public function buildForm(FormBuilder $builder, array $options): void
    {
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
    }

    public function transform(mixed $data, array $options): mixed
    {
        return $data;
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        return $data;
    }

    public function mapDataToForms(mixed $data, array $forms, array $options, FormInterface $form): void
    {
    }

    public function mapFormsToData(array $forms, mixed $data, array $options, FormInterface $form): mixed
    {
        return $data;
    }

    public function preSubmit(FormInterface $form, mixed $data, array $options): mixed
    {
        return $data;
    }
}