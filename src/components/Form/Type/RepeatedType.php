<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Exception\TransformationFailedException;
use NeoPHP\Component\Form\Model\Form;
use NeoPHP\Component\Form\Model\FormBuilder;

class RepeatedType extends AbstractType
{
    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return [
            'type' => TextType::class,
            'options' => [],
            'first_options' => [],
            'second_options' => [],
            'first_name' => 'first',
            'second_name' => 'second',
            'invalid_message' => 'The values do not match.',
        ];
    }

    public function buildForm(FormBuilder $builder, array $options): void
    {
        $label = $options['label'] ?? Form::humanize($builder->getName());
        $base = (array) $options['options'] + ['required' => $options['required']];

        $builder
            ->add((string) $options['first_name'], (string) $options['type'], (array) $options['first_options'] + $base + ['label' => $label])
            ->add((string) $options['second_name'], (string) $options['type'], (array) $options['second_options'] + $base + ['label' => is_string($label) ? 'Repeat ' . lcfirst($label) : $label]);
    }

    public function mapDataToForms(mixed $data, array $forms, array $options, FormInterface $form): void
    {
        foreach ($forms as $child) {
            $child->setData($data);
        }
    }

    public function mapFormsToData(array $forms, mixed $data, array $options, FormInterface $form): mixed
    {
        $first = $form->get((string) $options['first_name']);
        $second = $form->get((string) $options['second_name']);

        if (!$first->isSynchronized() || !$second->isSynchronized()) {
            return $data;
        }

        if ($first->getData() !== $second->getData()) {
            throw new TransformationFailedException('The values do not match.', (string) $options['first_name'], null, [], (string) $options['invalid_message']);
        }

        return $first->getData();
    }
}