<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class TextareaType extends TextType
{
    public function getParent(): ?string
    {
        return TextType::class;
    }
}