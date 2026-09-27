<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer;

use Closure;
use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\NameConverterInterface;
use NeoPHP\Component\Serializer\Encoder\CsvEncoder;
use NeoPHP\Component\Serializer\Encoder\JsonEncoder;
use NeoPHP\Component\Serializer\Encoder\XmlEncoder;
use NeoPHP\Component\Serializer\Encoder\YamlEncoder;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use NeoPHP\Component\Serializer\NameConverter\SnakeCaseToCamelCaseNameConverter;
use NeoPHP\Component\Serializer\Normalizer\ArrayDenormalizer;
use NeoPHP\Component\Serializer\Normalizer\BackedEnumNormalizer;
use NeoPHP\Component\Serializer\Normalizer\DateTimeNormalizer;
use NeoPHP\Component\Serializer\Normalizer\EntityNormalizer;
use NeoPHP\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use NeoPHP\Component\Serializer\Normalizer\ObjectNormalizer;
use NeoPHP\Package\Yaml\Contract\YamlInterface;

class SerializerManager extends AbstractSerializer
{
    public const PRIORITY_DATETIME = -800;
    public const PRIORITY_ENUM = -800;
    public const PRIORITY_JSON_SERIALIZABLE = -900;
    public const PRIORITY_ARRAY = -900;
    public const PRIORITY_ENTITY = -950;
    public const PRIORITY_OBJECT = -1000;

    public const CONTEXT_OPTIONS = [
        'datetime_format' => self::DATETIME_FORMAT,
        'circular_reference_limit' => self::CIRCULAR_REFERENCE_LIMIT,
        'max_depth' => self::MAX_DEPTH,
        'enable_max_depth' => self::ENABLE_MAX_DEPTH,
        'skip_null_values' => self::SKIP_NULL_VALUES,
        'allow_extra_attributes' => self::ALLOW_EXTRA_ATTRIBUTES,
    ];

    protected MetadataFactory $metadataFactory;

    protected ?NameConverterInterface $nameConverter;

    public function __construct(array $normalizers = [], array $encoders = [], array $defaultContext = [], string $defaultFormat = 'json', ?MetadataFactory $metadataFactory = null, ?NameConverterInterface $nameConverter = null)
    {
        $this->defaultContext = array_replace(self::DEFAULT_CONTEXT, $defaultContext);
        $this->defaultFormat = $defaultFormat;
        $this->metadataFactory = $metadataFactory ?? new MetadataFactory();
        $this->nameConverter = $nameConverter;

        foreach ($normalizers as $normalizer) {
            is_array($normalizer) ? $this->addNormalizer($normalizer[0], (int) ($normalizer[1] ?? 0)) : $this->addNormalizer($normalizer);
        }

        foreach ($encoders as $encoder) {
            $this->addEncoder($encoder);
        }
    }

    public static function create(array $config = [], ?Closure $orm = null, ?YamlInterface $yaml = null): static
    {
        $nameConverter = self::createNameConverter($config['name_converter'] ?? null);
        $metadata = new MetadataFactory();
        $defaultContext = (array) ($config['default_context'] ?? []);

        foreach (self::CONTEXT_OPTIONS as $option => $key) {
            if (array_key_exists($option, $config)) {
                $defaultContext[$key] = $config[$option];
            }
        }

        $serializer = new static([], [], $defaultContext, (string) ($config['default_format'] ?? 'json'), $metadata, $nameConverter);
        $serializer->addNormalizer(new ObjectNormalizer($metadata, $nameConverter), self::PRIORITY_OBJECT);

        if ($orm !== null) {
            $serializer->addNormalizer(new EntityNormalizer($orm, $metadata, $nameConverter), self::PRIORITY_ENTITY);
        }

        $serializer->addNormalizer(new ArrayDenormalizer(), self::PRIORITY_ARRAY);
        $serializer->addNormalizer(new JsonSerializableNormalizer(), self::PRIORITY_JSON_SERIALIZABLE);
        $serializer->addNormalizer(new BackedEnumNormalizer(), self::PRIORITY_ENUM);
        $serializer->addNormalizer(new DateTimeNormalizer(), self::PRIORITY_DATETIME);

        $serializer->addEncoder(new YamlEncoder($yaml));
        $serializer->addEncoder(new CsvEncoder());
        $serializer->addEncoder(new XmlEncoder());
        $serializer->addEncoder(new JsonEncoder());

        return $serializer;
    }

    public static function createNameConverter(mixed $nameConverter): ?NameConverterInterface
    {
        return match (true) {
            $nameConverter === null, $nameConverter === '', $nameConverter === false, $nameConverter === 'null', $nameConverter === 'none' => null,
            $nameConverter instanceof NameConverterInterface => $nameConverter,
            $nameConverter === 'snake_case' => new CamelCaseToSnakeCaseNameConverter(),
            $nameConverter === 'camel_case' => new SnakeCaseToCamelCaseNameConverter(),
            is_string($nameConverter) && is_subclass_of($nameConverter, NameConverterInterface::class) => new $nameConverter(),
            default => throw new SerializerException('Unknown name converter "{converter}": use snake_case, camel_case, null or a class implementing {interface}.', 0, null, [
                'converter' => is_string($nameConverter) ? $nameConverter : get_debug_type($nameConverter),
                'interface' => NameConverterInterface::class,
            ]),
        };
    }

    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadataFactory;
    }

    public function getNameConverter(): ?NameConverterInterface
    {
        return $this->nameConverter;
    }
}