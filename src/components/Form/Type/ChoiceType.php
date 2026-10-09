<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use BackedEnum;
use NeoPHP\Component\Form\Accessor\PropertyAccessor;
use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\FormView;
use Stringable;
use UnitEnum;

class ChoiceType extends AbstractType
{
    protected PropertyAccessor $accessor;

    public function __construct(?PropertyAccessor $accessor = null)
    {
        $this->accessor = $accessor ?? new PropertyAccessor();
    }

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return [
            'compound' => false,
            'choices' => [],
            'multiple' => false,
            'expanded' => false,
            'placeholder' => null,
            'choice_label' => null,
            'choice_value' => null,
            'choice_attr' => null,
            'invalid_message' => 'The selected choice is invalid.',
        ];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $choices = [];

        foreach ($this->getChoiceList($options) as $choice) {
            $choices[] = ['value' => $choice['value'], 'label' => $choice['label'], 'attr' => $choice['attr']];
        }

        $placeholder = $options['placeholder'];

        if ($placeholder === null && !$options['multiple'] && !$options['expanded'] && !$form->isRequired()) {
            $placeholder = '';
        }

        $view->vars['choices'] = $choices;
        $view->vars['multiple'] = (bool) $options['multiple'];
        $view->vars['expanded'] = (bool) $options['expanded'];
        $view->vars['placeholder'] = $placeholder === false ? null : $placeholder;
        $view->vars['value'] = $options['multiple'] ? (array) ($view->vars['value'] ?? []) : (string) ($view->vars['value'] ?? '');
    }

    public function transform(mixed $data, array $options): mixed
    {
        $list = $this->getChoiceList($options);

        if ($options['multiple']) {
            $values = [];

            foreach (is_iterable($data) ? $data : [] as $item) {
                $value = $this->findValue($item, $list, $options);

                if ($value !== null) {
                    $values[] = $value;
                }
            }

            return $values;
        }

        return $data === null ? '' : ($this->findValue($data, $list, $options) ?? '');
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        $list = $this->getChoiceList($options);
        $byValue = array_column($list, null, 'value');

        if ($options['multiple']) {
            if ($data === null || $data === '') {
                return [];
            }

            if (!is_array($data)) {
                throw new TransformationFailedException('Expected an array of choices.');
            }

            $result = [];

            foreach ($data as $value) {
                if (!is_scalar($value) || !isset($byValue[(string) $value])) {
                    throw new TransformationFailedException('The choice "{value}" does not exist.', null, null, ['value' => is_scalar($value) ? $value : get_debug_type($value)]);
                }

                $result[] = $byValue[(string) $value]['data'];
            }

            return $result;
        }

        if ($data === null || $data === '') {
            return null;
        }

        if (!is_scalar($data) || !isset($byValue[(string) $data])) {
            throw new TransformationFailedException('The choice "{value}" does not exist.', null, null, ['value' => is_scalar($data) ? $data : get_debug_type($data)]);
        }

        return $byValue[(string) $data]['data'];
    }

    public function getChoiceList(array $options): array
    {
        $list = [];

        foreach ($this->loadChoices($options) as $index => [$data, $label]) {
            $value = $this->valueOf($data, $options, (int) $index);
            $list[] = [
                'value' => $value,
                'label' => $this->labelOf($data, $label, $options, $value),
                'data' => $data,
                'attr' => $this->attrOf($data, $options, $value),
            ];
        }

        return $list;
    }

    protected function loadChoices(array $options): array
    {
        $choices = [];
        $list = array_is_list((array) $options['choices']);

        foreach ((array) $options['choices'] as $label => $data) {
            $choices[] = [$data, $list ? null : (string) $label];
        }

        return $choices;
    }

    protected function valueOf(mixed $data, array $options, int $index): string
    {
        $choiceValue = $options['choice_value'];

        if (is_callable($choiceValue) && !is_string($choiceValue)) {
            return (string) $choiceValue($data);
        }

        if (is_string($choiceValue) && is_object($data)) {
            return (string) $this->accessor->getValue($data, $choiceValue);
        }

        return match (true) {
            $data instanceof BackedEnum => (string) $data->value,
            $data instanceof UnitEnum => $data->name,
            is_bool($data) => $data ? '1' : '0',
            $data === null => '',
            is_scalar($data) => (string) $data,
            default => (string) $index,
        };
    }

    protected function labelOf(mixed $data, ?string $label, array $options, string $value): string
    {
        $choiceLabel = $options['choice_label'];

        if (is_callable($choiceLabel) && !is_string($choiceLabel)) {
            return (string) $choiceLabel($data, $label, $value);
        }

        if (is_string($choiceLabel) && is_object($data)) {
            return (string) $this->accessor->getValue($data, $choiceLabel);
        }

        if ($label !== null) {
            return $label;
        }

        return match (true) {
            $data instanceof UnitEnum => $data->name,
            $data instanceof Stringable, is_scalar($data) => (string) $data,
            default => $value,
        };
    }

    protected function attrOf(mixed $data, array $options, string $value): array
    {
        $choiceAttr = $options['choice_attr'];

        if (is_callable($choiceAttr) && !is_string($choiceAttr)) {
            return (array) $choiceAttr($data, $value);
        }

        return is_array($choiceAttr) ? (array) ($choiceAttr[$value] ?? []) : [];
    }

    protected function findValue(mixed $data, array $list, array $options): ?string
    {
        foreach ($list as $choice) {
            if ($choice['data'] === $data) {
                return $choice['value'];
            }
        }

        if (is_object($data) && !$data instanceof UnitEnum) {
            return null;
        }

        $value = $this->valueOf($data, $options, -1);

        foreach ($list as $choice) {
            if ($choice['value'] === $value) {
                return $value;
            }
        }

        return null;
    }
}