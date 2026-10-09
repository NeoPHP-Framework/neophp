<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash\Helper\View;

use NeoPHP\Component\Flash\FlashManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class FlashesViewHelper implements ViewFunctionInterface
{
    public function __construct(protected FlashManagerInterface $flash)
    {
    }

    public function getName(): string
    {
        return 'flashes';
    }

    public function __invoke(?string $type = null): array
    {
        return $type === null ? $this->flash->all() : $this->flash->get($type);
    }
}