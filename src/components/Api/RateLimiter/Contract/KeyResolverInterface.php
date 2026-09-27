<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Contract;

use NeoPHP\Component\Http\Request\Request;

interface KeyResolverInterface
{
    public function resolve(Request $request): ?string;
}