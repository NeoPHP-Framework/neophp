<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

class TableBlock extends AbstractBlock
{
    public const TYPE = 'table';

    public function __construct(
        protected array $headers,
        protected array $rows = [],
        ?string $title = null,
        protected string $emptyMessage = 'No data.',
    ) {
        $this->title = $title;
    }

    public function addRow(array $row): static
    {
        $this->rows[] = $row;

        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRows(): array
    {
        return $this->rows;
    }

    public function getEmptyMessage(): string
    {
        return $this->emptyMessage;
    }
}