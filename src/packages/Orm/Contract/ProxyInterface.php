<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use Closure;

interface ProxyInterface
{
    public function __neoLoad(): void;

    public function __neoIsInitialized(): bool;

    public function __neoSetInitialized(bool $initialized): void;

    public function __neoSetInitializer(?Closure $initializer): void;

    public function __neoUnsetLazyProperties(): void;
}