<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Helper\View;

use NeoPHP\Component\Upload\UploadManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class UploadViewHelper implements ViewFunctionInterface
{
    public function __construct(protected UploadManagerInterface $uploader)
    {
    }

    public function getName(): string
    {
        return 'upload';
    }

    public function __invoke(?string $path, ?string $default = null): ?string
    {
        return $this->uploader->url($path, $default);
    }
}