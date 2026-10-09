<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

use NeoPHP\Package\WebProfiler\Contract\BlockInterface;

abstract class AbstractBlock implements BlockInterface
{
    public const TYPE = 'block';

    protected ?string $title = null;

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }
}