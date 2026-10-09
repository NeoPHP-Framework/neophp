<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Model\Form;
use NeoPHP\Component\Form\Model\FormView;
use Traversable;

class CollectionType extends AbstractType
{
    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return [
            'entry_type' => TextType::class,
            'entry_options' => [],
            'allow_add' => false,
            'allow_delete' => false,
            'prototype' => true,
            'prototype_name' => '__name__',
            'delete_empty' => false,
        ];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['allow_add'] = (bool) $options['allow_add'];
        $view->vars['allow_delete'] = (bool) $options['allow_delete'];
        $view->vars['prototype_name'] = (string) $options['prototype_name'];

        if ($options['allow_add'] && $options['prototype'] && $form instanceof Form) {
            $prototype = $form->getManager()->createNamedBuilder((string) $options['prototype_name'], (string) $options['entry_type'], null, $this->entryOptions($options) + ['label' => false])->getForm();
            $view->vars['prototype'] = $prototype->createView($view);
        }
    }

    public function mapDataToForms(mixed $data, array $forms, array $options, FormInterface $form): void
    {
        foreach (array_keys($form->all()) as $name) {
            $form->remove((string) $name);
        }

        $items = $data instanceof Traversable ? iterator_to_array($data) : (is_array($data) ? $data : []);

        foreach ($items as $key => $item) {
            $this->addEntry($form, (string) $key, $options)->setData($item);
        }
    }

    public function mapFormsToData(array $forms, mixed $data, array $options, FormInterface $form): mixed
    {
        $result = [];

        foreach ($form->all() as $name => $child) {
            if ($child->isSubmitted() && !$child->isSynchronized()) {
                continue;
            }

            $value = $child->getData();

            if ($options['delete_empty'] && ($value === null || $value === '' || $value === [])) {
                continue;
            }

            $result[$name] = $value;
        }

        return $result;
    }

    public function preSubmit(FormInterface $form, mixed $data, array $options): mixed
    {
        $data = is_array($data) ? $data : [];

        if ($options['allow_delete']) {
            foreach (array_keys($form->all()) as $name) {
                if (!array_key_exists($name, $data)) {
                    $form->remove((string) $name);
                }
            }
        }

        if ($options['allow_add']) {
            foreach ($data as $key => $value) {
                if (!$form->has((string) $key)) {
                    $this->addEntry($form, (string) $key, $options);
                }
            }
        }

        return $data;
    }

    protected function addEntry(FormInterface $form, string $name, array $options): FormInterface
    {
        $child = $form instanceof Form
            ? $form->getManager()->createNamedBuilder($name, (string) $options['entry_type'], null, $this->entryOptions($options))->getForm()
            : null;

        if ($child === null) {
            $form->add($name, (string) $options['entry_type'], $this->entryOptions($options));

            return $form->get($name);
        }

        $form->addChild($child);

        return $child;
    }

    protected function entryOptions(array $options): array
    {
        return (array) $options['entry_options'] + ['label' => false];
    }
}