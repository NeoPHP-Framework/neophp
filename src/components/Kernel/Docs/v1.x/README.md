# Kernel

The Kernel component boots the application: it loads the environment, builds the container, registers the providers and turns a `Request` into a `Response`.
It also dispatches the kernel events and ships shared tools used by other features: class discovery, a file-based resource cache and the `cache:clear` command.

## Summary

- [Application kernel](#application-kernel)
- [Request lifecycle](#request-lifecycle)
- [Providers](#providers)
- [Parameters](#parameters)
- [Kernel events](#kernel-events)
- [The app variable](#the-app-variable)
- [Class discovery](#class-discovery)
- [Resource cache](#resource-cache)
- [Clearing the cache](#clearing-the-cache)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Application kernel

`neo install` creates `src/Kernel.php`, which extends `KernelManager`:

```php
<?php

declare(strict_types=1);

namespace App;

use NeoPHP\Component\Kernel\KernelManager;

class Kernel extends KernelManager
{
}
```

`public/index.php` runs it:

```php
<?php

declare(strict_types=1);

use App\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Kernel())->run();
```

The constructor is `__construct(?string $environment = null, ?bool $debug = null, ?string $rootPath = null)`:

| Argument | Default |
|---|---|
| `$environment` | `APP_ENV`, or `dev` |
| `$debug` | `APP_DEBUG`, or `true` when the environment is not `prod` |
| `$rootPath` | the first parent directory of the kernel class containing a `composer.json` that is not the framework one |

The `.env` files are loaded by the constructor (see the Config documentation).

### KernelInterface

`KernelManager` extends `AbstractKernel`, which implements `NeoPHP\Component\Kernel\Contract\KernelInterface`:

| Method | Description |
|---|---|
| `boot(): void` | registers the error handler, builds the container and registers then boots the providers (only once) |
| `handle(Request $request): Response` | boots the kernel and returns the response of the request, errors included |
| `run(): void` | builds the request from the globals, handles it, sends the response and calls `terminate()` |
| `terminate(Request $request, Response $response): void` | dispatches `TerminateEvent` |
| `getContainer(): ContainerInterface` | the container; throws a `KernelException` before `boot()` |
| `getRootPath(): string` | project root |
| `getConfigPath(): string` | `<root>/config` |
| `getPublicPath(): string` | `<root>/public` |
| `getTemplatesPath(): string` | `<root>/templates` |
| `getCachePath(): string` | `<root>/var/cache` |
| `getEnvironment(): string` | `dev`, `prod`, `test`... |
| `isDebug(): bool` | debug mode |
| `getVersion(): string` | installed version of `neophp/framework` (Composer), or `AbstractKernel::VERSION` |
| `getParameters(): array` | the `kernel.*` parameters |

The kernel is registered in the container as `KernelInterface`, as its own class and as `KernelManager`, so it can be injected in any service:

```php
public function __construct(protected KernelInterface $kernel)
{
}
```

PHP warnings and notices are converted into `ErrorException` (deprecations are ignored).

## Request lifecycle

`handle()` runs these steps:

1. `RequestEvent` is dispatched; a listener can answer directly.
2. The global middlewares run (see the Middleware documentation).
3. The route is matched (see the Routing documentation); the route parameters, `_route` and `_controller` are added to `$request->attributes`.
4. The route middlewares run.
5. `ControllerEvent` is dispatched, then the controller is called (see the Controller documentation).
6. `ResponseEvent` is dispatched for every response, errors included.

When an exception is thrown, `ExceptionEvent` is dispatched; without a response set by a listener, the error page is rendered as HTML, or as JSON when the request sends `Accept: application/json` (see the Exception documentation). Errors with a status of 500 or more are logged in the `framework` channel when it exists (see the Logger documentation).

After the response is sent, `run()` calls `terminate()`, which dispatches `TerminateEvent`.

## Providers

Each feature registers its services with a provider implementing `NeoPHP\Component\Container\Contract\ProviderInterface` (`register(ContainerInterface $container)` then `boot(ContainerInterface $container)`, see the Container documentation). The kernel registers the core providers, then the providers returned by `providers()`:

```php
<?php

declare(strict_types=1);

namespace App;

use App\Provider\BillingProvider;
use NeoPHP\Component\Kernel\KernelManager;

class Kernel extends KernelManager
{
    protected function providers(): iterable
    {
        return [BillingProvider::class];
    }
}
```

A provider can be a class name or an instance. Anything else throws a `KernelException`. All providers are registered before the first one is booted.

## Parameters

The kernel parameters are registered in the container and usable as `%kernel.*%` placeholders in the configuration:

| Parameter | Value |
|---|---|
| `kernel.root_path` | `getRootPath()` |
| `kernel.config_path` | `getConfigPath()` |
| `kernel.public_path` | `getPublicPath()` |
| `kernel.templates_path` | `getTemplatesPath()` |
| `kernel.cache_path` | `getCachePath()` |
| `kernel.environment` | `getEnvironment()` |
| `kernel.debug` | `isDebug()` |
| `kernel.version` | `getVersion()` |

```php
$cachePath = (string) $this->get('kernel.cache_path');
```

```yaml
settings:
  path: '%kernel.root_path%/var/log'
```

## Kernel events

The events are in `NeoPHP\Component\Kernel\Event\` and extend `KernelEvent`, which gives `getKernel(): KernelInterface` and `getRequest(): Request`. Listen to them like any event (see the Event documentation).

| Event | When | Methods |
|---|---|---|
| `RequestEvent` | before the global middlewares | `getResponse(): ?Response`, `setResponse(Response)` (answers without routing and stops the propagation), `hasResponse()` |
| `ControllerEvent` | after the route middlewares, before the controller | `getController()`, `setController(mixed)`, `getParameters()`, `setParameters(array)` |
| `ResponseEvent` | for every response, errors included | `getResponse(): Response`, `setResponse(Response)` |
| `ExceptionEvent` | when an exception is thrown | `getThrowable()`, `setThrowable(Throwable)`, `getResponse(): ?Response`, `setResponse(Response)` (replaces the error page), `hasResponse()` |
| `TerminateEvent` | after the response is sent | `getResponse(): Response`; slow work (mails, logs) |

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Event\RequestEvent;

class MaintenanceListener
{
    #[AsListener]
    public function onRequest(RequestEvent $event): void
    {
        if (is_file($event->getKernel()->getRootPath() . '/var/maintenance')) {
            $event->setResponse(new Response('Back soon.', 503));
        }
    }
}
```

The framework uses them too: the queued cookies are added and the session is saved by listeners of `ResponseEvent`.

## The app variable

Every template receives the global `app` variable (`NeoPHP\Component\Kernel\AppVariable`). Each value is read when the template uses it:

| Twig | PHP template | Value |
|---|---|---|
| `app.name` | `$app->getName()` | `framework.app.name` (`APP_NAME`) |
| `app.environment` | `$app->getEnvironment()` | kernel environment (`dev`, `prod`...) |
| `app.debug` | `$app->getDebug()` | debug mode |
| `app.request` | `$app->getRequest()` | current `Request`, `null` outside of an HTTP request |
| `app.session` | `$app->getSession()` | `SessionInterface`, `null` without the Session component |
| `app.user` | `$app->getUser()` | logged in user, `null` when anonymous or without the Security package |
| `app.flashes` / `app.flashes('success')` | `$app->getFlashes()` | flash messages (read and removed, like `flashes()`) |
| `app.locale` | `$app->getLocale()` | current locale (`en` without the Translation package) |
| `app.current_route` | `$app->getCurrentRoute()` | name of the matched route, `null` when none |
| `app.current_route_parameters` | `$app->getCurrentRouteParameters()` | route parameters (without `_route` / `_controller`) |

```twig
<title>{{ app.name }}</title>

<a href="{{ path('home') }}" {% if app.current_route == 'home' %}aria-current="page"{% endif %}>Home</a>

{% if app.user %}
    {{ app.user.userIdentifier }}
{% endif %}

{{ app.request.query.get('q') }}
{{ app.request.attributes.get('_route') }}
```

`app.current_route` and `app.currentRoute` are equivalent.

## Class discovery

`NeoPHP\Component\Kernel\Discovery\ClassFinder` finds the classes declared in a directory (scanned recursively) or a PHP file, without loading them. It is used to discover attribute routes, middlewares, commands, listeners...

| Method | Description |
|---|---|
| `find(string $path, string\|array\|null $contains = null): array` | class names |
| `map(string $path, string\|array\|null $contains = null): array` | class name => file |
| `getResources(): array` | scanned files and directories => modification time, for a `ResourceCache` |

`$contains` keeps only the files containing one of the given strings, which avoids parsing the others.

```php
$finder = new ClassFinder();
$classes = $finder->find($rootPath . '/src', 'AsMiddleware');
```

## Resource cache

`NeoPHP\Component\Kernel\Cache\ResourceCache` stores an array in a PHP file with the resources it was built from.

| Method | Description |
|---|---|
| `__construct(string $file, bool $debug = false)` | cache file |
| `load(callable $builder): array` | returns the cached data; otherwise calls `$builder`, which returns `[$data, $resources]`, and writes the file |
| `getFile(): string` | cache file |
| `clear(): void` | removes the file |

In debug, the cache is rebuilt when a resource was modified or removed. Otherwise, it is built once and never checked again.

```php
$cache = new ResourceCache($cachePath . '/app/handlers.php', $debug);

$handlers = $cache->load(function () use ($rootPath): array {
    $finder = new ClassFinder();
    $classes = $finder->find($rootPath . '/src/Handler');

    return [$classes, $finder->getResources()];
});
```

## Clearing the cache

```bash
php bin/neo cache:clear
php bin/neo cc --env=prod -v
```

`cache:clear` (alias `cc`) removes every file of `var/cache/` (routes, Twig templates, discoveries...) except `.gitkeep`, and resets OPcache. `-v` lists the removed files. Run it on every deployment in production.

## Exceptions

`NeoPHP\Component\Kernel\Exception\KernelException` extends `FrameworkException`. It is thrown for an invalid provider, a container used before `boot()`, or a cache file that cannot be written.

## Changelog

- v1.32.0 — global `app` template variable (`AppVariable`): request, session, user, flashes, locale, environment, debug, current route and its parameters.
- v1.9.1 — `ClassFinder` and `ResourceCache` shared with the other features (routing uses them).
- v1.9.0 — Kernel events `RequestEvent`, `ControllerEvent`, `ResponseEvent`, `ExceptionEvent`, `TerminateEvent`, replacing `TerminableInterface`.
- v1.5.0 — `cache:clear` command.
- v1.1.0 — Uncaught errors logged in the `framework` channel.
- v1.0.0 — Kernel: boot, providers, container, request handling, `kernel.*` parameters.