<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

use ArrayObject;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\Exception\UnsupportedFormatException;
use stdClass;
use Traversable;
use UnitEnum;

abstract class AbstractSerializer implements SerializerInterface
{
    public const GROUPS = 'groups';
    public const ATTRIBUTES = 'attributes';
    public const IGNORED_ATTRIBUTES = 'ignored_attributes';
    public const ENABLE_MAX_DEPTH = 'enable_max_depth';
    public const MAX_DEPTH = 'max_depth';
    public const DATETIME_FORMAT = 'datetime_format';
    public const DATETIME_TIMEZONE = 'datetime_timezone';
    public const SKIP_NULL_VALUES = 'skip_null_values';
    public const OBJECT_TO_POPULATE = 'object_to_populate';
    public const CIRCULAR_REFERENCE_HANDLER = 'circular_reference_handler';
    public const CIRCULAR_REFERENCE_LIMIT = 'circular_reference_limit';
    public const ALLOW_EXTRA_ATTRIBUTES = 'allow_extra_attributes';
    public const DISABLE_TYPE_ENFORCEMENT = 'disable_type_enforcement';
    public const JSON_ENCODE_OPTIONS = 'json_encode_options';
    public const JSON_DECODE_OPTIONS = 'json_decode_options';
    public const XML_ROOT_NODE_NAME = 'xml_root_node_name';
    public const XML_ENCODING = 'xml_encoding';
    public const XML_FORMAT_OUTPUT = 'xml_format_output';
    public const CSV_DELIMITER = 'csv_delimiter';
    public const CSV_ENCLOSURE = 'csv_enclosure';
    public const CSV_HEADERS = 'csv_headers';
    public const CSV_KEY_SEPARATOR = 'csv_key_separator';
    public const YAML_INLINE = 'yaml_inline';
    public const YAML_INDENT = 'yaml_indent';

    public const DEPTH = '_serializer_depth';
    public const CIRCULAR_COUNTERS = '_serializer_circular';
    public const MAX_DEPTH_COUNTERS = '_serializer_max_depth';
    public const PATH = '_serializer_path';

    public const LENIENT_FORMATS = ['xml', 'csv', 'form', 'query'];

    public const BUILTIN_TYPES = ['mixed', 'int', 'float', 'string', 'bool', 'array', 'iterable', 'null', 'true', 'false', 'object'];

    public const DEFAULT_CONTEXT = [
        self::DATETIME_FORMAT => 'Y-m-d\TH:i:sP',
        self::CIRCULAR_REFERENCE_LIMIT => 1,
        self::MAX_DEPTH => 10,
        self::ENABLE_MAX_DEPTH => true,
        self::SKIP_NULL_VALUES => false,
        self::ALLOW_EXTRA_ATTRIBUTES => true,
    ];

    protected array $normalizers = [];

    protected array $encoders = [];

    protected array $defaultContext = self::DEFAULT_CONTEXT;

    protected string $defaultFormat = 'json';

    protected ?array $sorted = null;

    public function addNormalizer(object $normalizer, int $priority = 0): static
    {
        if (!$normalizer instanceof NormalizerInterface && !$normalizer instanceof DenormalizerInterface) {
            throw new SerializerException('The normalizer "{class}" must implement {normalizer} or {denormalizer}.', 0, null, [
                'class' => $normalizer::class,
                'normalizer' => NormalizerInterface::class,
                'denormalizer' => DenormalizerInterface::class,
            ]);
        }

        if ($normalizer instanceof SerializerAwareInterface) {
            $normalizer->setSerializer($this);
        }

        $this->normalizers[] = ['normalizer' => $normalizer, 'priority' => $priority, 'index' => count($this->normalizers)];
        $this->sorted = null;

        return $this;
    }

    public function addEncoder(object $encoder): static
    {
        if (!$encoder instanceof EncoderInterface && !$encoder instanceof DecoderInterface) {
            throw new SerializerException('The encoder "{class}" must implement {encoder} or {decoder}.', 0, null, [
                'class' => $encoder::class,
                'encoder' => EncoderInterface::class,
                'decoder' => DecoderInterface::class,
            ]);
        }

        array_unshift($this->encoders, $encoder);

        return $this;
    }

    public function getNormalizers(): array
    {
        if ($this->sorted === null) {
            $normalizers = $this->normalizers;
            usort($normalizers, static fn (array $a, array $b): int => [$b['priority'], $a['index']] <=> [$a['priority'], $b['index']]);
            $this->sorted = $normalizers;
        }

        return $this->sorted;
    }

    public function getEncoders(): array
    {
        return $this->encoders;
    }

    public function getDefaultContext(): array
    {
        return $this->defaultContext;
    }

    public function getDefaultFormat(): string
    {
        return $this->defaultFormat;
    }

    public function serialize(mixed $data, string $format = 'json', array $context = []): string
    {
        $context = $this->context($context);

        return $this->encode($this->normalize($data, $format, $context), $format, $context);
    }

    public function deserialize(string $data, string $type, string $format = 'json', array $context = []): mixed
    {
        $context = $this->context($context);
        $decoded = $this->decode($data, $format, $context);

        if ($format === 'csv' && !str_ends_with($type, '[]') && is_array($decoded) && array_is_list($decoded) && count($decoded) === 1 && is_array($decoded[0])) {
            $decoded = $decoded[0];
        }

        return $this->denormalize($decoded, $type, $format, $context);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed
    {
        if ($data === null || is_scalar($data)) {
            return $data;
        }

        $context = $this->context($context);

        if (is_array($data)) {
            $normalized = [];

            foreach ($data as $key => $value) {
                $normalized[$key] = $value === null || is_scalar($value) ? $value : $this->normalize($value, $format, self::withPath($context, $key));
            }

            return $normalized;
        }

        foreach ($this->getNormalizers() as ['normalizer' => $normalizer]) {
            if ($normalizer instanceof NormalizerInterface && $normalizer->supportsNormalization($data, $format, $context)) {
                return $normalizer->normalize($data, $format, $context);
            }
        }

        if ($data instanceof stdClass || $data instanceof ArrayObject) {
            return $this->normalize((array) $data, $format, $context);
        }

        if ($data instanceof Traversable) {
            $items = iterator_to_array($data, true);

            if ($items !== [] && array_filter(array_keys($items), 'is_int') === array_keys($items)) {
                $items = array_values($items);
            }

            return $this->normalize($items, $format, $context);
        }

        throw new NotNormalizableValueException('Unable to normalize a value of type "{type}"{at}: no normalizer supports it.', 0, null, [
            'type' => get_debug_type($data),
            'at' => self::describePath($context),
            'path' => self::path($context),
        ]);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $type = ltrim(trim($type), '\\');
        $context = $this->context($context);

        if (str_starts_with($type, '?')) {
            if ($data === null || ($data === '' && self::isLenient($format, $context))) {
                return null;
            }

            $type = substr($type, 1);
        }

        if (in_array(strtolower($type), self::BUILTIN_TYPES, true)) {
            return $this->denormalizeBuiltin($data, strtolower($type), $format, $context);
        }

        foreach ($this->getNormalizers() as ['normalizer' => $normalizer]) {
            if ($normalizer instanceof DenormalizerInterface && $normalizer->supportsDenormalization($data, $type, $format, $context)) {
                return $normalizer->denormalize($data, $type, $format, $context);
            }
        }

        if (!str_ends_with($type, '[]') && !class_exists($type) && !interface_exists($type) && !enum_exists($type)) {
            throw new NotNormalizableValueException('Unable to denormalize{at}: the type "{type}" does not exist.', 0, null, [
                'type' => $type,
                'at' => self::describePath($context),
                'path' => self::path($context),
            ]);
        }

        throw new NotNormalizableValueException('Unable to denormalize a value of type "{actual}" into "{type}"{at}: no denormalizer supports it.', 0, null, [
            'type' => $type,
            'actual' => get_debug_type($data),
            'at' => self::describePath($context),
            'path' => self::path($context),
        ]);
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        $context = $this->context($context);

        foreach ($this->encoders as $encoder) {
            if ($encoder instanceof EncoderInterface && $encoder->supportsEncoding($format)) {
                return $encoder->encode($data, $format, $context);
            }
        }

        throw new UnsupportedFormatException('The format "{format}" is not supported for encoding (supported: {formats}).', 0, null, ['format' => $format, 'formats' => implode(', ', $this->getFormats())]);
    }

    public function decode(string $data, string $format, array $context = []): mixed
    {
        $context = $this->context($context);

        foreach ($this->encoders as $encoder) {
            if ($encoder instanceof DecoderInterface && $encoder->supportsDecoding($format)) {
                return $encoder->decode($data, $format, $context);
            }
        }

        throw new UnsupportedFormatException('The format "{format}" is not supported for decoding (supported: {formats}).', 0, null, ['format' => $format, 'formats' => implode(', ', $this->getFormats())]);
    }

    public function supportsFormat(string $format): bool
    {
        foreach ($this->encoders as $encoder) {
            if (($encoder instanceof EncoderInterface && $encoder->supportsEncoding($format)) || ($encoder instanceof DecoderInterface && $encoder->supportsDecoding($format))) {
                return true;
            }
        }

        return false;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if ($data === null || is_scalar($data) || is_array($data) || $data instanceof stdClass || $data instanceof Traversable) {
            return true;
        }

        foreach ($this->getNormalizers() as ['normalizer' => $normalizer]) {
            if ($normalizer instanceof NormalizerInterface && $normalizer->supportsNormalization($data, $format, $context)) {
                return true;
            }
        }

        return false;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        $type = ltrim(ltrim(trim($type), '?'), '\\');

        if (in_array(strtolower($type), self::BUILTIN_TYPES, true)) {
            return true;
        }

        foreach ($this->getNormalizers() as ['normalizer' => $normalizer]) {
            if ($normalizer instanceof DenormalizerInterface && $normalizer->supportsDenormalization($data, $type, $format, $context)) {
                return true;
            }
        }

        return false;
    }

    public function getFormats(): array
    {
        $formats = [];

        foreach ($this->encoders as $encoder) {
            foreach (['json', 'xml', 'csv', 'yaml', 'yml'] as $format) {
                if (($encoder instanceof EncoderInterface && $encoder->supportsEncoding($format)) || ($encoder instanceof DecoderInterface && $encoder->supportsDecoding($format))) {
                    $formats[] = $format;
                }
            }
        }

        return array_values(array_unique($formats));
    }

    public static function withPath(array $context, string|int $key): array
    {
        $path = (string) ($context[self::PATH] ?? '');
        $context[self::PATH] = is_int($key) ? $path . '[' . $key . ']' : ($path === '' ? $key : $path . '.' . $key);

        return $context;
    }

    public static function path(array $context): string
    {
        return (string) ($context[self::PATH] ?? '');
    }

    public static function describePath(array $context): string
    {
        $path = self::path($context);

        return $path === '' ? '' : ' at "' . $path . '"';
    }

    public static function isLenient(?string $format, array $context): bool
    {
        return (bool) ($context[self::DISABLE_TYPE_ENFORCEMENT] ?? false) || in_array($format, self::LENIENT_FORMATS, true);
    }

    protected function context(array $context): array
    {
        return isset($context[self::CIRCULAR_REFERENCE_LIMIT], $context[self::MAX_DEPTH], $context[self::DATETIME_FORMAT]) ? $context : array_replace($this->defaultContext, $context);
    }

    protected function denormalizeBuiltin(mixed $data, string $type, ?string $format, array $context): mixed
    {
        $lenient = self::isLenient($format, $context);

        $value = match ($type) {
            'mixed' => $data,
            'null' => $data === null || ($lenient && $data === '') ? null : $this,
            'int' => match (true) {
                is_int($data) => $data,
                is_float($data) && $lenient && floor($data) === $data => (int) $data,
                is_string($data) && $lenient && preg_match('/^[-+]?\d+$/', trim($data)) === 1 => (int) trim($data),
                default => $this,
            },
            'float' => match (true) {
                is_int($data), is_float($data) => (float) $data,
                is_string($data) && $lenient && is_numeric(trim($data)) => (float) trim($data),
                default => $this,
            },
            'string' => match (true) {
                is_string($data) => $data,
                $lenient && (is_int($data) || is_float($data)) => (string) $data,
                $data instanceof UnitEnum => $this,
                default => $this,
            },
            'bool', 'true', 'false' => match (true) {
                is_bool($data) => $data,
                $lenient && in_array(is_string($data) ? strtolower(trim($data)) : $data, ['1', 'true', 'on', 'yes', 1], true) => true,
                $lenient && in_array(is_string($data) ? strtolower(trim($data)) : $data, ['0', 'false', 'off', 'no', '', 0], true) => false,
                default => $this,
            },
            'array', 'iterable' => match (true) {
                is_array($data) => $data,
                $lenient && $data === '' => [],
                default => $this,
            },
            'object' => is_object($data) ? $data : (is_array($data) ? (object) $data : $this),
            default => $this,
        };

        if ($value === $this || ($type === 'true' && $value !== true) || ($type === 'false' && $value !== false)) {
            throw new NotNormalizableValueException('The value{at} must be of type {type}, {actual} given.', 0, null, [
                'type' => $type,
                'actual' => get_debug_type($data),
                'at' => self::describePath($context),
                'path' => self::path($context),
            ]);
        }

        return $value;
    }
}