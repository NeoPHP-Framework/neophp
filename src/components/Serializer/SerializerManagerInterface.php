<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer;

use NeoPHP\Component\Serializer\Exception\CircularReferenceException;
use NeoPHP\Component\Serializer\Exception\ExtraAttributesException;
use NeoPHP\Component\Serializer\Exception\MissingConstructorArgumentsException;
use NeoPHP\Component\Serializer\Exception\NotEncodableValueException;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use NeoPHP\Component\Serializer\Exception\UnexpectedValueException;
use NeoPHP\Component\Serializer\Exception\UnsupportedFormatException;

interface SerializerManagerInterface
{
    /**
     * Normalizes data into arrays and scalars, then encodes it.
     *
     * @param mixed $data The data: objects, arrays, scalars, dates, enums, ORM entities
     * @param string $format The format: json, xml, csv or yaml
     * @param array<string, mixed> $context Context options (groups, attributes, datetime_format, json_encode_options...)
     * @return string The encoded data
     * @throws UnsupportedFormatException When no encoder supports the format
     * @throws NotNormalizableValueException When a value cannot be normalized or the max depth is reached
     * @throws CircularReferenceException When a circular reference is found without handler
     * @throws NotEncodableValueException When the normalized data cannot be encoded
     */
    public function serialize(mixed $data, string $format = 'json', array $context = []): string;

    /**
     * Decodes a string, then denormalizes it into a type.
     *
     * @param string $data The encoded data
     * @param string $type The type: a class, "Class[]" for a list, a scalar type, a date or an enum
     * @param string $format The format: json, xml, csv or yaml
     * @param array<string, mixed> $context Context options (groups, object_to_populate, allow_extra_attributes...)
     * @return mixed The denormalized value
     * @throws UnsupportedFormatException When no decoder supports the format
     * @throws UnexpectedValueException When the data cannot be decoded
     * @throws NotNormalizableValueException When a value has a wrong type, a date or an enum value is invalid, or an entity does not exist
     * @throws MissingConstructorArgumentsException When required constructor arguments are missing
     * @throws ExtraAttributesException When unknown keys are given with allow_extra_attributes set to false
     */
    public function deserialize(string $data, string $type, string $format = 'json', array $context = []): mixed;

    /**
     * Normalizes data into arrays, scalars and null.
     *
     * @param mixed $data The data
     * @param string|null $format The target format, used by the normalizers
     * @param array<string, mixed> $context Context options
     * @return mixed The normalized data
     * @throws NotNormalizableValueException When a value cannot be normalized or the max depth is reached
     * @throws CircularReferenceException When a circular reference is found without handler
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed;

    /**
     * Denormalizes arrays and scalars into a type.
     *
     * @param mixed $data The normalized data
     * @param string $type The type: a class, "Class[]" for a list, a scalar type, a date or an enum
     * @param string|null $format The source format; xml, csv, form and query string data is read leniently
     * @param array<string, mixed> $context Context options
     * @return mixed The denormalized value
     * @throws NotNormalizableValueException When the type does not exist, a value has a wrong type, a date or an enum value is invalid, or an entity does not exist
     * @throws MissingConstructorArgumentsException When required constructor arguments are missing
     * @throws ExtraAttributesException When unknown keys are given with allow_extra_attributes set to false
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed;

    /**
     * Encodes normalized data.
     *
     * @param mixed $data The normalized data
     * @param string $format The format: json, xml, csv or yaml
     * @param array<string, mixed> $context Context options of the encoder
     * @return string The encoded data
     * @throws UnsupportedFormatException When no encoder supports the format
     * @throws NotEncodableValueException When the data cannot be encoded
     */
    public function encode(mixed $data, string $format, array $context = []): string;

    /**
     * Decodes a string into arrays and scalars.
     *
     * @param string $data The encoded data
     * @param string $format The format: json, xml, csv or yaml
     * @param array<string, mixed> $context Context options of the decoder
     * @return mixed The decoded data
     * @throws UnsupportedFormatException When no decoder supports the format
     * @throws UnexpectedValueException When the data cannot be decoded
     */
    public function decode(string $data, string $format, array $context = []): mixed;

    /**
     * Tells whether an encoder or a decoder supports a format.
     *
     * @param string $format The format
     * @return bool True when the format is supported
     */
    public function supportsFormat(string $format): bool;

    /**
     * Tells whether a normalizer supports a value.
     *
     * @param mixed $data The value
     * @param string|null $format The target format
     * @param array<string, mixed> $context Context options
     * @return bool True when the value can be normalized
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool;

    /**
     * Tells whether a denormalizer supports a type.
     *
     * @param mixed $data The normalized data
     * @param string $type The type
     * @param string|null $format The source format
     * @param array<string, mixed> $context Context options
     * @return bool True when the data can be denormalized into the type
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool;

    /**
     * Returns the default context, built from serializer.yaml.
     *
     * @return array<string, mixed> The context options
     */
    public function getDefaultContext(): array;
}