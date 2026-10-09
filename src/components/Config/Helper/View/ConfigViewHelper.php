<?php

declare(strict_types=1);

namespace NeoPHP\Component\Config\Helper\View;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class ConfigViewHelper implements ViewFunctionInterface
{
    public function __construct(protected ConfigManagerInterface $config)
    {
    }

    public function getName(): string
    {
        return 'config';
    }

    public function __invoke(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }
}