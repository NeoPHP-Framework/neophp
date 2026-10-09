<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\SerializerManagerInterface;

trait SerializerAwareTrait
{
    protected ?SerializerManagerInterface $serializer = null;

    public function setSerializer(SerializerManagerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    protected function serializer(): SerializerManagerInterface
    {
        return $this->serializer ?? throw new SerializerException('The normalizer "{class}" is not attached to a serializer.', 0, null, ['class' => static::class]);
    }
}