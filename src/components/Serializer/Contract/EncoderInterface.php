<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface EncoderInterface
{
    public function encode(mixed $data, string $format, array $context = []): string;

    public function supportsEncoding(string $format): bool;
}