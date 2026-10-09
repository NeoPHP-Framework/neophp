<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

use NeoPHP\Component\Serializer\SerializerManagerInterface;

interface SerializerAwareInterface
{
    public function setSerializer(SerializerManagerInterface $serializer): void;
}