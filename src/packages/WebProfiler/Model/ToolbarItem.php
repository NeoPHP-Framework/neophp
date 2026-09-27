<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Model;

class ToolbarItem
{
    protected string $status;

    public function __construct(
        protected string $label,
        protected string $value = '',
        protected string $icon = 'info',
        string $status = Status::DEFAULT,
        protected array $details = [],
        protected ?string $panel = null,
        protected bool $linked = true,
    ) {
        $this->status = Status::normalize($status);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function addDetail(string $label, mixed $value): static
    {
        $this->details[$label] = $value;

        return $this;
    }

    public function getPanel(): ?string
    {
        return $this->panel;
    }

    public function setPanel(?string $panel): static
    {
        $this->panel = $panel;

        return $this;
    }

    public function isLinked(): bool
    {
        return $this->linked;
    }
}