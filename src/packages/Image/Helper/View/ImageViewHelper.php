<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Image\ImageManagerInterface;

/**
 * @internal
 */
class ImageViewHelper implements ViewFunctionInterface
{
    public function __construct(protected ImageManagerInterface $images)
    {
    }

    public function getName(): string
    {
        return 'image';
    }

    public function __invoke(?string $path, string $preset, ?string $default = null): ?string
    {
        return $this->images->url($path, $preset, $default);
    }
}