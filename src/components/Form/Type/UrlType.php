<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class UrlType extends TextType
{
    public const INPUT = 'url';

    public function getParent(): ?string
    {
        return TextType::class;
    }
}