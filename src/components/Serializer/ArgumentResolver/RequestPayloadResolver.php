<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\ArgumentResolver;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Http\Exception\BadRequestHttpException;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Serializer\Attribute\MapQueryString;
use NeoPHP\Component\Serializer\Attribute\MapRequestPayload;
use NeoPHP\Component\Serializer\Attribute\Type;
use NeoPHP\Component\Serializer\Exception\ExtraAttributesException;
use NeoPHP\Component\Serializer\Exception\MissingConstructorArgumentsException;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\Exception\UnexpectedValueException;
use NeoPHP\Component\Serializer\SerializerManager;
use NeoPHP\Component\Serializer\SerializerManagerInterface;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Exception\ValidationFailedException;
use NeoPHP\Component\Validator\ValidatorManagerInterface;
use NeoPHP\Component\Validator\Violation\Violation;
use NeoPHP\Component\Validator\Violation\ViolationList;
use ReflectionNamedType;
use ReflectionParameter;

class RequestPayloadResolver implements ArgumentResolverInterface
{
    public const CONTENT_TYPES = [
        'json' => ['application/json'],
        'xml' => ['application/xml', 'text/xml'],
        'csv' => ['text/csv'],
        'yaml' => ['application/yaml', 'application/x-yaml', 'text/yaml', 'text/x-yaml'],
        'form' => ['application/x-www-form-urlencoded', 'multipart/form-data'],
    ];

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function supports(ReflectionParameter $parameter, Request $request): bool
    {
        return $parameter->getAttributes(MapRequestPayload::class) !== [] || $parameter->getAttributes(MapQueryString::class) !== [];
    }

    public function resolve(ReflectionParameter $parameter, Request $request): mixed
    {
        $type = $this->type($parameter);
        $payload = $parameter->getAttributes(MapRequestPayload::class);

        if ($payload !== []) {
            $attribute = $payload[0]->newInstance();
            [$data, $format] = $this->payload($request, $attribute);

            if ($data === null) {
                return $this->fallback($parameter, $type, $attribute->context, $attribute->groups, $attribute->validate, $attribute->validationGroups);
            }

            return $this->map($data, $type, $format, $attribute->context, $attribute->groups, $attribute->validate, $attribute->validationGroups);
        }

        $attribute = $parameter->getAttributes(MapQueryString::class)[0]->newInstance();
        $data = $request->query->all();

        if ($data === []) {
            return $this->fallback($parameter, $type, $attribute->context, $attribute->groups, $attribute->validate, $attribute->validationGroups);
        }

        return $this->map($data, $type, 'query', $attribute->context, $attribute->groups, $attribute->validate, $attribute->validationGroups);
    }

    public static function formatOf(?string $contentType): ?string
    {
        $contentType = strtolower(trim((string) $contentType));

        if ($contentType === '') {
            return null;
        }

        foreach (self::CONTENT_TYPES as $format => $types) {
            if (in_array($contentType, $types, true)) {
                return $format;
            }
        }

        return match (true) {
            str_ends_with($contentType, '+json') => 'json',
            str_ends_with($contentType, '+xml') => 'xml',
            default => null,
        };
    }

    protected function payload(Request $request, MapRequestPayload $attribute): array
    {
        $content = $request->getContent();
        $contentType = $request->getContentType();
        $format = $attribute->format ?? self::formatOf($contentType);

        if (trim($content) === '' && $request->request->all() === []) {
            return [null, $format ?? 'json'];
        }

        if ($format === null || ($attribute->acceptFormats !== null && !in_array($format, $attribute->acceptFormats, true))) {
            $accepted = $attribute->acceptFormats ?? array_keys(self::CONTENT_TYPES);

            throw new HttpException(415, 'Unsupported request Content-Type "{type}": expected {formats}.', [], [
                'type' => (string) ($contentType ?? 'none'),
                'formats' => implode(', ', $accepted),
            ]);
        }

        if ($format === 'form') {
            return [array_replace_recursive($request->request->all(), $request->files->all()), 'form'];
        }

        if (!$this->serializer()->supportsFormat($format)) {
            throw new HttpException(415, 'Unsupported request format "{format}".', [], ['format' => $format]);
        }

        try {
            $data = $this->serializer()->decode($content, $format);
        } catch (UnexpectedValueException $exception) {
            throw new BadRequestHttpException('Malformed request payload: {error}', ['error' => $exception->getMessage()], $exception);
        }

        if (!is_array($data)) {
            throw new BadRequestHttpException('Malformed request payload: an object or a list is expected, {type} given.', ['type' => get_debug_type($data)]);
        }

        return [$data, $format];
    }

    protected function fallback(ReflectionParameter $parameter, string $type, array $context, ?array $groups, bool $validate, ?array $validationGroups): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        return $this->map([], $type, 'json', $context, $groups, $validate, $validationGroups);
    }

    protected function map(array $data, string $type, string $format, array $context, ?array $groups, bool $validate, ?array $validationGroups): mixed
    {
        if ($groups !== null) {
            $context[SerializerManager::GROUPS] = $groups;
        }

        try {
            $object = $this->serializer()->denormalize($data, $type, $format, $context);
        } catch (NotNormalizableValueException $exception) {
            throw ValidationFailedException::create($this->violations($exception, $data));
        }

        if ($validate && $this->container->has(ValidatorManagerInterface::class)) {
            $violations = $this->validate($object, $validationGroups ?? [AbstractConstraint::DEFAULT_GROUP]);

            if (count($violations) > 0) {
                throw ValidationFailedException::create($violations);
            }
        }

        return $object;
    }

    protected function validate(mixed $object, array $groups): ViolationList
    {
        $validator = $this->container->get(ValidatorManagerInterface::class);

        if (!is_array($object)) {
            return $validator->validate($object, null, $groups);
        }

        $violations = new ViolationList();

        foreach ($object as $index => $item) {
            if (!is_object($item)) {
                continue;
            }

            foreach ($validator->validate($item, null, $groups) as $violation) {
                $path = $violation->getPropertyPath();
                $violations->add(new Violation(
                    $violation->getMessage(),
                    $violation->getMessageTemplate(),
                    $violation->getParameters(),
                    '[' . $index . ']' . ($path === '' ? '' : '.' . $path),
                    $violation->getInvalidValue(),
                    $violation->getConstraint(),
                ));
            }
        }

        return $violations;
    }

    protected function violations(NotNormalizableValueException $exception, array $data): ViolationList
    {
        $violations = new ViolationList();

        if ($exception instanceof MissingConstructorArgumentsException) {
            foreach ($exception->getMissingArguments() as $path) {
                $violations->add(new Violation('This value should not be missing.', 'This value should not be missing.', [], (string) $path, null));
            }

            return $violations;
        }

        if ($exception instanceof ExtraAttributesException) {
            $prefix = $exception->getPath();

            foreach ($exception->getExtraAttributes() as $attribute) {
                $violations->add(new Violation('This field was not expected.', 'This field was not expected.', [], ($prefix === null ? '' : $prefix . '.') . $attribute, null));
            }

            return $violations;
        }

        $violations->add(new Violation($exception->getMessage(), $exception->getMessage(), [], (string) $exception->getPath(), null));

        return $violations;
    }

    protected function type(ReflectionParameter $parameter): string
    {
        $types = $parameter->getAttributes(Type::class);

        if ($types !== []) {
            return $types[0]->newInstance()->type;
        }

        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || ($type->isBuiltin() && $type->getName() !== 'array')) {
            throw new SerializerException('The argument "${argument}" mapped with #[MapRequestPayload] / #[MapQueryString] must be typed with a class (or be an array with #[Type(\'App\\Dto\\Item[]\')]).', 0, null, [
                'argument' => $parameter->getName(),
            ]);
        }

        return $type->getName();
    }

    protected function serializer(): SerializerManagerInterface
    {
        return $this->container->get(SerializerManagerInterface::class);
    }
}