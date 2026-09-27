<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Package\WebProfiler\Model\Profile;

interface ToolbarAssetInterface extends ProfilerElementInterface
{
    public function getToolbarAssets(Profile $profile, array $data): array;
}