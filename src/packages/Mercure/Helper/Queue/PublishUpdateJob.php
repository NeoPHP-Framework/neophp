<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Helper\Queue;

use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Package\Mercure\Model\Update;
use NeoPHP\Package\Queue\Contract\JobInterface;

/**
 * Publishes an update from a worker of the Queue package (MercureManagerInterface::dispatch()).
 *
 * @internal
 */
class PublishUpdateJob implements JobInterface
{
    public function __construct(protected Update $update)
    {
    }

    public function getUpdate(): Update
    {
        return $this->update;
    }

    public function handle(MercureManagerInterface $mercure): void
    {
        $mercure->publish($this->update);
    }
}