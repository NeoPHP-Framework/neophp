<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;

/**
 * @internal
 */
class IsGrantedViewHelper implements ViewFunctionInterface
{
    public function __construct(protected SecurityManagerInterface $security)
    {
    }

    public function getName(): string
    {
        return 'is_granted';
    }

    public function __invoke(string|array $attribute, mixed $subject = null): bool
    {
        return $this->security->isGranted($attribute, $subject);
    }
}