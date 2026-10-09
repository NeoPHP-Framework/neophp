<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

use NeoPHP\Component\Form\Contract\AbstractType;
use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Model\FormView;

class ButtonType extends AbstractType
{
    public const INPUT = 'button';

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'mapped' => false, 'required' => false, 'button' => true];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['type'] = static::INPUT;
        $view->vars['clicked'] = $form->isClicked();
    }
}