<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractForm;
use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Contract\FormTypeInterface;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Model\FormBuilder;
use NeoPHP\Component\Form\Model\FormView;
use ReflectionMethod;

class ResolvedType
{
    protected ?array $defaults = null;

    protected array $implementations = [];

    public function __construct(protected array $chain)
    {
    }

    public function getInnerType(): FormTypeInterface
    {
        return $this->chain[array_key_last($this->chain)];
    }

    public function getChain(): array
    {
        return $this->chain;
    }

    public function getClass(): string
    {
        return $this->getInnerType()::class;
    }

    public function isA(string $class): bool
    {
        foreach ($this->chain as $type) {
            if ($type instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function getBlockPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->chain as $type) {
            $prefix = $type->getBlockPrefix();

            if (!in_array($prefix, $prefixes, true)) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    public function getDefaultOptions(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }

        $defaults = [];

        foreach ($this->chain as $type) {
            if ($type instanceof AbstractForm && $type->getEntityClass() !== null) {
                $defaults['data_class'] = $type->getEntityClass();
            }

            $defaults = array_replace($defaults, $type->configureOptions());
        }

        return $this->defaults = $defaults;
    }

    public function resolveOptions(array $options): array
    {
        $defaults = $this->getDefaultOptions();
        $unknown = array_diff_key($options, $defaults);

        if ($unknown !== []) {
            throw new FormException('The option(s) "{options}" do not exist for the type "{type}". Defined options: {defined}.', 0, null, [
                'options' => implode('", "', array_keys($unknown)),
                'type' => $this->getClass(),
                'defined' => implode(', ', array_keys($defaults)),
            ]);
        }

        return array_replace($defaults, $options);
    }

    public function buildForm(FormBuilder $builder, array $options): void
    {
        foreach ($this->chain as $type) {
            $type->buildForm($builder, $options);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        foreach ($this->chain as $type) {
            $type->buildView($view, $form, $options);
        }
    }

    public function transform(mixed $data, array $options): mixed
    {
        return $this->implementation('transform')->transform($data, $options);
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        return $this->implementation('reverseTransform')->reverseTransform($data, $options);
    }

    public function mapDataToForms(mixed $data, array $forms, array $options, FormInterface $form): void
    {
        $this->implementation('mapDataToForms')->mapDataToForms($data, $forms, $options, $form);
    }

    public function mapFormsToData(array $forms, mixed $data, array $options, FormInterface $form): mixed
    {
        return $this->implementation('mapFormsToData')->mapFormsToData($forms, $data, $options, $form);
    }

    public function preSubmit(FormInterface $form, mixed $data, array $options): mixed
    {
        foreach ($this->chain as $type) {
            $data = $type->preSubmit($form, $data, $options);
        }

        return $data;
    }

    protected function implementation(string $method): FormTypeInterface
    {
        if (isset($this->implementations[$method])) {
            return $this->implementations[$method];
        }

        foreach (array_reverse($this->chain) as $type) {
            if ((new ReflectionMethod($type, $method))->getDeclaringClass()->getName() !== AbstractType::class) {
                return $this->implementations[$method] = $type;
            }
        }

        return $this->implementations[$method] = $this->getInnerType();
    }
}