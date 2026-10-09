<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Type;

class SearchType extends TextType
{
    public const INPUT = 'search';

    public function getParent(): ?string
    {
        return TextType::class;
    }
}