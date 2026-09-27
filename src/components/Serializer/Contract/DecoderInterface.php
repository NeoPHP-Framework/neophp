<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface DecoderInterface
{
    public function decode(string $data, string $format, array $context = []): mixed;

    public function supportsDecoding(string $format): bool;
}