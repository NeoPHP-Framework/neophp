<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller;

use Closure;
use JsonSerializable;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Controller\Contract\ControllerInterface;
use NeoPHP\Component\Controller\Contract\ControllerResolverInterface;
use NeoPHP\Component\Controller\Exception\ControllerException;
use NeoPHP\Component\Http\Contract\HttpInterface;
use NeoPHP\Component\Http\Exception\NotFoundHttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Stringable;

class ControllerManager implements ControllerResolverInterface
{
    protected ?array $argumentResolvers = null;

    public function __construct(protected ContainerInterface $container, protected HttpInterface $http)
    {
    }

    public function addArgumentResolver(ArgumentResolverInterface $resolver): static
    {
        $this->argumentResolvers = [...$this->getArgumentResolvers(), $resolver];

        return $this;
    }

    public function getArgumentResolvers(): array
    {
        if ($this->argumentResolvers !== null) {
            return $this->argumentResolvers;
        }

        $resolvers = [];
        $ids = $this->container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $this->container->get(ArgumentResolverInterface::SERVICES_ID) : [];

        foreach ($ids as $id) {
            $resolver = is_string($id) ? $this->container->get($id) : $id;

            if (!$resolver instanceof ArgumentResolverInterface) {
                throw new ControllerException('The argument resolver "{resolver}" must implement {interface}.', 0, null, [
                    'resolver' => is_string($id) ? $id : get_debug_type($id),
                    'interface' => ArgumentResolverInterface::class,
                ]);
            }

            $resolvers[] = $resolver;
        }

        return $this->argumentResolvers = $resolvers;
    }

    public function resolve(mixed $controller): callable
    {
        if (is_string($controller) && str_contains($controller, '::')) {
            $controller = explode('::', $controller, 2);
        }

        if (is_array($controller) && count($controller) === 2 && is_string($controller[0] ?? null) && is_string($controller[1] ?? null)) {
            [$class, $method] = $controller;
            $callable = [$this->instantiate($class), $method];

            if (!is_callable($callable)) {
                throw new ControllerException('The controller method "{class}::{method}()" does not exist or is not public.', 0, null, ['class' => $class, 'method' => $method]);
            }

            return $callable;
        }

        if (is_string($controller) && class_exists($controller)) {
            $instance = $this->instantiate($controller);

            if (!is_callable($instance)) {
                throw new ControllerException('The controller "{class}" is not invokable: add an __invoke() method or use "{class}::method".', 0, null, ['class' => $controller]);
            }

            return $instance;
        }

        if (is_callable($controller)) {
            return $controller;
        }

        throw new ControllerException('Unable to resolve the controller "{controller}". Expected "Class::method", an invokable class or a callable.', 0, null, [
            'controller' => is_string($controller) ? $controller : get_debug_type($controller),
        ]);
    }

    public function resolveArguments(callable $controller, Request $request, array $routeParameters = []): array
    {
        $reflection = $this->reflect($controller);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $arguments[] = $this->resolveArgument($parameter, $request, $routeParameters, $reflection);
        }

        return $arguments;
    }

    public function dispatch(mixed $controller, Request $request, array $routeParameters = []): Response
    {
        $callable = $this->resolve($controller);
        $result = $callable(...$this->resolveArguments($callable, $request, $routeParameters));

        return $this->toResponse($result);
    }

    protected function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result === null => $this->http->createResponse('', 204),
            is_string($result), $result instanceof Stringable => $this->http->createResponse((string) $result),
            is_array($result), $result instanceof JsonSerializable => $this->http->json($result),
            default => throw new ControllerException('A controller must return a Response, a string, an array or null ({type} returned).', 0, null, [
                'type' => get_debug_type($result),
            ]),
        };
    }

    protected function resolveArgument(ReflectionParameter $parameter, Request $request, array $routeParameters, ReflectionFunctionAbstract $function): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_a($request, $type->getName())) {
            return $request;
        }

        foreach ($this->getArgumentResolvers() as $resolver) {
            if ($resolver->supports($parameter, $request)) {
                return $resolver->resolve($parameter, $request);
            }
        }

        if (array_key_exists($name, $routeParameters)) {
            return $this->cast($routeParameters[$name], $parameter);
        }

        if ($request->attributes->has($name)) {
            return $request->attributes->get($name);
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
            return $this->container->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ControllerException('Unable to resolve the argument "${argument}" of "{controller}": it is neither the request, a route parameter, a request attribute nor a service.', 0, null, [
            'argument' => $name,
            'controller' => $this->describe($function),
        ]);
    }

    protected function cast(mixed $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin() || !is_scalar($value)) {
            return $value;
        }

        $cast = match ($type->getName()) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'string' => (string) $value,
            default => $value,
        };

        if ($cast === null) {
            throw new NotFoundHttpException('The route parameter "{parameter}" must be of type {type}.', [
                'parameter' => $parameter->getName(),
                'type' => $type->getName(),
            ]);
        }

        return $cast;
    }

    protected function instantiate(string $class): object
    {
        if (!class_exists($class)) {
            throw new ControllerException('The controller class "{class}" does not exist (check its namespace and the composer autoload).', 0, null, ['class' => $class]);
        }

        $instance = $this->container->get($class);

        if ($instance instanceof ControllerInterface) {
            $instance->setContainer($this->container);
        }

        return $instance;
    }

    protected function reflect(callable $controller): ReflectionFunctionAbstract
    {
        if (is_array($controller)) {
            return new ReflectionMethod($controller[0], $controller[1]);
        }

        if (is_object($controller) && !$controller instanceof Closure) {
            return new ReflectionMethod($controller, '__invoke');
        }

        return new ReflectionFunction(Closure::fromCallable($controller));
    }

    protected function describe(ReflectionFunctionAbstract $function): string
    {
        return $function instanceof ReflectionMethod
            ? $function->getDeclaringClass()->getName() . '::' . $function->getName() . '()'
            : $function->getName() . '()';
    }
}