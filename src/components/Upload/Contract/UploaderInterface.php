<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Contract;

use NeoPHP\Component\Http\Request\UploadedFile;

interface UploaderInterface
{
    public function store(UploadedFile $file, string $directory = '', array $options = []): string;

    public function delete(?string $path): bool;

    public function exists(?string $path): bool;

    public function url(?string $path, ?string $default = null): ?string;

    public function path(string $path): string;

    public function getUploadPath(): string;

    public function getPublicUrl(): string;
}