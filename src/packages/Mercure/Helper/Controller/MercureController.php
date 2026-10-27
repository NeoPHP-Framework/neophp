<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Helper\Controller;

use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Package\Mercure\Model\Update;

trait MercureController
{
    abstract protected function get(string $id): mixed;

    protected function publishUpdate(Update $update): string
    {
        return $this->get(MercureManagerInterface::class)->publish($update);
    }

    protected function authorizeMercure(array $topics, array $payload = [], ?int $lifetime = null): string
    {
        return $this->get(MercureManagerInterface::class)->authorize($topics, $payload, $lifetime);
    }
}