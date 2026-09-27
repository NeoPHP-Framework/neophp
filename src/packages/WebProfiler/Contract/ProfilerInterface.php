<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;

interface ProfilerInterface extends ProfilerElementInterface
{
    public function getPanel(Profile $profile, array $data): ?Panel;
}