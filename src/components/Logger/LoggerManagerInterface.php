<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger;

use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\Exception\LoggerException;

interface LoggerManagerInterface extends LoggerInterface
{
    /**
     * Returns a log channel.
     *
     * @param string $name Name of the channel
     * @return LoggerInterface The logger of the channel
     * @throws LoggerException When the channel is not defined
     */
    public function channel(string $name): LoggerInterface;

    /**
     * Tells whether a log channel is defined.
     *
     * @param string $name Name of the channel
     * @return bool True when the channel is defined
     */
    public function hasChannel(string $name): bool;

    /**
     * Returns the log channels.
     *
     * @return array<string, LoggerInterface> The loggers, by channel name
     */
    public function getChannels(): array;

    /**
     * Returns the name of the default channel.
     *
     * @return string The name of the default channel
     */
    public function getDefaultChannel(): string;
}