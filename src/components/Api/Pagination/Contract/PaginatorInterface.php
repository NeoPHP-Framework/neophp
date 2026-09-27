<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination\Contract;

use NeoPHP\Component\Api\Pagination\Page;
use NeoPHP\Component\Api\Pagination\PageRequest;
use NeoPHP\Component\Http\Request\Request;

interface PaginatorInterface
{
    public function paginate(mixed $target, PageRequest|Request|null $request = null): Page;

    public function createPageRequest(?Request $request = null, ?int $defaultLimit = null, ?int $maxLimit = null): PageRequest;
}