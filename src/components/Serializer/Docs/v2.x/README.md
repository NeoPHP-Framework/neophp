# Serializer

The Serializer component turns objects into JSON, XML, CSV or YAML and back: DTOs, dates, enums and ORM entities (lazy relations, proxies, circular references).
It maps request bodies and query strings to typed controller arguments (`#[MapRequestPayload]`, `#[MapQueryString]`) with validation, and powers `json()` in controllers.
No external library is used; the XML format requires `ext-dom`.

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Serializing](#serializing)
- [Deserializing](#deserializing)
- [Formats](#formats)
- [Context options](#context-options)
- [Attributes](#attributes)
- [Groups](#groups)
- [Name conversion](#name-conversion)
- [Dates and enums](#dates-and-enums)
- [Circular references and max depth](#circular-references-and-max-depth)
- [Entities (ORM)](#entities-orm)
- [Request payloads](#request-payloads)
- [Controllers](#controllers)
- [Custom normalizers](#custom-normalizers)
- [Console](#console)
- [SerializerManagerInterface reference](#serializermanagerinterface-reference)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Serializer\SerializerManager` (`final`) |
| Interface | `NeoPHP\Component\Serializer\SerializerManagerInterface` |
| Attribute | `#[Component(provider: SerializerProvider::class)]` |
| Requires | nothing |

Inject `SerializerManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Serializer\SerializerManagerInterface;

public function __construct(private SerializerManagerInterface $serializer)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\Serializer\SerializerManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Quick start

```php
use NeoPHP\Component\Serializer\Attribute\Groups;
use NeoPHP\Component\Serializer\Attribute\SerializedName;

class ProductOutput
{
    public function __construct(
        #[Groups(['read'])] public int $id,
        #[Groups(['read', 'write'])] #[SerializedName('product_name')] public string $name,
        #[Groups(['read'])] public ?DateTimeImmutable $createdAt = null,
    ) {
    }
}
```

```php
use NeoPHP\Component\Serializer\SerializerManagerInterface;

class ProductExporter
{
    public function __construct(protected SerializerManagerInterface $serializer)
    {
    }

    public function export(array $products): string
    {
        return $this->serializer->serialize($products, 'json', ['groups' => ['read']]);
    }

    public function import(string $json): array
    {
        return $this->serializer->deserialize($json, ProductOutput::class . '[]', 'json');
    }
}
```

The service is `SerializerManagerInterface` (aliases: `SerializerManager`, `serializer`). Outside of the kernel, `SerializerManager::create($config)` builds a serializer with the default normalizers and encoders.

## Configuration

`config/framework/serializer.yaml` (key `framework.serializer`, every option is optional):

```yaml
default_format: json
datetime_format: 'Y-m-d\TH:i:sP'
circular_reference_limit: 1
max_depth: 10
enable_max_depth: true
skip_null_values: false
allow_extra_attributes: true
name_converter: null
normalizers: []
```

| Option | Default | Description |
|---|---|---|
| `default_format` | `json` | format returned by `getDefaultFormat()` |
| `datetime_format` | `Y-m-d\TH:i:sP` | format of dates (RFC 3339) |
| `circular_reference_limit` | `1` | how many times an object can appear in its own branch before the circular reference handler is called |
| `max_depth` | `10` | maximum object nesting (safety limit, `0` disables it) |
| `enable_max_depth` | `true` | reads the `#[MaxDepth]` attributes |
| `skip_null_values` | `false` | omits `null` values when normalizing |
| `allow_extra_attributes` | `true` | `false` throws an `ExtraAttributesException` on unknown input keys |
| `name_converter` | `null` | `snake_case`, `camel_case`, `null` or a class implementing `NameConverterInterface` |
| `normalizers` | `[]` | extra normalizers: `[App\Serializer\MoneyNormalizer]` or `[{ class: App\Serializer\MoneyNormalizer, priority: 10 }]` |
| `default_context` | `[]` | any [context option](#context-options) applied to every call |

## Serializing

`serialize()` normalizes the data (objects to arrays and scalars) then encodes it:

```php
$json = $serializer->serialize($order, 'json');
$xml = $serializer->serialize($order, 'xml', ['xml_root_node_name' => 'order']);
$array = $serializer->normalize($order, 'json', ['groups' => ['read']]);
```

An object is normalized from:

- its public properties;
- its getters `getX()`, `isX()` and `hasX()` without required argument (the attribute is `x`), also for private properties;
- the attributes of the properties and getters (`#[Groups]`, `#[SerializedName]`, `#[Ignore]`...).

Arrays are normalized recursively (keys kept), `Traversable` objects (collections) become arrays (lists when the keys are integers), `stdClass` and `ArrayObject` become arrays, `JsonSerializable` objects are normalized from `jsonSerialize()`, dates become strings and enums their value.

## Deserializing

`deserialize()` decodes the string then denormalizes it into the given type:

```php
$order = $serializer->deserialize($json, Order::class, 'json');
$items = $serializer->deserialize($json, 'App\Dto\Item[]', 'json');
$count = $serializer->denormalize('42', 'int', 'xml');
```

| Type | Result |
|---|---|
| `App\Dto\Order` | an object |
| `App\Dto\Item[]` | a list of objects (keys kept) |
| `int`, `float`, `string`, `bool`, `array`, `mixed`, `?int`... | a scalar, checked |
| `DateTimeImmutable`, `DateTime`, `DateTimeInterface` | a date |
| an enum | a case |

An object is built:

1. with its constructor: each parameter is read from the input by name (serialized name), else its default value, else `null` when nullable; missing required parameters throw a `MissingConstructorArgumentsException`;
2. then the other input keys are written with the setters `setX()` or the public properties;
3. unknown keys are ignored (or rejected with `allow_extra_attributes: false`).

The types of the constructor parameters, setters and properties drive the recursive denormalization: nested objects, nullable types, union types (a value that already matches a member is kept, else each member is tried in order), and arrays of objects with `#[Type('App\Dto\Item[]')]`. An `array` without `#[Type]` is kept as is.

```php
class Order
{
    public function __construct(
        public int $id,
        public Customer $customer,
        #[Type('App\Dto\Item[]')] public array $items = [],
        public ?DateTimeImmutable $paidAt = null,
        public Status $status = Status::Pending,
    ) {
    }
}
```

To update an existing object instead of creating one:

```php
$serializer->deserialize($json, Order::class, 'json', ['object_to_populate' => $order]);
```

Type errors throw a `NotNormalizableValueException` (status 422) with the path of the value (`items[1].qty`). JSON is strict (`"3"` is not an `int`); XML, CSV, form and query string data is read leniently (`"3"` becomes `3`, `"1"`/`"true"` become `true`, an empty string becomes `null` for a nullable non-string type). Set `disable_type_enforcement: true` in the context to be lenient with other formats.

## Formats

| Format | Encoder | Notes |
|---|---|---|
| `json` | `JsonEncoder` | `json_encode_options` (default `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE \| JSON_PRESERVE_ZERO_FRACTION`), `json_decode_options` (default `JSON_BIGINT_AS_STRING`) |
| `xml` | `XmlEncoder` | requires `ext-dom`; `xml_root_node_name` (default `response`), `xml_encoding` (default `UTF-8`), `xml_format_output` |
| `csv` | `CsvEncoder` | `csv_delimiter` (`,`), `csv_enclosure` (`"`), `csv_headers` (forced order), `csv_key_separator` (`.`) |
| `yaml`, `yml` | `YamlEncoder` | `yaml_inline` (level from which arrays are written inline, default `4`), `yaml_indent` (default `2`); decoded with the Yaml package |

JSON:

```php
$serializer->serialize($data, 'json', ['json_encode_options' => JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES]);
```

XML:

- lists repeat the element: `['items' => [a, b]]` gives `<items>a</items><items>b</items>`, a top-level list gives `<item key="0">...</item>`;
- keys starting with `@` are attributes, the key `#` is the text of the element;
- `true`/`false` are written `1`/`0`, `null` is an empty element;
- on decoding, the root element is removed, every value is a string (read leniently), a single repeated element is not a list: `X[]` accepts it and wraps it;
- `DOCTYPE` declarations are rejected and the network is disabled (no XXE).

```php
$serializer->serialize(['@id' => 5, 'name' => 'Neo'], 'xml', ['xml_root_node_name' => 'user']);
```

```xml
<?xml version="1.0" encoding="UTF-8"?>
<user id="5"><name>Neo</name></user>
```

CSV:

- a list of rows (a single object is one row), nested arrays are flattened (`author.name`, `items.0.qty`);
- on decoding, the first line is the header, keys are unflattened and a list of rows is returned; `deserialize()` into a class (not `X[]`) takes the single row;
- every value is a string (read leniently).

YAML:

```php
$serializer->serialize($config, 'yaml', ['yaml_inline' => 2]);
```

Strings are quoted when needed (JSON-style double quotes), `null`, booleans, integers and floats (`1.0`) are written as YAML scalars.

## Context options

The keys are available as constants of `NeoPHP\Component\Serializer\SerializerManager`.

| Key | Constant | Description |
|---|---|---|
| `groups` | `GROUPS` | serialize / deserialize only the attributes of these groups (`'*'`: all) |
| `attributes` | `ATTRIBUTES` | whitelist, nested: `['id', 'author' => ['name']]` |
| `ignored_attributes` | `IGNORED_ATTRIBUTES` | property names to skip |
| `enable_max_depth` | `ENABLE_MAX_DEPTH` | reads `#[MaxDepth]` |
| `max_depth` | `MAX_DEPTH` | maximum nesting |
| `datetime_format` | `DATETIME_FORMAT` | date format |
| `datetime_timezone` | `DATETIME_TIMEZONE` | converts dates to this time zone when normalizing, and parses dates in it |
| `skip_null_values` | `SKIP_NULL_VALUES` | omits `null` values |
| `object_to_populate` | `OBJECT_TO_POPULATE` | existing object to update (top level) |
| `circular_reference_handler` | `CIRCULAR_REFERENCE_HANDLER` | `fn (object $object, ?string $format, array $context): mixed` |
| `circular_reference_limit` | `CIRCULAR_REFERENCE_LIMIT` | see [Circular references](#circular-references-and-max-depth) |
| `allow_extra_attributes` | `ALLOW_EXTRA_ATTRIBUTES` | `false`: unknown keys throw |
| `disable_type_enforcement` | `DISABLE_TYPE_ENFORCEMENT` | lenient scalar casts for every format |
| `json_encode_options` / `json_decode_options` | `JSON_ENCODE_OPTIONS` / `JSON_DECODE_OPTIONS` | flags |
| `xml_root_node_name`, `xml_encoding`, `xml_format_output` | `XML_*` | XML options |
| `csv_delimiter`, `csv_enclosure`, `csv_headers`, `csv_key_separator` | `CSV_*` | CSV options |
| `yaml_inline`, `yaml_indent` | `YAML_INLINE`, `YAML_INDENT` | YAML options |

The context given to a call is merged over the configuration (`default_context` and the options of `serializer.yaml`).

## Attributes

`NeoPHP\Component\Serializer\Attribute\*`, on properties, getters and constructor parameters:

| Attribute | Description |
|---|---|
| `#[Groups(['read', 'write'])]` | groups of the attribute; on the class: default groups of the attributes without `#[Groups]` |
| `#[SerializedName('user_name')]` | name in the serialized data (wins over the name converter) |
| `#[Ignore]` | never serialized nor deserialized |
| `#[MaxDepth(1)]` | how many times the attribute is followed in a branch (with `enable_max_depth`) |
| `#[Context(normalization: [...], denormalization: [...], context: [...], groups: [...])]` | context options for this attribute only, optionally for some groups; repeatable |
| `#[Type('App\Dto\Item[]')]` | type used to deserialize the attribute (element type of arrays) |

```php
class Event
{
    #[Context(normalization: ['datetime_format' => 'Y-m-d'], denormalization: ['datetime_format' => 'Y-m-d'])]
    public ?DateTimeImmutable $day = null;

    #[Ignore]
    public string $internalNote = '';

    #[Type('App\Dto\Speaker[]')]
    public array $speakers = [];
}
```

## Groups

```php
#[Groups(['read'])]
class Article
{
    public int $id = 0;

    #[Groups(['read', 'write'])]
    public string $title = '';

    #[Groups(['admin'])]
    public ?string $notes = null;
}

$serializer->normalize($article, 'json', ['groups' => ['read']]);
$serializer->denormalize($data, Article::class, 'json', ['groups' => ['write']]);
```

- Without `groups` in the context, every attribute is used.
- With `groups`, only the attributes of at least one of the groups are used; the attributes without group are skipped.
- On deserialization, the keys of the other attributes are ignored (or rejected with `allow_extra_attributes: false`).

## Name conversion

`name_converter: snake_case` converts `createdAt` into `created_at` (and back when deserializing). `camel_case` does the opposite (PHP properties in snake case, data in camel case). `#[SerializedName]` always wins. A custom converter implements `NeoPHP\Component\Serializer\Contract\NameConverterInterface` (`normalize(string $propertyName): string`, `denormalize(string $propertyName): string`).

## Dates and enums

- `DateTimeInterface` values are formatted with `datetime_format`; on deserialization the format is tried first (time fields reset: `Y-m-d` gives midnight), then any format understood by `new DateTimeImmutable()`; an integer is a timestamp. `DateTimeInterface` types give a `DateTimeImmutable`.
- A backed enum is written with its value (`tryFrom()` on the way back, the allowed values are listed in the error), a pure enum with its case name.

## Circular references and max depth

An object that appears again in its own branch (a post, its comments, their post...) is a circular reference. With the default `circular_reference_limit: 1`:

- for an entity, its identifier is written;
- for another object, the `circular_reference_handler` of the context is called, or a `CircularReferenceException` is thrown.

```php
$serializer->serialize($category, 'json', [
    'circular_reference_handler' => fn (object $object): string => $object->getName(),
]);
```

`#[MaxDepth(n)]` limits how many times an attribute is followed in a branch (`Category::$parent` with `#[MaxDepth(1)]`: the parent is written, not the parent of the parent). Beyond the limit, the attribute is skipped (for an entity relation: its identifier).

`max_depth` (default `10`) is a safety limit on the object nesting: beyond it, an entity is written as its identifier and another object throws a `NotNormalizableValueException`.

## Entities (ORM)

Entities (`#[ORM\Entity]`) are handled by `EntityNormalizer` when the ORM package is available:

- proxies are initialized and read with the metadata of the real class (`ClassResolver::getRealClass()`, never `$entity::class`);
- a to-one relation is written as a nested object; it is written as its identifier when the related entity has no attribute in the requested `groups`, when a circular reference is found or when the max depth is reached;
- a to-many collection is written as a list (of objects, or of identifiers in the same cases);
- a circular reference is written as the identifier.

```php
#[ORM\Entity]
class Post
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['read'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Groups(['read', 'write'])]
    private ?string $title = null;

    #[ORM\ManyToOne(User::class)]
    #[Groups(['read', 'write'])]
    private ?User $author = null;

    #[ORM\ManyToMany(Tag::class)]
    #[Groups(['read', 'write'])]
    private CollectionInterface $tags;
}

$this->json($post, 200, [], ['groups' => ['read']]);
```

```json
{"id": 1, "title": "Hello", "author": {"id": 3, "displayName": "Ada"}, "tags": [1, 4]}
```

(`User` has attributes in the `read` group, `Tag` has none.)

Deserializing into an entity:

- `object_to_populate` updates a managed entity (call `flush()` afterwards);
- a relation given as an identifier (`"author": 3`) or as `{"id": 3}` is loaded with `find()` of the ORM; an array without identifier creates a new entity;
- a to-many relation takes a list of identifiers or objects: the collection is updated in place (elements removed, added);
- an unknown identifier throws a `NotNormalizableValueException` (422): `The Category "999" at "category" does not exist.`

```php
$post = $this->deserialize($request->getContent(), Post::class, 'json', [
    'groups' => ['write'],
    'object_to_populate' => $post,
]);
$this->getOrm()->flush();
```

## Request payloads

`#[MapRequestPayload]` maps the request body to a typed controller argument, `#[MapQueryString]` maps the query string:

```php
use NeoPHP\Component\Serializer\Attribute\MapQueryString;
use NeoPHP\Component\Serializer\Attribute\MapRequestPayload;
use NeoPHP\Component\Serializer\Attribute\Type;

#[Route('/api/products', methods: ['POST'])]
public function create(#[MapRequestPayload] ProductInput $input): JsonResponse
{
}

#[Route('/api/products/import', methods: ['POST'])]
public function import(#[MapRequestPayload(acceptFormats: ['json'])] #[Type('App\Dto\ProductInput[]')] array $inputs): JsonResponse
{
}

#[Route('/api/products', methods: ['GET'])]
public function search(#[MapQueryString] ?ProductSearch $search): JsonResponse
{
}
```

| Option | `MapRequestPayload` | `MapQueryString` | Description |
|---|---|---|---|
| `groups` | yes | yes | serialization groups used to deserialize |
| `validationGroups` | yes | yes | validation groups (default `['Default']`) |
| `format` | yes | | forces the format instead of reading `Content-Type` |
| `acceptFormats` | yes | | allowed formats, e.g. `['json']` |
| `context` | yes | yes | extra context options |
| `validate` | yes | yes | `false` skips the validation |

1. The format comes from `Content-Type`: `application/json` and `*+json` (json), `application/xml`, `text/xml` and `*+xml` (xml), `text/csv` (csv), `application/yaml`, `application/x-yaml`, `text/yaml` (yaml), `application/x-www-form-urlencoded` and `multipart/form-data` (form: the request fields and files).
2. The body is decoded and denormalized into the type of the argument (a class, or an array with `#[Type('X[]')]`).
3. The object is validated with the Validator component (`#[Assert\...]` constraints).

| Case | Response |
|---|---|
| valid data | the argument is the object |
| empty body / empty query string | `null` when the argument is nullable, else its default value, else the object is built from `[]` (usually a 422) |
| unsupported or missing `Content-Type`, format not in `acceptFormats` | 415 `HttpException` |
| malformed body (invalid JSON, XML...) | 400 `BadRequestHttpException` |
| type error, missing constructor argument, extra attribute (`allow_extra_attributes: false`), unknown entity id | 422 `ValidationFailedException` with the path of the value |
| validation errors | 422 `ValidationFailedException` |

A `ValidationFailedException` is rendered as JSON by the Validator component when the request is JSON or asks for JSON (`Accept: application/json`):

```json
{"error": {"status": 422, "message": "The data is not valid: 2 violation(s).", "violations": {"title": ["This value is too short. It should have 3 character(s) or more."], "quantity": ["This value should be positive."]}}}
```

Lists are validated item by item (paths `[1].title`). The resolution is done by `NeoPHP\Component\Serializer\ArgumentResolver\RequestPayloadResolver`, an argument resolver of the Controller component (see the Controller documentation).

## Controllers

The trait `SerializerController` of `AbstractController` provides:

```php
$yaml = $this->serialize($report, 'yaml');
$input = $this->deserialize($request->getContent(), ProductInput::class, 'json');
```

`json()` (Http component) normalizes the data with the Serializer when it contains objects or when a context is given:

```php
return $this->json($post, 200, [], ['groups' => ['read']]);
return $this->json(['items' => $products, 'total' => $total]);
```

## Custom normalizers

A normalizer implements `NormalizerInterface` (`normalize()`, `supportsNormalization()`) and / or `DenormalizerInterface` (`denormalize()`, `supportsDenormalization()`). To call the serializer for nested values, implement `SerializerAwareInterface` (the trait `NeoPHP\Component\Serializer\Normalizer\SerializerAwareTrait` provides `setSerializer()` and `serializer()`).

```php
<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Model\Money;
use NeoPHP\Component\Serializer\Attribute\AsNormalizer;
use NeoPHP\Component\Serializer\Contract\DenormalizerInterface;
use NeoPHP\Component\Serializer\Contract\NormalizerInterface;

#[AsNormalizer(priority: 10)]
class MoneyNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed
    {
        return sprintf('%.2f %s', $data->cents / 100, $data->currency);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Money;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        [$amount, $currency] = explode(' ', (string) $data);

        return new Money((int) round((float) $amount * 100), $currency);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Money::class && is_string($data);
    }
}
```

- `#[AsNormalizer(priority: 0)]` classes of `src/` are discovered (cached in `var/cache/serializer/`, refreshed in debug), like `#[AsListener]` listeners.
- Or list them in `config/framework/serializer.yaml` (`normalizers: [App\Serializer\MoneyNormalizer]`, or `{ class: ..., priority: 10 }`).
- They are built by the container (constructor autowiring). The highest priority is asked first; the built-in normalizers have negative priorities:

| Normalizer | Priority | Handles |
|---|---|---|
| `DateTimeNormalizer` | -800 | `DateTimeInterface` |
| `BackedEnumNormalizer` | -800 | backed and pure enums |
| `JsonSerializableNormalizer` | -900 | `JsonSerializable` (normalization only) |
| `ArrayDenormalizer` | -900 | `X[]` types (denormalization only) |
| `EntityNormalizer` | -950 | ORM entities |
| `ObjectNormalizer` | -1000 | any other object |

In code: `$serializer->addNormalizer($normalizer, $priority)` and `$serializer->addEncoder($encoder)` (an `EncoderInterface` / `DecoderInterface`; the last added encoder wins for its format).

## Console

```bash
php bin/neo serializer:debug "App\Entity\Post"
```

Displays, for each attribute of the class: name, serialized name, groups, type, access (`r` readable, `w` writable), ignored, max depth and context.

## SerializerManagerInterface reference

`NeoPHP\Component\Serializer\SerializerManagerInterface`:

| Method | Description |
|---|---|
| `serialize(mixed $data, string $format = 'json', array $context = []): string` | normalize and encode |
| `deserialize(string $data, string $type, string $format = 'json', array $context = []): mixed` | decode and denormalize |
| `normalize(mixed $data, ?string $format = null, array $context = []): mixed` | to arrays / scalars / `null` |
| `denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed` | from arrays / scalars |
| `encode(mixed $data, string $format, array $context = []): string` | encode normalized data |
| `decode(string $data, string $format, array $context = []): mixed` | decode a string |
| `supportsFormat(string $format): bool` | an encoder or decoder handles the format |
| `supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool` | a normalizer handles the value |
| `supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool` | a denormalizer handles the type |
| `getDefaultContext(): array` | the configured context |

`SerializerManager` also provides `create(array $config = [], ?Closure $orm = null, ?YamlManagerInterface $yaml = null)`, `addNormalizer()`, `addEncoder()`, `getNormalizers()`, `getEncoders()`, `getFormats()`, `getDefaultFormat()`, `getMetadataFactory()` and `getNameConverter()`.

Exceptions (`NeoPHP\Component\Serializer\Exception\*`, all extend `SerializerException`, a `FrameworkException`):

| Exception | Status | Thrown when |
|---|---|---|
| `UnsupportedFormatException` | 500 | no encoder / decoder for the format |
| `NotEncodableValueException` | 500 | the data cannot be encoded |
| `UnexpectedValueException` | 400 | the input cannot be decoded (malformed) |
| `NotNormalizableValueException` | 422 | a value has a wrong type, an enum value or a date is invalid, an entity does not exist (`getPath()`) |
| `MissingConstructorArgumentsException` | 422 | required constructor parameters are missing (`getMissingArguments()`) |
| `ExtraAttributesException` | 422 | unknown keys with `allow_extra_attributes: false` (`getExtraAttributes()`) |
| `CircularReferenceException` | 500 | circular reference without handler (non-entity objects) |

## Changelog

- v2.0.0 — `SerializerManager` is the `final` entry point of the module, declared with `#[Component]`; `SerializerManagerInterface` replaces `Contract\SerializerInterface`; `Contract\AbstractSerializer` is merged into the manager; the internal classes are marked `@internal`.
- v1.23.0 — Serializer component: JSON / XML / CSV / YAML encoders, object, date, enum, `JsonSerializable`, array and ORM entity normalizers, attributes `#[Groups]`, `#[SerializedName]`, `#[Ignore]`, `#[MaxDepth]`, `#[Context]`, `#[Type]`, name converters, circular reference and max depth handling, `#[MapRequestPayload]` / `#[MapQueryString]` controller arguments with validation (400 / 415 / 422), custom normalizers (`#[AsNormalizer]` or configuration), `serialize()` / `deserialize()` in controllers, `json()` with a serializer context, `serializer:debug`.