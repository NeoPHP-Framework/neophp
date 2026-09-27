<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use NeoPHP\Component\Serializer\Contract\SerializerInterface;
use NeoPHP\Component\Serializer\Exception\SerializerException;

trait SerializerAwareTrait
{
    protected ?SerializerInterface $serializer = null;

    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    protected function serializer(): SerializerInterface
    {
        return $this->serializer ?? throw new SerializerException('The normalizer "{class}" is not attached to a serializer.', 0, null, ['class' => static::class]);
    }
}