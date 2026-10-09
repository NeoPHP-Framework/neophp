<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class EmailType extends TextType
{
    public const INPUT = 'email';

    public function getParent(): ?string
    {
        return TextType::class;
    }
}