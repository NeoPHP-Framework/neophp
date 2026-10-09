<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Helper\View;

use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class CsrfTokenViewHelper implements ViewFunctionInterface
{
    public function __construct(protected CsrfManagerInterface $csrf)
    {
    }

    public function getName(): string
    {
        return 'csrf_token';
    }

    public function __invoke(string $id): string
    {
        return $this->csrf->getToken($id);
    }
}