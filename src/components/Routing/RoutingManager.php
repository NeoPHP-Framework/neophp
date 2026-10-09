<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing;

use Closure;
use NeoPHP\Component\Config\ConfigManager;
use NeoPHP\Component\Http\HttpManager;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Routing\Exception\MethodNotAllowedException;
use NeoPHP\Component\Routing\Exception\RouteNotDefinedException;
use NeoPHP\Component\Routing\Exception\RouteNotFoundException;
use NeoPHP\Component\Routing\Exception\RoutingException;
use NeoPHP\Component\Routing\Loader\YamlRouteLoader;
use NeoPHP\Component\Routing\Provider\RoutingProvider;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\Route\RouteCollection;
use NeoPHP\Component\Routing\Route\RouteMatch;
use NeoPHP\Package\Yaml\YamlManager;
use NeoPHP\Package\Yaml\YamlManagerInterface;

#[Component(provider: RoutingProvider::class, requires: [ConfigManager::class, YamlManager::class, HttpManager::class])]
final class RoutingManager implements RoutingManagerInterface
{
    protected RouteCollection $routes;

    protected Closure|string|null $baseUrl = null;

    protected Closure|string|null $basePath = null;

    protected YamlManagerInterface $yaml;

    protected mixed $resolver;

    public function __construct(?YamlManagerInterface $yaml = null, ?RouteCollection $routes = null, ?callable $resolver = null)
    {
        $this->routes = $routes ?? new RouteCollection();
        $this->yaml = $yaml ?? new YamlManager();
        $this->resolver = $resolver;
    }

    public function match(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $path = Route::normalizePath($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->match($path);

            if ($parameters === null) {
                continue;
            }

            if (!$route->allowsMethod($method)) {
                array_push($allowed, ...$route->getMethods());
                continue;
            }

            return new RouteMatch($route, $parameters);
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));

            throw MethodNotAllowedException::forPath($method, $path, $allowed);
        }

        throw RouteNotFoundException::forPath($method, $path);
    }

    public function generate(string $name, array $parameters = [], bool $absolute = false): string
    {
        $route = $this->routes->get($name);

        if ($route === null) {
            throw new RouteNotDefinedException(sprintf('Route "%s" does not exist.', $name));
        }

        $path = $this->getBasePath() . $route->generate($parameters);

        return $absolute ? $this->getBaseUrl() . $path : $path;
    }

    public function setBasePath(Closure|string|null $basePath): static
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function getBasePath(): string
    {
        $basePath = $this->basePath instanceof Closure ? ($this->basePath)() : $this->basePath;
        $basePath = rtrim(trim((string) $basePath), '/');

        return $basePath === '' ? '' : '/' . ltrim($basePath, '/');
    }

    public function setBaseUrl(Closure|string|null $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getBaseUrl(): string
    {
        $baseUrl = $this->baseUrl instanceof Closure ? ($this->baseUrl)() : $this->baseUrl;
        $baseUrl = rtrim(trim((string) $baseUrl), '/');

        if ($baseUrl === '') {
            throw new RoutingException('Unable to generate an absolute URL: there is no current request, set the "url" option in config/framework/app.yaml (APP_URL in .env).');
        }

        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw new RoutingException('The base URL "{url}" is not valid: use an absolute URL such as https://example.com (APP_URL in .env).', 0, null, ['url' => $baseUrl]);
        }

        return $baseUrl;
    }

    public function add(Route $route): static
    {
        $this->routes->add($route);

        return $this;
    }

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }

    public function loadYaml(string $file): static
    {
        $this->routes->addCollection((new YamlRouteLoader($this->yaml, $this->resolver))->load($file));

        return $this;
    }
}