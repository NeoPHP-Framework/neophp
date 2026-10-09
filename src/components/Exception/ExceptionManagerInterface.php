<?php

declare(strict_types=1);

namespace NeoPHP\Component\Exception;

use Closure;
use Throwable;

interface ExceptionManagerInterface
{
    public function setDumper(?Closure $dumper): static;

    public function getDumper(): ?Closure;

    public function isDebug(): bool;

    public function setDebug(bool $debug): static;

    public function getStatusCode(Throwable $exception): int;

    public function getHeaders(Throwable $exception): array;

    public function render(Throwable $exception): string;

    public function renderJson(Throwable $exception): array;
}