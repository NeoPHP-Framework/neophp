<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing;

use Closure;
use NeoPHP\Component\Routing\Exception\MethodNotAllowedException;
use NeoPHP\Component\Routing\Exception\RouteNotDefinedException;
use NeoPHP\Component\Routing\Exception\RouteNotFoundException;
use NeoPHP\Component\Routing\Exception\RoutingException;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\Route\RouteCollection;
use NeoPHP\Component\Routing\Route\RouteMatch;
use NeoPHP\Package\Yaml\Exception\ParseException;

interface RoutingManagerInterface
{
    /**
     * Finds the first route matching a request, in the order the routes are declared.
     *
     * @param string $method HTTP method of the request
     * @param string $path Path of the request, without the base path
     * @return RouteMatch The matched route and its parameters
     * @throws RouteNotFoundException When no route matches the path (404)
     * @throws MethodNotAllowedException When the path matches only with other HTTP methods (405)
     * @throws RoutingException When the requirement of a route is not a valid regular expression
     */
    public function match(string $method, string $path): RouteMatch;

    /**
     * Generates the path or the absolute URL of a route; the parameters that are not placeholders are added to the query string.
     *
     * @param string $name Name of the route
     * @param array<string, mixed> $parameters Values of the placeholders and of the query string
     * @param bool $absolute Generates an absolute URL with the base URL
     * @return string The path, with the base path of the application, or the absolute URL
     * @throws RouteNotDefinedException When the route does not exist, or a parameter is missing, not a scalar or does not match its requirement
     * @throws RoutingException When an absolute URL is asked without a valid base URL
     */
    public function generate(string $name, array $parameters = [], bool $absolute = false): string;

    /**
     * Sets the base URL of the absolute URLs.
     *
     * @param Closure|string|null $baseUrl The scheme and host (https://example.com), a closure returning it, or null
     * @return static The routing manager
     */
    public function setBaseUrl(Closure|string|null $baseUrl): static;

    /**
     * Returns the base URL of the absolute URLs.
     *
     * @return string The base URL, without trailing slash
     * @throws RoutingException When no base URL is set or it is not an absolute http(s) URL
     */
    public function getBaseUrl(): string;

    /**
     * Sets the sub-directory of the application, added to the generated paths.
     *
     * @param Closure|string|null $basePath The sub-directory (/app), a closure returning it, or null
     * @return static The routing manager
     */
    public function setBasePath(Closure|string|null $basePath): static;

    /**
     * Returns the sub-directory of the application.
     *
     * @return string The base path with a leading slash and without trailing slash, or an empty string
     */
    public function getBasePath(): string;

    /**
     * Adds a route; a route of the same name is replaced.
     *
     * @param Route $route The route
     * @return static The routing manager
     */
    public function add(Route $route): static;

    /**
     * Loads a routes file, its imports, the attribute routes of the imported controllers and the routes of the imported NeoPHP packages.
     *
     * @param string $file Path of the YAML routes file
     * @return static The routing manager
     * @throws RoutingException When the file does not exist or is invalid, an import is circular or unknown, a route is incomplete or defined twice
     * @throws ParseException When the file is not valid YAML
     */
    public function loadYaml(string $file): static;

    /**
     * Returns every route.
     *
     * @return RouteCollection The routes, in their declaration order
     */
    public function getRoutes(): RouteCollection;
}