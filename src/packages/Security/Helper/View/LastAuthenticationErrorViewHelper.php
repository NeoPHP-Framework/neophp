<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;

/**
 * @internal
 */
class LastAuthenticationErrorViewHelper implements ViewFunctionInterface
{
    public function __construct(protected SecurityManagerInterface $security)
    {
    }

    public function getName(): string
    {
        return 'last_authentication_error';
    }

    public function __invoke(bool $clear = true): ?string
    {
        return $this->security->getLastAuthenticationError($clear);
    }
}