<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination;

use NeoPHP\Component\Api\Exception\ApiException;

class PageRequest
{
    public function __construct(
        protected int $page = 1,
        protected int $limit = 20,
        protected string $path = '/',
        protected array $query = [],
        protected string $pageParameter = 'page',
        protected string $limitParameter = 'limit',
    ) {
        if ($page < 1 || $limit < 1) {
            throw new ApiException('Invalid page request: the page ({page}) and the limit ({limit}) must be greater than 0.', 0, null, ['page' => $page, 'limit' => $limit]);
        }
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function getPageParameter(): string
    {
        return $this->pageParameter;
    }

    public function getLimitParameter(): string
    {
        return $this->limitParameter;
    }

    public function withPage(int $page): static
    {
        $request = clone $this;
        $request->page = max(1, $page);

        return $request;
    }

    public function url(int $page): string
    {
        $query = $this->query;
        unset($query[$this->pageParameter], $query[$this->limitParameter]);
        $query[$this->pageParameter] = $page;
        $query[$this->limitParameter] = $this->limit;

        return $this->path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}