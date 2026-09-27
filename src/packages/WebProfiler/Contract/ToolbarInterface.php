<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;

interface ToolbarInterface extends ProfilerElementInterface
{
    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem;
}