<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Helper\View;

use NeoPHP\Component\Asset\AssetManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class AssetViewHelper implements ViewFunctionInterface
{
    public function __construct(protected AssetManagerInterface $asset)
    {
    }

    public function getName(): string
    {
        return 'asset';
    }

    public function __invoke(string $path): string
    {
        return $this->asset->url($path);
    }
}