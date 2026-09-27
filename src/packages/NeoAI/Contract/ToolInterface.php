<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Contract;

use NeoPHP\Package\NeoAI\Tool\ToolRunner;

interface ToolInterface
{
    public function getName(): string;

    public function getUsage(): string;

    public function execute(array $arguments, ToolRunner $runner): string;
}