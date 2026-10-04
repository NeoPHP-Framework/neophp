<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Helper\Controller;

use NeoPHP\Component\Http\Request\UploadedFile;
use NeoPHP\Component\Upload\Contract\UploaderInterface;

trait UploadController
{
    abstract protected function get(string $id): mixed;

    protected function storeUpload(UploadedFile $file, string $directory = '', array $options = []): string
    {
        return $this->get(UploaderInterface::class)->store($file, $directory, $options);
    }

    protected function deleteUpload(?string $path): bool
    {
        return $this->get(UploaderInterface::class)->delete($path);
    }

    protected function uploadUrl(?string $path, ?string $default = null): ?string
    {
        return $this->get(UploaderInterface::class)->url($path, $default);
    }
}