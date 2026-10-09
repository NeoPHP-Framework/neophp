# Routing

The Routing component maps URLs to controllers. Routes are declared in `config/routes.yaml`, with the `#[Route]` attribute on the controllers, or both.
It matches the incoming requests, generates paths and absolute URLs, and compiles the routes into a cache.

## Summary

- [Attribute routes](#attribute-routes)
- [YAML routes](#yaml-routes)
- [Matching](#matching)
- [Generating URLs](#generating-urls)
- [Absolute URLs](#absolute-urls)
- [Cache](#cache)
- [Listing routes](#listing-routes)
- [API](#api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Attribute routes

`config/routes.yaml` declares where the controllers are:

```yaml
controllers:
  resource: ../src/Controller/
  type: attribute
```

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

#[Route('/blog', name: 'blog_')]
class BlogController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('blog/index');
    }

    #[Route('/{slug}', name: 'show', requirements: ['slug' => '[a-z0-9-]+'])]
    #[Route('/post/{slug}', name: 'show_legacy')]
    public function show(string $slug): Response
    {
        return $this->render('blog/show', ['slug' => $slug]);
    }
}
```

`NeoPHP\Component\Routing\Attribute\Route`:

| Argument | Description |
|---|---|
| `path` | URL pattern, placeholders written `{name}` |
| `name` | route name (default: built from the class and the method, `app_blog_show`) |
| `methods` | allowed HTTP methods: `['GET', 'POST']` or `'GET\|POST'` (all when omitted) |
| `requirements` | regex per placeholder |
| `defaults` | default values |
| `options` | free options |
| `middlewares` | middlewares of the route (see the Middleware documentation) |

On the class, `#[Route]` is a prefix: its `path` and `name` are prepended to every route of the class, its `middlewares` run before the ones of the method, and its `methods`, `requirements`, `defaults` and `options` are the default values of these routes. On an invokable class (`__invoke()`) without method routes, the class attribute defines the route itself. The attribute is repeatable.

`resource` can be a directory (scanned recursively) or a PHP file. `prefix`, `name_prefix`, `requirements`, `defaults`, `options`, `methods` and `middlewares` can be used on the import, like for a YAML import:

```yaml
admin_controllers:
  resource: ../src/Admin/Controller/
  type: attribute
  prefix: /admin
  name_prefix: admin_
```

## YAML routes

`config/routes.yaml` (or `config/routes.yml`)

```yaml
home:
  path: /
  controller: App\Controller\HomeController::index
  methods: [GET]

user_show:
  path: /user/{id}
  controller: App\Controller\UserController::show
  requirements: { id: '\d+' }

page:
  path: /page/{slug}
  controller: App\Controller\PageController::show
  defaults: { slug: home }

admin:
  resource: routes/admin.yaml
  prefix: /admin
  name_prefix: admin_
```

| Key | Description |
|---|---|
| `path` | URL pattern, placeholders written `{name}` |
| `controller` | `Class::method`, or an invokable class |
| `methods` | allowed HTTP methods (all when omitted) |
| `requirements` | regex per placeholder (default `[^/]+`) |
| `defaults` | default values; a trailing placeholder with a default is optional |
| `options` | free options |
| `middlewares` | middlewares of the route; on an import, they run before the ones of the imported routes |
| `resource` | imports another routes file or a controllers directory (relative to the current file) |
| `type` | `yaml` or `attribute` (default: `attribute` for a directory or a `.php` file, `yaml` otherwise) |
| `prefix` / `name_prefix` | prefix applied to the imported paths / names |

Configuration placeholders (`%env(...)%`, `%kernel.*%`...) are resolved in the routes files (see the Config documentation).

A route name must be unique: a name defined twice (in YAML, in attributes, or both) throws a `RoutingException` that gives both locations.

## Matching

Routes are tested in the order they are declared. A path that matches no route throws a `RouteNotFoundException` (404). A path that matches with the wrong HTTP method throws a `MethodNotAllowedException` (405).

On a match, the kernel adds the route parameters, `_route` and `_controller` to `$request->attributes`, and the parameters are passed to the controller arguments (see the Controller documentation).

## Generating URLs

```php
$this->generateUrl('user_show', ['id' => 42]);
$this->generateUrl('blog_index', ['page' => 2]);
$this->redirectToRoute('blog_show', ['slug' => 'hello'], 301);
```

The first call returns `/user/42`; the parameters that are not placeholders are added to the query string (`/blog/?page=2`). A trailing placeholder equal to its default value is removed. A missing parameter, or a value that does not match its requirement, throws a `RouteNotDefinedException`.

`RoutingController` trait (part of `AbstractController`):

| Method | Returns |
|---|---|
| `generateUrl(string $route, array $parameters = [], bool $absolute = false)` | `string` |
| `redirectToRoute(string $route, array $parameters = [], int $status = 302)` | `RedirectResponse` |

View helpers (PHP and Twig templates, see the View documentation):

| Helper | Returns |
|---|---|
| `path(string $name, array $parameters = [])` | path |
| `url(string $name, array $parameters = [])` | absolute URL |

```twig
<a href="{{ path('blog_show', {slug: post.slug}) }}">{{ post.title }}</a>
```

```php
<a href="<?= $this->e($this->path('blog_show', ['slug' => $post->getSlug()])) ?>">Read</a>
```

## Absolute URLs

For links written outside of the page (emails, feeds, API responses, console output), generate an absolute URL:

```php
$this->generateUrl('post_show', ['slug' => $post->getSlug()], true);
$routing->generate('post_show', ['slug' => 'hello'], true);
```

```twig
<a href="{{ url('post_show', {slug: post.slug}) }}">Read</a>
```

The base URL is the scheme and host of the current request. Outside of an HTTP request (console commands, emails sent from the console), it is the `url` option of `config/framework/app.yaml`:

```yaml
url: '%env(APP_URL)%'
```

```dotenv
APP_URL=https://example.com
```

`APP_URL` is created by `neo install`. Without a base URL, or with an invalid one, a `RoutingException` explains what to configure. `RoutingInterface::setBaseUrl()` replaces the base URL (a string, a closure or `null`).

When the application is installed in a sub-directory (`https://example.com/app/`), the generated paths start with it (`/app/posts/1`); it comes from `Request::getBasePath()` and can be replaced with `RoutingInterface::setBasePath()`. The routes themselves are written without it.

## Cache

Routes are compiled into `var/cache/routing/routes.{env}.php`.

| Mode | Behavior |
|---|---|
| debug | the cache is rebuilt when a routes file, a controller, a `.env` file or the installed packages change |
| production | the cache is built once, on the first request, and never checked again |

In production, run `php bin/neo cache:clear` on every deployment (see the Kernel documentation).

## Listing routes

```bash
php bin/neo route:list
php bin/neo routes admin
```

`route:list` (alias `routes`) lists the name, methods, path and controller of each route; the optional argument filters on the name, the path or the controller.

## API

### RoutingInterface

`NeoPHP\Component\Routing\Contract\RoutingInterface` (service, also aliased as `RoutingManager`):

| Method | Description |
|---|---|
| `match(string $method, string $path): RouteMatch` | finds the route of a request |
| `generate(string $name, array $parameters = [], bool $absolute = false): string` | path or absolute URL of a route |
| `setBaseUrl(Closure\|string\|null $baseUrl): static` | base URL of the absolute URLs |
| `setBasePath(Closure\|string\|null $basePath): static`, `getBasePath(): string` | sub-directory added to the generated paths |
| `getBaseUrl(): string` | current base URL |
| `add(Route $route): static` | adds a route |
| `loadYaml(string $file): static` | loads a routes file |
| `getRoutes(): RouteCollection` | all routes |

`RoutingManager::__construct(?YamlInterface $yaml = null, ?RouteCollection $routes = null, ?callable $resolver = null)`.

```php
$routing->add(new Route('health', '/health', HealthController::class, ['GET']));
```

### Route

`NeoPHP\Component\Routing\Route\Route::__construct(string $name, string $path, mixed $controller, array $methods = [], array $requirements = [], array $defaults = [], array $options = [])`.

| Method | Description |
|---|---|
| `getName()`, `getPath()`, `getController()`, `getMethods()`, `getRequirements()`, `getDefaults()`, `getOptions()` | definition |
| `getOption(string $name, mixed $default = null)` | one option (`middlewares` holds the route middlewares) |
| `allowsMethod(string $method): bool` | method allowed |
| `getVariables(): array` | placeholder names |
| `getRegex(): string` | compiled regex |
| `match(string $path): ?array` | parameters, or `null` |
| `generate(array $parameters = []): string` | path |
| `getSource()` / `setSource()` | file where the route is declared |
| `Route::normalizePath(string $path): string` | leading slash, no trailing slash |

### RouteMatch and RouteCollection

`RouteMatch` has the public properties `$route` and `$parameters`, and `getName()`, `getController()`.

`RouteCollection` (iterable, countable): `add(Route)`, `addCollection(RouteCollection)`, `get(string $name): ?Route`, `has()`, `remove()`, `all()`.

### Loaders and cache

| Class | Description |
|---|---|
| `Loader\YamlRouteLoader` | `__construct(YamlInterface $yaml, ?callable $resolver = null)`, `load(string $file): RouteCollection`, `getResources()` |
| `Loader\AttributeRouteLoader` | `load(string $path): RouteCollection` (directory or file), `loadClass(string $class): RouteCollection`, `getResources()` |
| `Cache\RouteCache` | `__construct(string $file, bool $debug = false)`, `load(callable $builder): RouteCollection`, `getFile()`, `clear()` |

## Exceptions

| Exception | Status | Thrown when |
|---|---|---|
| `RoutingException` (extends `FrameworkException`) | 500 | invalid routes file, duplicate route name, missing base URL |
| `RouteNotFoundException` | 404 | no route matches the path |
| `MethodNotAllowedException` | 405 | the path matches with another method; `getAllowedMethods()` |
| `RouteNotDefinedException` | 500 | unknown route name, missing or invalid parameter in `generate()` |

## Changelog

- v1.31.0 — generated paths contain the sub-directory of the application (`setBasePath()`, `getBasePath()`).
- Bugfix after v1.17.0 — Absolute URLs: `generate(..., true)`, `generateUrl(..., true)`, `url()` view helper, `framework.app.url` / `APP_URL` for the console, `setBaseUrl()`.
- v1.9.1 — Attribute routes discovered with the kernel `ClassFinder` and routes cache stored with `ResourceCache`; an old routes cache is rebuilt automatically.
- v1.7.0 — `middlewares` option on routes, imports and `#[Route]`.
- v1.5.0 — `#[Route]` attribute, controllers imported with `type: attribute`, routes cache, duplicate route names detected.
- v1.4.0 — `generateUrl()` and `redirectToRoute()` in the `RoutingController` trait.
- v1.2.0 — `path()` view helper.
- v1.0.0 — YAML routes, imports, requirements, defaults, 404 / 405, URL generation, `route:list`.