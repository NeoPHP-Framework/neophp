<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use NeoPHP\Component\Serializer\Contract\DenormalizerInterface;
use NeoPHP\Component\Serializer\Contract\SerializerAwareInterface;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use NeoPHP\Component\Serializer\SerializerManager;

class ArrayDenormalizer implements DenormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $inner = substr($type, 0, -2);
        $lenient = SerializerManager::isLenient($format, $context);

        if ($lenient && $data === '') {
            return [];
        }

        if ($lenient && (!is_array($data) || !array_is_list($data))) {
            $data = [$data];
        }

        if (!is_array($data)) {
            throw new NotNormalizableValueException('The value{at} must be a list of {type}, {actual} given.', 0, null, [
                'type' => $inner,
                'actual' => get_debug_type($data),
                'at' => SerializerManager::describePath($context),
                'path' => SerializerManager::path($context),
            ]);
        }

        unset($context[SerializerManager::OBJECT_TO_POPULATE]);
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->serializer()->denormalize($value, $inner, $format, SerializerManager::withPath($context, $key));
        }

        return $result;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return str_ends_with($type, '[]') && strlen($type) > 2;
    }
}