<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller\Contract;

use NeoPHP\Component\Http\Request\Request;
use ReflectionParameter;

interface ArgumentResolverInterface
{
    public const SERVICES_ID = 'controller.argument_resolvers';

    public function supports(ReflectionParameter $parameter, Request $request): bool;

    public function resolve(ReflectionParameter $parameter, Request $request): mixed;
}