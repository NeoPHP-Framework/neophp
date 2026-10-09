# Kernel

The Kernel component boots the application: it loads the environment, discovers the modules of the framework and of the installed packages, builds the container and turns a `Request` into a `Response`.
It also dispatches the kernel events and ships shared tools used by other modules: the module attributes, class discovery, a file-based resource cache and the `cache:clear` command.

## Summary

- [Module](#module)
- [Application kernel](#application-kernel)
- [Request lifecycle](#request-lifecycle)
- [Modules](#modules)
- [Providers](#providers)
- [Parameters](#parameters)
- [Kernel events](#kernel-events)
- [The app variable](#the-app-variable)
- [Class discovery](#class-discovery)
- [Resource cache](#resource-cache)
- [Clearing the cache](#clearing-the-cache)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Kernel\KernelManager` (`final`) |
| Interface | `NeoPHP\Component\Kernel\KernelManagerInterface` |
| Attribute | `#[Component(provider: KernelProvider::class, requires: [ContainerManager::class, ExceptionManager::class, YamlManager::class, DotenvManager::class, ConfigManager::class, HttpManager::class, EventManager::class, MiddlewareManager::class, RoutingManager::class, ControllerManager::class])]` |
| Requires | Container, Exception, Yaml, Dotenv, Config, Http, Event, Middleware, Routing, Controller |

Inject `KernelManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Kernel\KernelManagerInterface;

public function __construct(private KernelManagerInterface $kernel)
{
}
```

The kernel is always loaded and cannot be disabled.

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` (provider, discoveries, traces, `Helper/View`, `Helper/Console`, `Helper/Event`, `Helper/WebProfiler`) are used by the framework only.

## Application kernel

`neo install` creates `src/Kernel.php`, which extends `AbstractKernel` (`KernelManager` is `final`):

```php
<?php

declare(strict_types=1);

namespace App;

use NeoPHP\Component\Kernel\Contract\AbstractKernel;

class Kernel extends AbstractKernel
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

### KernelManagerInterface

`AbstractKernel` implements `NeoPHP\Component\Kernel\KernelManagerInterface`:

| Method | Description |
|---|---|
| `boot(): void` | registers the error handler, builds the container and registers then boots the providers (only once) |
| `handle(Request $request): Response` | boots the kernel and returns the response of the request, errors included |
| `run(): void` | builds the request from the globals, handles it, sends the response and calls `terminate()` |
| `terminate(Request $request, Response $response): void` | dispatches `TerminateEvent` |
| `getContainer(): ContainerManagerInterface` | the container; throws a `KernelException` before `boot()` |
| `getRootPath(): string` | project root |
| `getConfigPath(): string` | `<root>/config` |
| `getPublicPath(): string` | `<root>/public` |
| `getTemplatesPath(): string` | `<root>/templates` |
| `getCachePath(): string` | `<root>/var/cache` |
| `getEnvironment(): string` | `dev`, `prod`, `test`... |
| `isDebug(): bool` | debug mode |
| `getVersion(): string` | installed version of `neophp/framework` (Composer), or `AbstractKernel::VERSION` |
| `getParameters(): array` | the `kernel.*` parameters |
| `getModules(): array` | the enabled modules, in their loading order: class => `type`, `provider`, `requires`, `namespace` |
| `isEnabled(string $class): bool` | whether the module a class belongs to is enabled |

The kernel is registered in the container as `KernelManagerInterface`, as its own class and as `KernelManager`, so it can be injected in any service:

```php
public function __construct(protected KernelManagerInterface $kernel)
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

## Modules

Every component, package and process is a module. Its entry point is a `final` class at the root of its directory, its manager, declared with an attribute of `NeoPHP\Component\Kernel\Attribute\`:

| Attribute | Module |
|---|---|
| `#[Component(provider: ..., requires: [...])]` | component (`src/components/<Name>/`) |
| `#[Package(provider: ..., requires: [...])]` | package (`src/packages/<Name>/`, or an installed Composer package) |
| `#[Process(provider: ..., requires: [...])]` | process (`src/process/<Name>/`) |

- `provider`: the provider registering the services of the module (`ProviderInterface`).
- `requires`: the modules this one needs to work. They are loaded before it.

```php
<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash;

use NeoPHP\Component\Flash\Provider\FlashProvider;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Session\SessionManager;

#[Component(provider: FlashProvider::class, requires: [SessionManager::class])]
final class FlashManager implements FlashManagerInterface
{
    // ...
}
```

### Discovery

The kernel registers every module it finds, nothing is listed by hand:

- the framework: the classes at the root of `src/{components,packages,process}/<Name>/` carrying a module attribute (one module per directory);
- the installed packages and the application: the classes listed in `extra.neophp.modules` of their `composer.json`:

```json
{
    "name": "acme/billing",
    "extra": {
        "neophp": {
            "modules": ["Acme\\Billing\\BillingManager"]
        }
    }
}
```

The providers are registered in the order of the dependencies: a module is always loaded after the modules it requires (then components, packages and processes, by class name). A dependency cycle throws a `KernelException`.

The result is cached in `var/cache/kernel/modules.<env>.php`. In debug, it is rebuilt when a module, `config/config.php` or a `composer.json` changes; in production, run `php bin/neo cache:clear` after a deployment.

### Enabling and disabling modules

Every discovered module is enabled. `config/config.php` lists only the changes of the project:

```php
<?php

declare(strict_types=1);

return [
    NeoPHP\Package\WebProfiler\WebProfilerManager::class => ['dev' => true],
    NeoPHP\Package\NeoAI\NeoAiManager::class => ['dev' => true],
    NeoPHP\Package\Markdown\MarkdownManager::class => false,
];
```

| Value | Module |
|---|---|
| `true` | enabled (the default) |
| `false` | disabled |
| `['dev' => true, 'test' => true]` | enabled only in the listed environments |
| `['all' => true, 'prod' => false]` | `all` applies to the environments that are not listed |

A module required by an enabled module cannot be disabled: the kernel throws a `KernelException` naming both modules. The kernel itself (`KernelManager`) requires Container, Exception, Yaml, Dotenv, Config, Http, Event, Middleware, Routing and Controller, and cannot be disabled. `bin/neo` stops with a message when the Console process is disabled.

The kernel also throws a `KernelException` when a key of `config/config.php` is not a module, when a value is neither a boolean nor an array of environment => boolean, when a module class is not `final`, has an invalid provider, requires a class that is not a module, or when a directory declares several modules.

### Disabled modules

The classes of a disabled module are ignored by the discoveries of the other modules: view helpers, listeners, console commands and profiler elements. `isEnabled()` tells whether the module of a class is enabled:

```php
$kernel->isEnabled(FlashesViewHelper::class); // false when FlashManager is disabled
```

A class of the application, or of a namespace that belongs to no module, is always enabled.

### Helpers

The integration of a module with another one lives in `Helper/<Module>/` of the integrated module, the folder being named after the module it plugs into: `Flash/Helper/View/`, `Flash/Helper/WebProfiler/`, `Routing/Helper/Console/`... A helper may use the internals of its own module, but only the public API of the other one, and it is loaded only when both modules are enabled.

## Providers

Each module registers its services with a provider implementing `NeoPHP\Component\Container\Contract\ProviderInterface` (`register(ContainerManagerInterface $container)` then `boot(ContainerManagerInterface $container)`, see the Container documentation). The kernel registers the providers of the enabled modules, then the providers returned by `providers()`:

```php
<?php

declare(strict_types=1);

namespace App;

use App\Provider\BillingProvider;
use NeoPHP\Component\Kernel\Contract\AbstractKernel;

class Kernel extends AbstractKernel
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

The events are in `NeoPHP\Component\Kernel\Event\` and extend `KernelEvent`, which gives `getKernel(): KernelManagerInterface` and `getRequest(): Request`. Listen to them like any event (see the Event documentation).

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

Every template receives the global `app` variable (`NeoPHP\Component\Kernel\Helper\View\AppVariable`). Each value is read when the template uses it:

| Twig | PHP template | Value |
|---|---|---|
| `app.name` | `$app->getName()` | `framework.app.name` (`APP_NAME`) |
| `app.environment` | `$app->getEnvironment()` | kernel environment (`dev`, `prod`...) |
| `app.debug` | `$app->getDebug()` | debug mode |
| `app.request` | `$app->getRequest()` | current `Request`, `null` outside of an HTTP request |
| `app.session` | `$app->getSession()` | `SessionManagerInterface`, `null` without the Session component |
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

`NeoPHP\Component\Kernel\Exception\KernelException` extends `FrameworkException`. It is thrown for an invalid module or provider, an invalid `config/config.php`, a required module that is disabled, a dependency cycle, a container used before `boot()`, or a cache file that cannot be written.

## Changelog

- v2.0.0 — `KernelManagerInterface` replaces `Contract\KernelInterface`; `AppVariable` moves to `Helper\View\AppVariable`; modules: `#[Component]`, `#[Package]` and `#[Process]` attributes on `final` managers, automatic discovery of the framework modules and of the Composer packages (`extra.neophp.modules`), providers sorted by `requires`, `config/config.php` to disable modules per project and per environment, `getModules()` and `isEnabled()`, classes of disabled modules ignored by the discoveries, `Helper/<Module>/` convention; `KernelManager` is `final`, the application kernel extends `AbstractKernel`.
- v1.32.0 — global `app` template variable (`AppVariable`): request, session, user, flashes, locale, environment, debug, current route and its parameters.
- v1.9.1 — `ClassFinder` and `ResourceCache` shared with the other features (routing uses them).
- v1.9.0 — Kernel events `RequestEvent`, `ControllerEvent`, `ResponseEvent`, `ExceptionEvent`, `TerminateEvent`, replacing `TerminableInterface`.
- v1.5.0 — `cache:clear` command.
- v1.1.0 — Uncaught errors logged in the `framework` channel.
- v1.0.0 — Kernel: boot, providers, container, request handling, `kernel.*` parameters.