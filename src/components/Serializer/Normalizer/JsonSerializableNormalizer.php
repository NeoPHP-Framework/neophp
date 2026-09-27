<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use JsonSerializable;
use NeoPHP\Component\Serializer\Contract\NormalizerInterface;
use NeoPHP\Component\Serializer\Contract\SerializerAwareInterface;

class JsonSerializableNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed
    {
        return $this->serializer()->normalize($data->jsonSerialize(), $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof JsonSerializable;
    }
}