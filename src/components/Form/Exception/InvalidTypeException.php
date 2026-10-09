<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Exception;

class InvalidTypeException extends FormException
{
    public function isNull(): bool
    {
        return (bool) ($this->context['null'] ?? false);
    }
}