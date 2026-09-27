<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface SerializerInterface
{
    public function serialize(mixed $data, string $format = 'json', array $context = []): string;

    public function deserialize(string $data, string $type, string $format = 'json', array $context = []): mixed;

    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed;

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed;

    public function encode(mixed $data, string $format, array $context = []): string;

    public function decode(string $data, string $format, array $context = []): mixed;

    public function supportsFormat(string $format): bool;

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool;

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool;

    public function getDefaultContext(): array;
}