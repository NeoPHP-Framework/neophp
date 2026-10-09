<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Helper\Controller;

use NeoPHP\Component\Http\Request\UploadedFile;
use NeoPHP\Component\Upload\UploadManagerInterface;

trait UploadController
{
    abstract protected function get(string $id): mixed;

    protected function storeUpload(UploadedFile $file, string $directory = '', array $options = []): string
    {
        return $this->get(UploadManagerInterface::class)->store($file, $directory, $options);
    }

    protected function deleteUpload(?string $path): bool
    {
        return $this->get(UploadManagerInterface::class)->delete($path);
    }

    protected function uploadUrl(?string $path, ?string $default = null): ?string
    {
        return $this->get(UploadManagerInterface::class)->url($path, $default);
    }
}