<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf;

interface CsrfManagerInterface
{
    public function getToken(string $id): string;

    public function refreshToken(string $id): string;

    public function removeToken(string $id): void;

    public function hasToken(string $id): bool;

    public function isTokenValid(string $id, ?string $token): bool;

    public function getFieldName(): string;

    public function getHeaderName(): string;
}