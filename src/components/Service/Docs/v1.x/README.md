# Service

The Service component registers application services in the container from `config/services.yaml`: whole directories, explicit arguments, method calls, factories and aliases.
Interfaces implemented by a single registered class are bound automatically, and the whole file is compiled into a cache.

## Summary

- [Autowiring](#autowiring)
- [Configuration file](#configuration-file)
- [Arguments](#arguments)
- [Interfaces](#interfaces)
- [Cache](#cache)
- [Command](#command)
- [API](#api)
- [Changelog](#changelog)

## Autowiring

Every class can be injected without configuration: the container reads the constructor and gives each parameter the service of its type. Controllers, middlewares, commands, listeners and view helpers are built this way.

```php
class NewsletterController extends AbstractController
{
    public function __construct(protected MailerInterface $mailer)
    {
    }
}
```

A service is shared: the same instance is given everywhere during the request. `#[Autowire(shared: false)]` on the class, or `shared: false` in `services.yaml`, creates a new instance each time.

### #[Autowire] and #[Inject]

`#[Autowire]` on a constructor parameter and `#[Inject]` on a property say what to inject when the type is not enough. Both come from the Container component (see the Container documentation).

```php
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Container\Attribute\Inject;

#[Autowire(shared: true)]
class SmtpMailer implements MailerInterface
{
    #[Inject]
    protected LoggerInterface $logger;

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
| `shared` | `#[Autowire]` on the class only: `false` creates a new instance each time |

## Configuration file

`config/services.yaml` (or `config/services.yml`) only accepts the root key `services`.

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

  App\Service\Slugger: ~

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
| `_defaults` | defaults of the file: `shared` (default `true`); `autowire` is accepted |
| `Namespace\: { resource, exclude, shared }` | registers every instantiable class of a directory; `exclude`: files, directories or `*` patterns, relative to the file |
| `id: ~` | registers a class with its default values |
| `id: '@other'` / `id: { alias: other }` | alias of another service |
| `class` | class of the service (default: the id) |
| `arguments` | arguments by name (`$host`) or by position; the others are autowired |
| `calls` | methods called after the construction: `[method, [arguments]]` |
| `factory` | `['@service', 'method']`, `['Class', 'method']` or `'Class::method'`; must return an object |
| `shared` | `false` creates a new instance each time |

The priority of `shared` is: the entry, then `#[Autowire(shared: ...)]` on the class, then `_defaults`.

Unknown keys, a missing class, a missing `resource`, a malformed call or factory, a method call on a missing method and a factory that does not return an object throw a `ServiceException`.

## Arguments

| Value | Meaning |
|---|---|
| `@id` | the service `id` |
| `@?id` | the service `id`, or `null` when it does not exist |
| `@@text` | the string `@text` |
| `%...%` | a configuration placeholder, resolved when the service is built (see the Config documentation) |

Arrays are resolved recursively.

## Interfaces

When one registered class implements an interface, the interface is bound to this class automatically: a parameter typed `NotifierInterface` receives `SmsNotifier`.

When several registered classes implement it, resolving the interface throws a `ServiceException` that asks for an alias:

```yaml
services:
  App\Service\NotifierInterface: '@App\Service\SmsNotifier'
```

An interface already bound by the framework, or defined as a service or alias in the file, is never replaced automatically.

## Cache

`services.yaml` is compiled into `var/cache/service/services.{env}.php`. In debug, the cache is rebuilt when the file or a class of a `resource` changes. In production, clear it on every deployment:

```bash
php bin/neo cache:clear
```

## Command

`service:list` lists the services, the aliases and the interfaces bound automatically, with their class, shared flag and source (`definition`, `resource`, `alias`, `interface`). An optional filter keeps the rows whose id or class contains the text.

```bash
php bin/neo service:list
php bin/neo service:list Repository
```

## API

### ServiceInterface

`NeoPHP\Component\Service\Contract\ServiceInterface` (implemented by `ServiceManager`, extending `AbstractService`):

| Method | Description |
|---|---|
| `register(array $definitions): static` | registers compiled definitions (`services`, `aliases`, `interfaces` keys) in the container |
| `getServices(): array` | service definitions by id (`class`, `arguments`, `calls`, `factory`, `shared`, `source`) |
| `getAliases(): array` | aliases (`alias => target`) |
| `getInterfaces(): array` | interfaces bound automatically (`interface => id`, or a list of ids when ambiguous) |

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Service\Contract\ServiceInterface;

final class ServiceAudit
{
    public function __construct(private ServiceInterface $services)
    {
    }

    public function count(): int
    {
        return count($this->services->getServices());
    }
}
```

### Other classes

| Class | Description |
|---|---|
| `NeoPHP\Component\Service\Loader\YamlServiceLoader` | `load(string $file): array` compiles a services file; `getResources(): array` returns the watched files |
| `NeoPHP\Component\Service\Provider\ServiceProvider` | registers the manager and loads `services.yaml` (constants `SERVICE_FILES`, `CACHE_DIRECTORY`) |
| `NeoPHP\Component\Service\Exception\ServiceException` | invalid file or definition, ambiguous interface |

## Changelog

- v1.8.0 — `#[Autowire]`, `#[Inject]`, `config/services.yaml` (resources, arguments, calls, factories, aliases), shared services by default, interfaces bound to their single implementation, `service:list` command.