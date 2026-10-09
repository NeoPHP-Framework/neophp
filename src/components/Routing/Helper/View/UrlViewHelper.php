<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Helper\View;

use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class UrlViewHelper implements ViewFunctionInterface
{
    public function __construct(protected RoutingManagerInterface $routing)
    {
    }

    public function getName(): string
    {
        return 'url';
    }

    public function __invoke(string $name, array $parameters = []): string
    {
        return $this->routing->generate($name, $parameters, true);
    }
}