<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

interface BlockInterface
{
    public function getType(): string;
}