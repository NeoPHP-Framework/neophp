<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Helper\Controller;

use NeoPHP\Package\Image\ImageManagerInterface;

trait ImageController
{
    abstract protected function get(string $id): mixed;

    protected function imageUrl(?string $path, string $preset, ?string $default = null): ?string
    {
        return $this->get(ImageManagerInterface::class)->url($path, $preset, $default);
    }

    protected function optimizeImage(string $path, array $options = []): string
    {
        return $this->get(ImageManagerInterface::class)->optimize($path, $options);
    }
}