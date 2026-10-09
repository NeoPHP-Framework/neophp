# Container

The Container component is the dependency injection container of NeoPHP.
It autowires constructors, shares services by default, reads the `#[Autowire]` and `#[Inject]` attributes and is filled by feature providers and `config/services.yaml`.

## Summary

- [Autowiring](#autowiring)
- [#[Autowire]](#autowire)
- [#[Inject]](#inject)
- [services.yaml](#servicesyaml)
- [Container API](#container-api)
- [Providers](#providers)
- [Controller trait](#controller-trait)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Autowiring

Every class can be injected: the container reads the constructor and gives each parameter the service of its type. Controllers, middlewares, commands, listeners and view helpers are built this way.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MailerInterface;
use NeoPHP\Component\Controller\Contract\AbstractController;

class NewsletterController extends AbstractController
{
    public function __construct(protected MailerInterface $mailer)
    {
    }
}
```

A service is shared: the same instance is given everywhere during the request. `#[Autowire(shared: false)]` on the class, or `shared: false` in `services.yaml`, creates a new instance each time.

## #[Autowire]

`NeoPHP\Component\Container\Attribute\Autowire`, on a constructor parameter, says what to inject when the type is not enough:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Container\Attribute\Autowire;

class SmtpMailer implements MailerInterface
{
    public function __construct(
        #[Autowire(service: 'mailer.transport')] protected TransportInterface $transport,
        #[Autowire(config: 'framework.app.name')] protected string $appName,
        #[Autowire(env: 'MAILER_DSN')] protected string $dsn,
        #[Autowire(param: 'kernel.debug')] protected bool $debug,
        #[Autowire('%kernel.root_path%/var/mails')] protected string $spool,
    ) {
    }
}
```

| Argument | Injected value |
|---|---|
| `value` (first argument) | a value; placeholders (`%env(...)%`, `%kernel.*%`, `%config.key%`) are resolved |
| `service` | the service with this id |
| `config` | a configuration value (`framework.app.name`) |
| `env` | an environment variable |
| `param` | a kernel parameter (`kernel.debug`, `kernel.root_path`...) |
| `shared` | on the class only: `false` creates a new instance each time |

A missing service, configuration key or environment variable throws a `ContainerException`, unless the parameter is nullable (it then receives `null`).

`#[Autowire]` is read on constructor parameters and callables run with `call()`, not on the parameters of a controller action: there, only the type-hint is used (see the Controller documentation).

## #[Inject]

`NeoPHP\Component\Container\Attribute\Inject`, on a property, injects the value after the constructor. Without argument, the type of the property is used. It accepts `service`, `config`, `env`, `param` and `value`.

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Container\Attribute\Inject;
use NeoPHP\Component\Logger\Contract\LoggerInterface;

class ReportService
{
    #[Inject]
    protected LoggerInterface $logger;

    #[Inject(config: 'framework.app.name')]
    protected string $appName;
}
```

## services.yaml

Application services are declared in `config/services.yaml` (read by the Service component):

```yaml
services:
  _defaults:
    shared: true

  App\:
    resource: ../src/
    exclude:
      - ../src/Kernel.php

  App\Service\SmtpMailer:
    arguments:
      $host: '%env(MAIL_HOST)%'
      $logger: '@NeoPHP\Component\Logger\Contract\LoggerInterface'
    calls:
      - [setFrom, ['noreply@example.com']]

  mailer: '@App\Service\SmtpMailer'

  App\Service\NotifierInterface: '@App\Service\SmsNotifier'

  app.api_client:
    class: App\Service\ApiClient
    factory: ['@App\Service\ApiClientFactory', 'create']
    arguments:
      $baseUrl: 'https://api.example.com'
    shared: false
```

| Entry | Description |
|---|---|
| `_defaults.shared` | default value of `shared` for the file |
| `Namespace\: { resource, exclude, shared }` | registers every class of a directory (`exclude`: files, directories or `*` patterns, relative to the file) |
| `id: ~` | registers a class with its default values |
| `id: '@other'` / `id: { alias: other }` | alias of another service |
| `class` | class of the service (default: the id) |
| `arguments` | arguments by name (`$host`) or by position; the others are autowired |
| `calls` | methods called after the construction: `[method, [arguments]]` |
| `factory` | `['@service', 'method']`, `['Class', 'method']` or `'Class::method'` |
| `shared` | `false` creates a new instance each time |

In arguments, `@id` is a service, `@?id` a service or `null` when it does not exist, `@@text` the string `@text`, and `%...%` the configuration placeholders.

When one class of the file implements an interface, the interface is bound to it automatically. When several classes implement it, resolving the interface throws a `ServiceException` that asks for an alias. An interface already bound by the framework is never replaced.

`services.yaml` is compiled into `var/cache/service/services.{env}.php`, rebuilt in debug when the file or a class of a `resource` changes. In production, run `php bin/neo cache:clear` on every deployment. `php bin/neo service:list` and `php bin/neo debug:container` list the services.

## Container API

Inject `NeoPHP\Component\Container\Contract\ContainerInterface` (implemented by `ContainerManager`). The configuration is registered under the id `config`.

| Method | Description |
|---|---|
| `get(string $id): mixed` | returns a service (built and autowired when needed) |
| `has(string $id): bool` | whether the id is bound, aliased or an existing class |
| `bind(string $id, mixed $concrete = null, bool $shared = false): static` | binds an id to a class, a closure `fn (ContainerInterface $c)` or a value |
| `singleton(string $id, mixed $concrete = null): static` | `bind()` with `shared: true` |
| `instance(string $id, mixed $value): static` | registers an existing value |
| `alias(string $alias, string $id): static` | makes `$alias` point to `$id` |
| `bound(string $id): bool` | whether the id is bound or registered |
| `resolved(string $id): bool` | whether a shared instance already exists |
| `make(string $id, array $parameters = []): mixed` | builds a new instance, with named parameters |
| `instantiate(string $class, array $parameters = []): object` | builds a class with autowiring and `#[Inject]` |
| `inject(object $object): object` | fills the `#[Inject]` properties of an object |
| `call(callable\|array\|string $callable, array $parameters = []): mixed` | calls a callable with autowired arguments (`'Class::method'`, `[$object, 'method']`...) |
| `getDefinitions(): array` / `getAliases(): array` | registered definitions and aliases |

`AbstractContainer` also exposes `resolveArguments(ReflectionFunctionAbstract $function, array $parameters = []): array`.

```php
$container->singleton(ClockInterface::class, SystemClock::class);
$container->bind('app.report', static fn (ContainerInterface $c): Report => new Report($c->get(LoggerInterface::class)));
$container->alias('clock', ClockInterface::class);

$report = $container->make(Report::class, ['title' => 'Monthly']);
$total = $container->call([$calculator, 'total'], ['year' => 2026]);
```

## Providers

Each feature registers its services with a provider implementing `ProviderInterface` (`register()` then `boot()`). `AbstractProvider` gives an empty `boot()`.

```php
<?php

declare(strict_types=1);

namespace App\Provider;

use App\Service\SystemClock;
use App\Service\ClockInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;

class ClockProvider extends AbstractProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(ClockInterface::class, SystemClock::class);
    }
}
```

## Controller trait

`NeoPHP\Component\Container\Helper\Controller\ContainerController` adds `setContainer(ContainerInterface $container)`, `get(string $id)` and `has(string $id)` to a controller. It is required by every other controller trait (see the Controller documentation).

## Exceptions

| Exception | Thrown when |
|---|---|
| `NeoPHP\Component\Container\Exception\ContainerException` | a class cannot be built, a parameter cannot be resolved, an alias points to itself |
| `NeoPHP\Component\Container\Exception\NotFoundException` | an id is not found (`NotFoundException::forId($id)`); extends `ContainerException` |

## Changelog

- v1.14.0 — `getDefinitions()` and `getAliases()` on the container.
- v1.8.0 — `#[Autowire]` on parameters and classes, `#[Inject]` on properties, `config/services.yaml`, shared services by default, interfaces bound to their single implementation, `service:list` command.
- v1.4.0 — `ContainerController` trait for controllers.
- v1.0.0 — Dependency injection container with autowiring and providers.