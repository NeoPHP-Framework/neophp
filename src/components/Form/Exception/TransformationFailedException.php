<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Exception;

use Throwable;

class TransformationFailedException extends FormException
{
    public function __construct(string $message = '', protected ?string $child = null, ?Throwable $previous = null, array $context = [], protected ?string $invalidMessage = null)
    {
        parent::__construct($message, 0, $previous, $context);
    }

    public function getChild(): ?string
    {
        return $this->child;
    }

    public function getInvalidMessage(): ?string
    {
        return $this->invalidMessage;
    }
}