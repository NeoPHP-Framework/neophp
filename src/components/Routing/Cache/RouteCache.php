<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Cache;

use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\Route\RouteCollection;

class RouteCache
{
    protected ResourceCache $cache;

    public function __construct(protected string $file, protected bool $debug = false)
    {
        $this->cache = new ResourceCache($file, $debug);
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function load(callable $builder): RouteCollection
    {
        $build = fn (): array => $this->build($builder);
        $data = $this->cache->load($build);

        if (!isset($data['routes'])) {
            $this->cache->clear();
            $data = $this->cache->load($build);
        }

        return $this->hydrate((array) ($data['routes'] ?? []));
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    protected function build(callable $builder): array
    {
        [$routes, $resources] = $builder();
        $data = ['routes' => []];

        foreach ($routes as $route) {
            $data['routes'][] = [
                $route->getName(),
                $route->getPath(),
                $route->getController(),
                $route->getMethods(),
                $route->getRequirements(),
                $route->getDefaults(),
                $route->getOptions(),
                $route->getSource(),
            ];
        }

        return [$data, $resources];
    }

    protected function hydrate(array $routes): RouteCollection
    {
        $collection = new RouteCollection();

        foreach ($routes as $data) {
            $route = new Route($data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6]);
            $collection->add($route->setSource((string) $data[7]));
        }

        return $collection;
    }
}