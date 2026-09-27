<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Contract;

interface ResponseInterface
{
    public function getStatusCode(): int;

    public function getHeaders(): array;

    public function getHeader(string $name): ?string;

    public function getContent(bool $throw = true): string;

    public function toArray(bool $throw = true): array;

    public function getInfo(?string $key = null): mixed;

    public function isSuccessful(): bool;
}