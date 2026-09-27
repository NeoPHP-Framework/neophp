<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use Throwable;

interface ProfilerElementInterface
{
    public function getName(): string;

    public function getPriority(): int;

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array;
}