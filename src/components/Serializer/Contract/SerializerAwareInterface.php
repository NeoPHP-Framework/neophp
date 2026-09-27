<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface SerializerAwareInterface
{
    public function setSerializer(SerializerInterface $serializer): void;
}