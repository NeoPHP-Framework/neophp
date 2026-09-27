<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed;

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool;
}