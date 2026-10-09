<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session\Helper\View;

use NeoPHP\Component\Session\SessionManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class SessionViewHelper implements ViewFunctionInterface
{
    public function __construct(protected SessionManagerInterface $session)
    {
    }

    public function getName(): string
    {
        return 'session';
    }

    public function __invoke(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }
}