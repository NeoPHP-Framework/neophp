<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi;

use NeoPHP\Component\Api\Attribute\MapPagination;
use NeoPHP\Component\Api\Attribute\RateLimit;
use NeoPHP\Component\Api\Controller\OpenApiController;
use NeoPHP\Component\Api\OpenApi\Attribute\Operation;
use NeoPHP\Component\Api\OpenApi\Attribute\Parameter;
use NeoPHP\Component\Api\OpenApi\Attribute\Response as ResponseAttribute;
use NeoPHP\Component\Api\OpenApi\Attribute\Tag;
use NeoPHP\Component\Api\Pagination\Page;
use NeoPHP\Component\Api\Pagination\PageRequest;
use NeoPHP\Component\Api\Pagination\Paginator;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetails;
use NeoPHP\Component\Api\Reflection\ControllerReflector;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Component\Serializer\ArgumentResolver\RequestPayloadResolver;
use NeoPHP\Component\Serializer\Attribute\MapQueryString;
use NeoPHP\Component\Serializer\Attribute\MapRequestPayload;
use NeoPHP\Component\Serializer\Attribute\Type;
use NeoPHP\Component\Serializer\Contract\NameConverterInterface;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

class OpenApiGenerator
{
    public const VERSION = '3.1.0';

    public const DEFAULTS = [
        'title' => 'API',
        'version' => '1.0.0',
        'description' => null,
        'servers' => [],
        'paths' => ['^/api'],
        'route' => ['enabled' => false, 'path' => '/api/doc'],
    ];

    public const METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'PATCH'];

    protected array $config;

    protected array $patterns = [];

    protected SchemaGenerator $schemas;

    protected array $tags = [];

    protected array $operationIds = [];

    public function __construct(
        protected RoutingManagerInterface $routing,
        array $config = [],
        protected MetadataFactory $metadata = new MetadataFactory(),
        protected ?NameConverterInterface $nameConverter = null,
        protected array $pagination = [],
    ) {
        $this->config = array_replace(self::DEFAULTS, $config);
        $this->pagination = array_replace(Paginator::DEFAULTS, $pagination);

        foreach ((array) $this->config['paths'] as $path) {
            $path = (string) $path;
            $this->patterns[] = str_starts_with($path, '^') ? '#' . str_replace('#', '\#', $path) . '#' : '#^' . preg_quote($path, '#') . '#';
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function generate(): array
    {
        $this->schemas = new SchemaGenerator($this->metadata, $this->nameConverter);
        $this->tags = [];
        $this->operationIds = [];
        $paths = [];

        foreach ($this->getRoutes() as $route) {
            $method = ControllerReflector::method($route->getController());
            $operations = $method === null ? [] : $this->attributes($method, Operation::class);

            if ($operations !== [] && $operations[0]->hidden) {
                continue;
            }

            $httpMethods = array_values(array_intersect($route->getMethods() === [] ? ['GET'] : $route->getMethods(), self::METHODS));

            foreach ($httpMethods as $httpMethod) {
                $paths[$this->path($route)][strtolower($httpMethod)] = $this->operation($route, $httpMethod, $method, $operations[0] ?? null, count($httpMethods) > 1);
            }
        }

        ksort($paths);
        $info = ['title' => (string) $this->config['title'], 'version' => (string) $this->config['version']];

        if (($this->config['description'] ?? null) !== null && $this->config['description'] !== '') {
            $info['description'] = (string) $this->config['description'];
        }

        $document = ['openapi' => self::VERSION, 'info' => $info];
        $servers = $this->servers();

        if ($servers !== []) {
            $document['servers'] = $servers;
        }

        if ($this->tags !== []) {
            ksort($this->tags);
            $document['tags'] = array_values($this->tags);
        }

        $document['paths'] = $paths === [] ? (object) [] : $paths;
        $schemas = $this->schemas->getSchemas();
        $document['components'] = ['schemas' => $schemas === [] ? (object) [] : $schemas];

        return $document;
    }

    public function toJson(bool $pretty = true): string
    {
        return (string) json_encode($this->generate(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    public function getRoutes(): array
    {
        $routes = [];

        foreach ($this->routing->getRoutes() as $route) {
            if (!$this->matches($route->getPath()) || ControllerReflector::target($route->getController())[0] === OpenApiController::class) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    protected function matches(string $path): bool
    {
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @phpstan-impure */
    protected function operation(Route $route, string $httpMethod, ?ReflectionMethod $method, ?Operation $attribute, bool $multiple): array
    {
        $operation = ['tags' => $this->operationTags($route, $method, $attribute)];

        if ($attribute?->summary !== null) {
            $operation['summary'] = $attribute->summary;
        }

        if ($attribute?->description !== null) {
            $operation['description'] = $attribute->description;
        }

        $operation['operationId'] = $this->operationId($attribute->operationId ?? ($multiple ? $route->getName() . '_' . strtolower($httpMethod) : $route->getName()));

        if ($attribute !== null && $attribute->deprecated) {
            $operation['deprecated'] = true;
        }

        if ($attribute !== null && $attribute->security !== []) {
            $operation['security'] = $attribute->security;
        }

        $errors = [];
        $parameters = $this->pathParameters($route, $method);

        if ($parameters !== []) {
            $errors[404] = true;
        }

        $body = null;
        $paginated = false;

        foreach ($method?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(MapRequestPayload::class) !== []) {
                $body = $this->requestBody($parameter);
                $errors += [400 => true, 415 => true, 422 => true];
            } elseif ($parameter->getAttributes(MapQueryString::class) !== []) {
                array_push($parameters, ...$this->queryParameters($parameter));
                $errors += [400 => true, 422 => true];
            } elseif ($parameter->getAttributes(MapPagination::class) !== [] || ($parameter->getType() instanceof ReflectionNamedType && $parameter->getType()->getName() === PageRequest::class)) {
                array_push($parameters, ...$this->paginationParameters($parameter));
                $paginated = true;
                $errors[400] = true;
            }
        }

        foreach ($method === null ? [] : $method->getAttributes(Parameter::class) as $item) {
            $parameters[] = $this->customParameter($item->newInstance());
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            $operation['requestBody'] = $body;
        }

        $rateLimited = $method !== null && ControllerReflector::attributes($route->getController(), RateLimit::class) !== [];

        if ($rateLimited) {
            $errors[429] = true;
        }

        $operation['responses'] = $this->responses($method, $errors, $rateLimited, $paginated);

        return $operation;
    }

    protected function operationTags(Route $route, ?ReflectionMethod $method, ?Operation $attribute): array
    {
        $tags = $attribute->tags ?? [];
        $attributes = [];

        if ($method !== null) {
            $attributes = ControllerReflector::attributes($route->getController(), Tag::class);
        }

        foreach ($attributes as $tag) {
            $this->tags[$tag->name] = array_filter(['name' => $tag->name, 'description' => $tag->description], static fn (mixed $value): bool => $value !== null);

            if ($attribute?->tags === null || $attribute->tags === []) {
                $tags[] = $tag->name;
            }
        }

        if ($tags === []) {
            $class = (string) (ControllerReflector::target($route->getController())[0] ?? '');
            $position = strrpos($class, '\\');
            $short = $position === false ? $class : substr($class, $position + 1);
            $tags[] = $short === '' ? 'default' : ((string) preg_replace('/Controller$/', '', $short) ?: $short);
        }

        foreach ($tags as $tag) {
            $this->tags[$tag] ??= ['name' => $tag];
        }

        return array_values(array_unique($tags));
    }

    protected function operationId(string $id): string
    {
        $candidate = $id;
        $index = 2;

        while (isset($this->operationIds[$candidate])) {
            $candidate = $id . '_' . $index++;
        }

        $this->operationIds[$candidate] = true;

        return $candidate;
    }

    protected function path(Route $route): string
    {
        return $route->getPath();
    }

    protected function pathParameters(Route $route, ?ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method?->getParameters() ?? [] as $parameter) {
            $arguments[$parameter->getName()] = $parameter;
        }

        $parameters = [];
        $requirements = $route->getRequirements();
        $defaults = $route->getDefaults();

        foreach ($route->getVariables() as $name) {
            $requirement = isset($requirements[$name]) ? (string) $requirements[$name] : null;
            $type = isset($arguments[$name]) && $arguments[$name]->getType() instanceof ReflectionNamedType ? $arguments[$name]->getType()->getName() : null;
            $schema = match (true) {
                $type === 'int' || in_array($requirement, ['\d+', '[0-9]+', '\d', '[1-9]\d*'], true) => ['type' => 'integer'],
                $type === 'float' => ['type' => 'number'],
                default => ['type' => 'string'],
            };

            if ($requirement !== null && $schema['type'] === 'string') {
                if (preg_match('/^[\w-]+(?:\|[\w-]+)+$/', $requirement) === 1) {
                    $schema['enum'] = explode('|', $requirement);
                } else {
                    $schema['pattern'] = '^(?:' . $requirement . ')$';
                }
            }

            if (array_key_exists($name, $defaults) && is_scalar($defaults[$name])) {
                $schema['default'] = $schema['type'] === 'integer' && is_numeric($defaults[$name]) ? (int) $defaults[$name] : $defaults[$name];
            }

            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
        }

        return $parameters;
    }

    protected function requestBody(ReflectionParameter $parameter): array
    {
        $attribute = $parameter->getAttributes(MapRequestPayload::class)[0]->newInstance();
        $schema = $this->parameterSchema($parameter, $attribute->groups, $attribute->validationGroups);
        $formats = $attribute->acceptFormats ?? ($attribute->format !== null ? [$attribute->format] : ['json']);
        $content = [];

        foreach ($formats as $format) {
            foreach (RequestPayloadResolver::CONTENT_TYPES[$format] ?? [] as $index => $mime) {
                if ($index === 0 || $format === 'form') {
                    $content[$mime] = ['schema' => $schema];
                }
            }
        }

        return [
            'required' => !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull(),
            'content' => $content === [] ? ['application/json' => ['schema' => $schema]] : $content,
        ];
    }

    protected function queryParameters(ReflectionParameter $parameter): array
    {
        $attribute = $parameter->getAttributes(MapQueryString::class)[0]->newInstance();
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
            return [];
        }

        [$properties, $required] = $this->schemas->properties($type->getName(), $attribute->groups, SchemaGenerator::WRITE, $attribute->validationGroups);
        $parameters = [];

        foreach ($properties as $name => $schema) {
            $schema = (array) $schema;
            $item = ['name' => (string) $name, 'in' => 'query', 'required' => in_array($name, $required, true) && !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()];

            if (isset($schema['description'])) {
                $item['description'] = $schema['description'];
                unset($schema['description']);
            }

            $item['schema'] = $schema === [] ? (object) [] : $schema;

            if (($schema['type'] ?? null) === 'array' || (is_array($schema['type'] ?? null) && in_array('array', $schema['type'], true))) {
                $item['name'] .= '[]';
                $item['style'] = 'form';
                $item['explode'] = true;
            }

            $parameters[] = $item;
        }

        return $parameters;
    }

    protected function paginationParameters(ReflectionParameter $parameter): array
    {
        $attributes = $parameter->getAttributes(MapPagination::class);
        $attribute = $attributes === [] ? new MapPagination() : $attributes[0]->newInstance();
        $max = max(1, $attribute->maxLimit ?? (int) $this->pagination['max_limit']);
        $default = min($max, max(1, $attribute->defaultLimit ?? (int) $this->pagination['default_limit']));

        return [
            ['name' => (string) $this->pagination['page_parameter'], 'in' => 'query', 'required' => false, 'description' => 'Page number (starts at 1)', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => (string) $this->pagination['limit_parameter'], 'in' => 'query', 'required' => false, 'description' => 'Number of items per page (values above ' . $max . ' are capped)', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => $default]],
        ];
    }

    protected function customParameter(Parameter $parameter): array
    {
        $schema = $parameter->schema ?? array_filter(['type' => $parameter->type, 'format' => $parameter->format, 'enum' => $parameter->enum], static fn (mixed $value): bool => $value !== null);
        $item = ['name' => $parameter->name, 'in' => $parameter->in, 'required' => $parameter->in === 'path' || $parameter->required];

        if ($parameter->description !== null) {
            $item['description'] = $parameter->description;
        }

        if ($parameter->deprecated) {
            $item['deprecated'] = true;
        }

        $item['schema'] = $schema;

        if ($parameter->example !== null) {
            $item['example'] = $parameter->example;
        }

        return $item;
    }

    protected function parameterSchema(ReflectionParameter $parameter, ?array $groups, ?array $validationGroups): array|object
    {
        $types = $parameter->getAttributes(Type::class);

        if ($types !== []) {
            return $this->schemas->type($types[0]->newInstance()->type, $groups, SchemaGenerator::WRITE, $validationGroups);
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return $this->schemas->type($type->getName(), $groups, SchemaGenerator::WRITE, $validationGroups);
        }

        return $this->schemas->reflectionType($type, $groups, SchemaGenerator::WRITE, $validationGroups);
    }

    protected function responses(?ReflectionMethod $method, array $errors, bool $rateLimited, bool $paginated): array
    {
        $responses = [];
        $attributes = $method === null ? [] : $method->getAttributes(ResponseAttribute::class);

        foreach ($attributes as $item) {
            $attribute = $item->newInstance();
            $responses[(string) $attribute->status] = $this->response($attribute, $rateLimited);
        }

        if ($responses === []) {
            [$status, $response] = $this->defaultResponse($method, $paginated);
            $responses[(string) $status] = $this->withHeaders($response, $status, $rateLimited, $paginated);
        }

        ksort($errors);

        foreach (array_keys($errors) as $status) {
            $responses[(string) $status] ??= $this->problemResponse($status);
        }

        ksort($responses, SORT_STRING);

        return $responses;
    }

    protected function response(ResponseAttribute $attribute, bool $rateLimited): array
    {
        $response = ['description' => $attribute->description ?? (Response::PHRASES[$attribute->status] ?? 'Response')];
        $schema = $attribute->schema;

        if ($schema === null && $attribute->type !== null) {
            $schema = $this->schemas->type($attribute->type, $attribute->groups, SchemaGenerator::READ);
            $schema = $attribute->paginated ? $this->pageSchema($schema) : ($attribute->isList ? ['type' => 'array', 'items' => $schema] : $schema);
        }

        if ($schema !== null) {
            $contentType = $attribute->contentType ?? ($attribute->status >= 400 ? ProblemDetails::CONTENT_TYPE : 'application/json');
            $response['content'] = [$contentType => ['schema' => $schema]];
        } elseif ($attribute->status >= 400) {
            $response = $this->problemResponse($attribute->status, $response['description']);
        }

        foreach ($attribute->headers as $name => $description) {
            $response['headers'][(string) $name] = ['description' => (string) $description, 'schema' => ['type' => 'string']];
        }

        return $attribute->status < 400 ? $this->withHeaders($response, $attribute->status, $rateLimited, $attribute->paginated) : $response;
    }

    protected function defaultResponse(?ReflectionMethod $method, bool $paginated): array
    {
        $type = $method?->getReturnType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($name === 'void' || $name === 'null' || $name === 'never') {
            return [204, ['description' => Response::PHRASES[204]]];
        }

        if ($name === Page::class || $paginated) {
            return [200, ['description' => Response::PHRASES[200], 'content' => ['application/json' => ['schema' => $this->pageSchema((object) [])]]]];
        }

        if ($type === null || ($name !== null && (is_a($name, Response::class, true) || $name === 'mixed'))) {
            return [200, ['description' => Response::PHRASES[200]]];
        }

        if ($name === 'string') {
            return [200, ['description' => Response::PHRASES[200], 'content' => ['text/html' => ['schema' => ['type' => 'string']]]]];
        }

        return [200, ['description' => Response::PHRASES[200], 'content' => ['application/json' => ['schema' => $this->schemas->reflectionType($type, null, SchemaGenerator::READ)]]]];
    }

    protected function withHeaders(array $response, int $status, bool $rateLimited, bool $paginated): array
    {
        if ($paginated) {
            $response['headers']['Link'] = ['description' => 'Pagination links (RFC 8288): first, prev, next, last', 'schema' => ['type' => 'string']];
            $response['headers']['X-Total-Count'] = ['description' => 'Total number of items', 'schema' => ['type' => 'integer']];
        }

        if ($rateLimited) {
            $response['headers']['X-RateLimit-Limit'] = ['description' => 'Maximum number of requests in the window', 'schema' => ['type' => 'integer']];
            $response['headers']['X-RateLimit-Remaining'] = ['description' => 'Remaining requests in the window', 'schema' => ['type' => 'integer']];
            $response['headers']['X-RateLimit-Reset'] = ['description' => 'Unix timestamp when the limit resets', 'schema' => ['type' => 'integer']];
        }

        return $response;
    }

    protected function problemResponse(int $status, ?string $description = null): array
    {
        $schema = $status === 422 ? $this->validationProblemSchema() : $this->problemSchema();
        $response = [
            'description' => $description ?? (Response::PHRASES[$status] ?? 'Error'),
            'content' => [ProblemDetails::CONTENT_TYPE => ['schema' => $schema]],
        ];

        if ($status === 429) {
            $response['headers']['Retry-After'] = ['description' => 'Seconds to wait before retrying', 'schema' => ['type' => 'integer']];
        }

        if ($status === 405) {
            $response['headers']['Allow'] = ['description' => 'Allowed methods', 'schema' => ['type' => 'string']];
        }

        return $response;
    }

    protected function problemSchema(): array
    {
        if (!$this->schemas->hasSchema('ProblemDetails')) {
            $this->schemas->addSchema('ProblemDetails', [
                'type' => 'object',
                'description' => 'RFC 7807 / RFC 9457 problem details',
                'required' => ['type', 'title', 'status'],
                'properties' => [
                    'type' => ['type' => 'string', 'format' => 'uri-reference', 'default' => 'about:blank'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer', 'minimum' => 400, 'maximum' => 599],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string', 'format' => 'uri-reference'],
                ],
                'additionalProperties' => true,
            ]);
        }

        return ['$ref' => SchemaGenerator::REF_PREFIX . 'ProblemDetails'];
    }

    protected function validationProblemSchema(): array
    {
        if (!$this->schemas->hasSchema('ValidationProblemDetails')) {
            $this->schemas->addSchema('ValidationProblemDetails', [
                'allOf' => [
                    $this->problemSchema(),
                    [
                        'type' => 'object',
                        'properties' => [
                            'violations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['propertyPath', 'title'],
                                    'properties' => [
                                        'propertyPath' => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                        'template' => ['type' => 'string'],
                                        'parameters' => ['type' => 'object', 'additionalProperties' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        return ['$ref' => SchemaGenerator::REF_PREFIX . 'ValidationProblemDetails'];
    }

    protected function pageSchema(array|object $items): array
    {
        if (!$this->schemas->hasSchema('Pagination')) {
            $this->schemas->addSchema('Pagination', [
                'type' => 'object',
                'required' => ['total', 'page', 'limit', 'pages'],
                'properties' => [
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'limit' => ['type' => 'integer', 'minimum' => 1],
                    'pages' => ['type' => 'integer', 'minimum' => 1],
                ],
            ]);
            $link = ['type' => ['string', 'null'], 'format' => 'uri-reference'];
            $this->schemas->addSchema('PaginationLinks', [
                'type' => 'object',
                'properties' => ['self' => $link, 'first' => $link, 'prev' => $link, 'next' => $link, 'last' => $link],
            ]);
        }

        $items = (array) $items;
        $ref = (string) ($items['$ref'] ?? '');
        $schema = [
            'type' => 'object',
            'required' => ['items', 'pagination', 'links'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => $items === [] ? (object) [] : $items],
                'pagination' => ['$ref' => SchemaGenerator::REF_PREFIX . 'Pagination'],
                'links' => ['$ref' => SchemaGenerator::REF_PREFIX . 'PaginationLinks'],
            ],
        ];

        if ($ref === '') {
            return $schema;
        }

        $parts = explode('-', substr($ref, strlen(SchemaGenerator::REF_PREFIX)), 2);
        $name = $parts[0] . 'Page' . (isset($parts[1]) ? '-' . $parts[1] : '');

        return $this->schemas->hasSchema($name) ? ['$ref' => SchemaGenerator::REF_PREFIX . $name] : $this->schemas->addSchema($name, $schema);
    }

    protected function servers(): array
    {
        $servers = [];

        foreach ((array) $this->config['servers'] as $server) {
            $servers[] = is_array($server) ? array_filter(['url' => (string) ($server['url'] ?? '/'), 'description' => $server['description'] ?? null], static fn (mixed $value): bool => $value !== null) : ['url' => (string) $server];
        }

        return $servers;
    }

    protected function attributes(ReflectionMethod $method, string $class): array
    {
        $attributes = array_map(static fn (object $item): object => $item->newInstance(), $method->getAttributes($class));

        if ($attributes !== []) {
            return $attributes;
        }

        return array_map(static fn (object $item): object => $item->newInstance(), $method->getDeclaringClass()->getAttributes($class));
    }
}