<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Helper\Controller;

use NeoPHP\Component\Serializer\SerializerManagerInterface;

trait SerializerController
{
    abstract protected function get(string $id): mixed;

    protected function serialize(mixed $data, string $format = 'json', array $context = []): string
    {
        return $this->get(SerializerManagerInterface::class)->serialize($data, $format, $context);
    }

    protected function deserialize(string $data, string $type, string $format = 'json', array $context = []): mixed
    {
        return $this->get(SerializerManagerInterface::class)->deserialize($data, $type, $format, $context);
    }
}