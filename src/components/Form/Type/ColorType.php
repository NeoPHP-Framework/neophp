<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class ColorType extends TextType
{
    public const INPUT = 'color';

    public function getParent(): ?string
    {
        return TextType::class;
    }
}