<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Model\FormView;

class PasswordType extends TextType
{
    public const INPUT = 'password';

    public function getParent(): ?string
    {
        return TextType::class;
    }

    public function configureOptions(): array
    {
        return ['always_empty' => true, 'trim' => false];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = static::INPUT;

        if ($options['always_empty'] || !$form->isSubmitted()) {
            $view->vars['value'] = '';
        }
    }
}