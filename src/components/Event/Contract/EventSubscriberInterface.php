<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Contract;

interface EventSubscriberInterface
{
    public static function getSubscribedEvents(): array;
}