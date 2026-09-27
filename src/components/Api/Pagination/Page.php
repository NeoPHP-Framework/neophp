<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

class Page implements IteratorAggregate, Countable, JsonSerializable
{
    protected array $items;

    public function __construct(iterable $items, protected int $total, protected PageRequest $request)
    {
        $this->items = [];

        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPage(): int
    {
        return $this->request->getPage();
    }

    public function getLimit(): int
    {
        return $this->request->getLimit();
    }

    public function getPages(): int
    {
        return max(1, (int) ceil($this->total / $this->request->getLimit()));
    }

    public function hasNext(): bool
    {
        return $this->getPage() < $this->getPages();
    }

    public function hasPrevious(): bool
    {
        return $this->getPage() > 1;
    }

    public function getPageRequest(): PageRequest
    {
        return $this->request;
    }

    public function map(callable $callback): static
    {
        $page = clone $this;
        $page->items = array_map($callback, $this->items);

        return $page;
    }

    public function getLinks(): array
    {
        $page = $this->getPage();
        $pages = $this->getPages();

        return [
            'self' => $this->request->url($page),
            'first' => $this->request->url(1),
            'prev' => $this->hasPrevious() ? $this->request->url(min($page - 1, $pages)) : null,
            'next' => $this->hasNext() ? $this->request->url($page + 1) : null,
            'last' => $this->request->url($pages),
        ];
    }

    public function getLinkHeader(): string
    {
        $links = [];

        foreach ($this->getLinks() as $relation => $url) {
            if ($url !== null && $relation !== 'self') {
                $links[] = '<' . $url . '>; rel="' . $relation . '"';
            }
        }

        return implode(', ', $links);
    }

    public function getHeaders(): array
    {
        return [
            'Link' => $this->getLinkHeader(),
            'X-Total-Count' => (string) $this->total,
        ];
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'pagination' => [
                'total' => $this->total,
                'page' => $this->getPage(),
                'limit' => $this->getLimit(),
                'pages' => $this->getPages(),
            ],
            'links' => $this->getLinks(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}