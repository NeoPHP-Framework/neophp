<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Model;

use NeoPHP\Component\Form\Contract\FormInterface;
use Stringable;

class FormError implements Stringable
{
    public function __construct(protected string $message, protected ?FormInterface $origin = null, protected array $parameters = [], protected mixed $cause = null)
    {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getOrigin(): ?FormInterface
    {
        return $this->origin;
    }

    public function setOrigin(FormInterface $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getCause(): mixed
    {
        return $this->cause;
    }

    public function __toString(): string
    {
        return $this->message;
    }
}