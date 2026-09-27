<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

class KeyValueBlock extends AbstractBlock
{
    public const TYPE = 'key_value';

    public function __construct(
        protected array $items,
        ?string $title = null,
        protected string $emptyMessage = 'No data.',
    ) {
        $this->title = $title;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getEmptyMessage(): string
    {
        return $this->emptyMessage;
    }
}