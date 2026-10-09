<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class TelType extends TextType
{
    public const INPUT = 'tel';

    public function getParent(): ?string
    {
        return TextType::class;
    }
}