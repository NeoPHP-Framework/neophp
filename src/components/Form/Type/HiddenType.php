<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class HiddenType extends TextType
{
    public const INPUT = 'hidden';

    public function getParent(): ?string
    {
        return FormType::class;
    }

    public function configureOptions(): array
    {
        return ['compound' => false, 'required' => false, 'error_bubbling' => true, 'label' => false];
    }
}