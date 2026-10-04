<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Helper\View;

use NeoPHP\Component\Upload\Contract\UploaderInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

class UploadViewHelper implements ViewFunctionInterface
{
    public function __construct(protected UploaderInterface $uploader)
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