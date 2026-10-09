<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;

/**
 * @internal
 */
class LastUsernameViewHelper implements ViewFunctionInterface
{
    public function __construct(protected SecurityManagerInterface $security)
    {
    }

    public function getName(): string
    {
        return 'last_username';
    }

    public function __invoke(): string
    {
        return $this->security->getLastUsername();
    }
}