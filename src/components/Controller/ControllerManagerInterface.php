<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller;

use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Component\Controller\Exception\ControllerException;
use NeoPHP\Component\Http\Exception\NotFoundHttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

interface ControllerManagerInterface
{
    /**
     * Turns a controller definition into a callable, building the controller class with the container.
     *
     * @param mixed $controller "Class::method", [class, method], an invokable class name or a callable
     * @return callable The controller
     * @throws ControllerException When the class does not exist, the method is not public, the class is not invokable, or the value is not a controller
     * @throws ContainerException When the controller class cannot be built by the container
     */
    public function resolve(mixed $controller): callable;

    /**
     * Resolves the arguments of a controller: the request, the argument resolvers, the route parameters cast to their scalar type, the request attributes, the services and the default values.
     *
     * @param callable $controller The controller
     * @param Request $request The current request
     * @param array<string, mixed> $routeParameters Parameters of the matched route, by name
     * @return list<mixed> The arguments, in the order of the parameters
     * @throws ControllerException When an argument cannot be resolved or an argument resolver does not implement ArgumentResolverInterface
     * @throws NotFoundHttpException When a route parameter cannot be cast to the scalar type of its argument
     * @throws ContainerException When a service argument cannot be built by the container
     */
    public function resolveArguments(callable $controller, Request $request, array $routeParameters = []): array;

    /**
     * Resolves and calls a controller, then turns its result into a response: a string gives an HTML response, an array or a JsonSerializable a JSON response, null a 204 response.
     *
     * @param mixed $controller "Class::method", [class, method], an invokable class name or a callable
     * @param Request $request The current request
     * @param array<string, mixed> $routeParameters Parameters of the matched route, by name
     * @return Response The response of the controller
     * @throws ControllerException When the controller or an argument cannot be resolved, or the controller returns an unsupported value
     * @throws NotFoundHttpException When a route parameter cannot be cast to the scalar type of its argument
     * @throws ContainerException When the controller or a service argument cannot be built by the container
     */
    public function dispatch(mixed $controller, Request $request, array $routeParameters = []): Response;
}