<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Model\FormView;

class CheckboxType extends AbstractType
{
    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'required' => false, 'value' => '1', 'false_values' => [null, '', '0', 'false']];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = 'checkbox';
        $view->vars['checked'] = (bool) $form->getData();
        $view->vars['value'] = (string) $options['value'];
    }

    public function transform(mixed $data, array $options): mixed
    {
        return (bool) $data;
    }

    public function reverseTransform(mixed $data, array $options): mixed
    {
        return !in_array($data, (array) $options['false_values'], true) && $data !== false;
    }
}