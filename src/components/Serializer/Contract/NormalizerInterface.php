<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface NormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed;

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool;
}