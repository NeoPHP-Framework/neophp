<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;

/**
 * @internal
 */
class AppUserViewHelper implements ViewFunctionInterface
{
    public function __construct(protected SecurityManagerInterface $security)
    {
    }

    public function getName(): string
    {
        return 'app_user';
    }

    public function __invoke(): ?UserInterface
    {
        return $this->security->getUser();
    }
}