<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Encoder;

use JsonException;
use NeoPHP\Component\Serializer\Contract\DecoderInterface;
use NeoPHP\Component\Serializer\Contract\EncoderInterface;
use NeoPHP\Component\Serializer\Exception\NotEncodableValueException;
use NeoPHP\Component\Serializer\Exception\UnexpectedValueException;
use NeoPHP\Component\Serializer\SerializerManager;

class JsonEncoder implements EncoderInterface, DecoderInterface
{
    public const FORMAT = 'json';

    public const DEFAULT_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function encode(mixed $data, string $format, array $context = []): string
    {
        try {
            return json_encode($data, ((int) ($context[SerializerManager::JSON_ENCODE_OPTIONS] ?? self::DEFAULT_OPTIONS)) | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new NotEncodableValueException('Unable to encode the data as JSON: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }

    public function decode(string $data, string $format, array $context = []): mixed
    {
        if (trim($data) === '') {
            throw new UnexpectedValueException('Unable to decode JSON: the input is empty.');
        }

        try {
            return json_decode($data, true, 512, ((int) ($context[SerializerManager::JSON_DECODE_OPTIONS] ?? JSON_BIGINT_AS_STRING)) | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Unable to decode JSON: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }

    public function supportsEncoding(string $format): bool
    {
        return $format === self::FORMAT;
    }

    public function supportsDecoding(string $format): bool
    {
        return $format === self::FORMAT;
    }
}