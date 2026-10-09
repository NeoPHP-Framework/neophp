<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Contract;

interface ViewGlobalInterface extends ViewHelperInterface
{
    public function getValue(): mixed;
}