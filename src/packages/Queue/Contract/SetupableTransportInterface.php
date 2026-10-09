<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Contract;

interface SetupableTransportInterface extends TransportInterface
{
    public function setup(): void;
}