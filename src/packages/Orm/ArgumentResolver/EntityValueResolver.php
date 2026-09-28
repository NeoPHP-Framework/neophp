<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\ArgumentResolver;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Http\Exception\NotFoundHttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Orm\Attribute\MapEntity;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use ReflectionNamedType;
use ReflectionParameter;

class EntityValueResolver implements ArgumentResolverInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function supports(ReflectionParameter $parameter, Request $request): bool
    {
        $class = $this->entityClass($parameter);

        if ($class === null || $this->attribute($parameter)?->disabled) {
            return false;
        }

        return $this->criteria($parameter, $request, $class) !== null;
    }

    public function resolve(ReflectionParameter $parameter, Request $request): mixed
    {
        $class = (string) $this->entityClass($parameter);
        $criteria = $this->criteria($parameter, $request, $class) ?? [];
        $orm = $this->orm();
        $entity = array_key_exists('__id', $criteria) ? $orm->find($class, $criteria['__id']) : $orm->getRepository($class)->findOneBy($criteria);

        if ($entity !== null || $parameter->allowsNull()) {
            return $entity;
        }

        throw new NotFoundHttpException($this->attribute($parameter)->message ?? 'The "{entity}" object was not found.', ['entity' => substr(strrchr('\\' . $class, '\\'), 1)]);
    }

    protected function criteria(ReflectionParameter $parameter, Request $request, string $class): ?array
    {
        $attribute = $this->attribute($parameter);
        $parameters = array_filter($request->attributes->all(), static fn (mixed $value, int|string $key): bool => is_string($key) && !str_starts_with($key, '_') && is_scalar($value), ARRAY_FILTER_USE_BOTH);

        if ($attribute !== null && $attribute->mapping !== []) {
            $criteria = [];

            foreach ($attribute->mapping as $routeParameter => $field) {
                if (!array_key_exists($routeParameter, $parameters)) {
                    return null;
                }

                $criteria[(string) $field] = $parameters[$routeParameter];
            }

            return $criteria;
        }

        if ($attribute?->id !== null) {
            return array_key_exists($attribute->id, $parameters) ? ['__id' => $parameters[$attribute->id]] : null;
        }

        $name = $parameter->getName();

        foreach ([$name, $name . '_id', $name . 'Id'] as $key) {
            if (array_key_exists($key, $parameters)) {
                return ['__id' => $parameters[$key]];
            }
        }

        if (array_key_exists('id', $parameters) && $this->countEntityArguments($parameter) === 1) {
            return ['__id' => $parameters['id']];
        }

        $metadata = $this->orm()->getMetadata($class);
        $others = array_map(static fn (ReflectionParameter $other): string => $other->getName(), $parameter->getDeclaringFunction()->getParameters());
        $criteria = [];

        foreach ($parameters as $key => $value) {
            if ($key !== 'id' && !in_array($key, $others, true) && $metadata->hasField($key)) {
                $criteria[$key] = $value;
            }
        }

        return $criteria === [] ? null : $criteria;
    }

    protected function entityClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !$this->container->has(OrmInterface::class)) {
            return null;
        }

        return $this->orm()->getMetadataFactory()->isEntity($type->getName()) ? $type->getName() : null;
    }

    protected function countEntityArguments(ReflectionParameter $parameter): int
    {
        $count = 0;

        foreach ($parameter->getDeclaringFunction()->getParameters() as $other) {
            if ($this->entityClass($other) !== null && !$this->attribute($other)?->disabled) {
                $count++;
            }
        }

        return $count;
    }

    protected function attribute(ReflectionParameter $parameter): ?MapEntity
    {
        $attributes = $parameter->getAttributes(MapEntity::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    protected function orm(): OrmInterface
    {
        return $this->container->get(OrmInterface::class);
    }
}