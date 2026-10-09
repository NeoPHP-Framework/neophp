<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\ArgumentResolver;

use NeoPHP\Component\Api\Attribute\MapPagination;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Api\Pagination\PageRequest;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Http\Request\Request;
use ReflectionNamedType;
use ReflectionParameter;

class PageRequestResolver implements ArgumentResolverInterface
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function supports(ReflectionParameter $parameter, Request $request): bool
    {
        if ($parameter->getAttributes(MapPagination::class) !== []) {
            return true;
        }

        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === PageRequest::class;
    }

    public function resolve(ReflectionParameter $parameter, Request $request): mixed
    {
        $attributes = $parameter->getAttributes(MapPagination::class);
        $attribute = $attributes === [] ? new MapPagination() : $attributes[0]->newInstance();

        return $this->container->get(PaginatorInterface::class)->createPageRequest($request, $attribute->defaultLimit, $attribute->maxLimit);
    }
}