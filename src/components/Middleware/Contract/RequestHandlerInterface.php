<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Contract;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

interface RequestHandlerInterface
{
    public function handle(Request $request): Response;
}