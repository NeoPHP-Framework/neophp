<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Model;

class Metric
{
    protected string $status;

    public function __construct(
        protected string $label,
        protected string|int|float $value,
        protected ?string $unit = null,
        string $status = Status::DEFAULT,
        protected ?string $help = null,
    ) {
        $this->status = Status::normalize($status);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string|int|float
    {
        return $this->value;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }
}