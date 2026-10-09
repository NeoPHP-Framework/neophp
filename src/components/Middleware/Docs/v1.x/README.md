# Middleware

A middleware runs before and after the controller. It can modify the request, return a response without calling the controller, or modify the response.
Middlewares are global (every request) or attached to routes, and are referenced by class, alias or group.

## Summary

- [Writing a middleware](#writing-a-middleware)
- [Global middlewares](#global-middlewares)
- [Aliases and groups](#aliases-and-groups)
- [Route middlewares](#route-middlewares)
- [Order of execution](#order-of-execution)
- [Listing middlewares](#listing-middlewares)
- [API](#api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Writing a middleware

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Attribute\AsMiddleware;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Contract\RequestHandlerInterface;
use NeoPHP\Component\Session\Contract\SessionInterface;

#[AsMiddleware(name: 'auth')]
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(protected SessionInterface $session)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$this->session->has('user_id')) {
            return new RedirectResponse('/login');
        }

        $response = $handler->handle($request);
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }
}
```

The interfaces mirror PSR-15 without dependency:

| Interface | Method |
|---|---|
| `Contract\MiddlewareInterface` | `process(Request $request, RequestHandlerInterface $handler): Response` |
| `Contract\RequestHandlerInterface` | `handle(Request $request): Response` (calls the next middleware, then the controller) |

Middlewares are built by the container: their dependencies are autowired (see the Container documentation). A class that does not implement `MiddlewareInterface` throws a `MiddlewareException`.

## Global middlewares

Global middlewares run on every request, before the routing (they also run for a 404).

`config/framework/middleware.yaml`

```yaml
global:
  - App\Middleware\MaintenanceMiddleware

aliases:
  auth: App\Middleware\AuthMiddleware

groups:
  admin: [auth, App\Middleware\AdminMiddleware]
```

| Key | Description |
|---|---|
| `global` | middlewares (classes, aliases or groups) run on every request, in this order |
| `aliases` | short name => class |
| `groups` | name => list of middlewares (classes, aliases or other groups) |

A middleware can also declare itself with `#[AsMiddleware]`, discovered in `src/`:

| Argument | Default | Description |
|---|---|---|
| `name` | `null` | alias of the middleware (`auth`) |
| `global` | `false` | runs the middleware on every request |
| `priority` | `0` | order of the global middlewares declared with the attribute (highest first) |

```php
#[AsMiddleware(global: true, priority: 10)]
class MaintenanceMiddleware implements MiddlewareInterface
```

The global middlewares of `middleware.yaml` run first, in their order, then the global middlewares declared with `#[AsMiddleware]`. An alias of `middleware.yaml` wins over an alias declared with the attribute. The discovery is cached in `var/cache/middleware/` (rebuilt in debug when a file of `src/` changes).

## Aliases and groups

A middleware is referenced by its alias, a group or its class name, everywhere: `global`, groups, routes and attributes. A group is expanded into its middlewares, recursively; a group that contains itself throws a `MiddlewareException`.

## Route middlewares

With the `#[Middleware]` attribute, on the controller class or on an action (repeatable, variadic):

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\TwoFactorMiddleware;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Attribute\Middleware;
use NeoPHP\Component\Routing\Attribute\Route;

#[Middleware('auth')]
class AdminController extends AbstractController
{
    #[Route('/admin/users', name: 'admin_users', middlewares: ['audit'])]
    #[Middleware('admin', TwoFactorMiddleware::class)]
    public function users(): Response
    {
        return $this->render('admin/users');
    }
}
```

With the `middlewares` option of `#[Route]` or of `config/routes.yaml` (see the Routing documentation):

```yaml
admin:
  resource: ../src/Admin/Controller/
  type: attribute
  prefix: /admin
  middlewares: [admin]

legacy:
  path: /legacy
  controller: App\Controller\LegacyController::index
  middlewares: [auth]
```

## Order of execution

1. global middlewares of `middleware.yaml`
2. global middlewares declared with `#[AsMiddleware(global: true)]`
3. `middlewares` of the YAML import, then of the YAML route
4. `middlewares` of `#[Route]` on the class, then on the method
5. `#[Middleware]` on the class, then on the method
6. the controller

A middleware is never run twice: a middleware already global is ignored on the route, and a middleware listed twice runs once. An unknown alias throws a `MiddlewareException`.

## Listing middlewares

```bash
php bin/neo middleware:list
```

Displays the global middlewares in their order, the aliases and the groups.

## API

`NeoPHP\Component\Middleware\Contract\MiddlewareManagerInterface` (service, also aliased as `MiddlewareManager`):

| Method | Description |
|---|---|
| `handle(Request $request, array $middlewares, callable $handler): Response` | runs the middlewares, then `$handler(Request): Response` |
| `resolve(array $middlewares): array` | expands aliases and groups into unique class names |
| `getGlobal(): array` | classes of the global middlewares |
| `forController(mixed $controller, array $middlewares = []): array` | classes of the route middlewares: `$middlewares` then the `#[Middleware]` attributes of the controller, without the global ones |
| `addAlias(string $name, string $class): static` | adds an alias |
| `addGroup(string $name, array $middlewares): static` | adds a group |
| `addGlobal(string $middleware): static` | adds a global middleware |
| `getAliases(): array` | name => class |
| `getGroups(): array` | name => middlewares |

`MiddlewareManager::__construct(?ContainerInterface $container = null, array $global = [], array $aliases = [], array $groups = [])`.

Other classes:

| Class | Description |
|---|---|
| `Pipeline\Pipeline` | `RequestHandlerInterface` running a list of middleware classes: `__construct(array $middlewares, callable $handler, callable $resolver)` |
| `Discovery\MiddlewareDiscovery` | `__construct(array $paths)`, `discover(): array` (`['aliases' => [...], 'global' => [...]]`), `getResources()` |

## Exceptions

`NeoPHP\Component\Middleware\Exception\MiddlewareException` extends `FrameworkException`. It is thrown for an unknown middleware, a class that does not implement `MiddlewareInterface`, or a circular group.

## Changelog

- v1.7.0 — Middlewares: PSR-15 style interfaces, global middlewares (`middleware.yaml`, `#[AsMiddleware]`), aliases and groups, route middlewares (`#[Middleware]`, `#[Route(middlewares)]`, `routes.yaml`), `middleware:list` command.