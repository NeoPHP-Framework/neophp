<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger;

use NeoPHP\Component\Logger\Contract\LoggerInterface;

interface LoggerManagerInterface extends LoggerInterface
{
    public function channel(string $name): LoggerInterface;

    public function hasChannel(string $name): bool;

    public function getChannels(): array;

    public function getDefaultChannel(): string;
}