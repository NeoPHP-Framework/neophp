<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class SubmitType extends ButtonType
{
    public const INPUT = 'submit';

    public function getParent(): ?string
    {
        return ButtonType::class;
    }

    public function configureOptions(): array
    {
        return [];
    }
}