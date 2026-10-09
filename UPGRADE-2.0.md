# Upgrade from 1.x to 2.0

NeoPHP 2.0 makes every component, package and process a module: a `final` manager at the root of the module, declared with `#[Component]`, `#[Package]` or `#[Process]` and discovered by the kernel, with its interface next to it. This file lists the changes that require an update of the applications, then the details per module.

## Summary

1. Require `neophp/framework` `^2.0`; PHP 8.2 with the `ctype`, `json`, `mbstring` and `tokenizer` extensions.
2. `src/Kernel.php` extends `NeoPHP\Component\Kernel\Contract\AbstractKernel` (see [Kernel](#kernel)).
3. Replace the interfaces of `Contract\` with the `<Module>ManagerInterface` at the root of each module (see [Interfaces](#interfaces)). A search and replace of the table below is enough.
4. Classes extending a manager or the abstract class of a manager inject the interface instead (see [Managers](#managers)).
5. Update the imports of the classes moved out of the root of their module (see [Moved classes](#moved-classes)).
6. Run `php bin/neo install` to create `config/config.php`, then `php bin/neo cache:clear`.

## Requirements

`composer.json` of the framework now requires `ext-ctype`, `ext-json`, `ext-mbstring` and `ext-tokenizer` (they were already used; `mbstring` was only suggested). Composer refuses to install NeoPHP 2 without them.

## Interfaces

The main interface of every module moves to the root of the module and is named after its manager. The old names are removed:

| 1.x | 2.0 |
|---|---|
| `NeoPHP\Component\Asset\Contract\AssetInterface` | `NeoPHP\Component\Asset\AssetManagerInterface` |
| `NeoPHP\Component\Cache\Contract\CacheManagerInterface` | `NeoPHP\Component\Cache\CacheManagerInterface` |
| `NeoPHP\Component\Config\Contract\ConfigInterface` | `NeoPHP\Component\Config\ConfigManagerInterface` |
| `NeoPHP\Component\Container\Contract\ContainerInterface` | `NeoPHP\Component\Container\ContainerManagerInterface` |
| `NeoPHP\Component\Controller\Contract\ControllerResolverInterface` | `NeoPHP\Component\Controller\ControllerManagerInterface` |
| `NeoPHP\Component\Cookie\Contract\CookieInterface` | `NeoPHP\Component\Cookie\CookieManagerInterface` |
| `NeoPHP\Component\Csrf\Contract\CsrfInterface` | `NeoPHP\Component\Csrf\CsrfManagerInterface` |
| `NeoPHP\Component\Database\Contract\DatabaseInterface` | `NeoPHP\Component\Database\DatabaseManagerInterface` |
| `NeoPHP\Component\Event\Contract\EventDispatcherInterface` | `NeoPHP\Component\Event\EventManagerInterface` |
| `NeoPHP\Component\Flash\Contract\FlashInterface` | `NeoPHP\Component\Flash\FlashManagerInterface` |
| `NeoPHP\Component\Form\Contract\FormManagerInterface` | `NeoPHP\Component\Form\FormManagerInterface` |
| `NeoPHP\Component\Http\Contract\HttpInterface` | `NeoPHP\Component\Http\HttpManagerInterface` |
| `NeoPHP\Component\HttpClient\Contract\HttpClientInterface` | `NeoPHP\Component\HttpClient\HttpClientManagerInterface` |
| `NeoPHP\Component\Kernel\Contract\KernelInterface` | `NeoPHP\Component\Kernel\KernelManagerInterface` |
| `NeoPHP\Component\Logger\Contract\LoggerManagerInterface` | `NeoPHP\Component\Logger\LoggerManagerInterface` |
| `NeoPHP\Component\Mailer\Contract\MailerInterface` | `NeoPHP\Component\Mailer\MailerManagerInterface` |
| `NeoPHP\Component\Middleware\Contract\MiddlewareManagerInterface` | `NeoPHP\Component\Middleware\MiddlewareManagerInterface` |
| `NeoPHP\Component\Routing\Contract\RoutingInterface` | `NeoPHP\Component\Routing\RoutingManagerInterface` |
| `NeoPHP\Component\Serializer\Contract\SerializerInterface` | `NeoPHP\Component\Serializer\SerializerManagerInterface` |
| `NeoPHP\Component\Service\Contract\ServiceInterface` | `NeoPHP\Component\Service\ServiceManagerInterface` |
| `NeoPHP\Component\Session\Contract\SessionInterface` | `NeoPHP\Component\Session\SessionManagerInterface` |
| `NeoPHP\Component\Upload\Contract\UploaderInterface` | `NeoPHP\Component\Upload\UploadManagerInterface` |
| `NeoPHP\Component\Validator\Contract\ValidatorInterface` | `NeoPHP\Component\Validator\ValidatorManagerInterface` |
| `NeoPHP\Component\View\Contract\ViewInterface` | `NeoPHP\Component\View\ViewManagerInterface` |
| `NeoPHP\Package\Debug\Contract\DebugInterface` | `NeoPHP\Package\Debug\DebugManagerInterface` |
| `NeoPHP\Package\Dotenv\Contract\DotenvInterface` | `NeoPHP\Package\Dotenv\DotenvManagerInterface` |
| `NeoPHP\Package\Markdown\Contract\MarkdownParserInterface` | `NeoPHP\Package\Markdown\MarkdownManagerInterface` |
| `NeoPHP\Package\Orm\Contract\OrmInterface` | `NeoPHP\Package\Orm\OrmManagerInterface` |
| `NeoPHP\Package\Queue\Contract\QueueInterface` | `NeoPHP\Package\Queue\QueueManagerInterface` |
| `NeoPHP\Package\Security\Contract\SecurityInterface` | `NeoPHP\Package\Security\SecurityManagerInterface` |
| `NeoPHP\Package\Tailwind\Contract\TailwindInterface` | `NeoPHP\Package\Tailwind\TailwindManagerInterface` |
| `NeoPHP\Package\Translation\Contract\TranslatorInterface` | `NeoPHP\Package\Translation\TranslationManagerInterface` |
| `NeoPHP\Package\Yaml\Contract\YamlInterface` | `NeoPHP\Package\Yaml\YamlManagerInterface` |
| `NeoPHP\Process\Console\Contract\ConsoleInterface` | `NeoPHP\Process\Console\ConsoleManagerInterface` |
| `NeoPHP\Process\Installer\Contract\InstallerInterface` | `NeoPHP\Process\Installer\InstallerManagerInterface` |
| — | `NeoPHP\Component\Api\ApiManagerInterface` (new) |
| — | `NeoPHP\Component\Exception\ExceptionManagerInterface` (new) |
| — | `NeoPHP\Package\NeoAI\NeoAiManagerInterface` (new) |
| — | `NeoPHP\Package\Scheduler\SchedulerManagerInterface` (new) |
| — | `NeoPHP\Package\WebProfiler\WebProfilerManagerInterface` (new) |

The other interfaces of `Contract\` keep their name and namespace (for example `NeoPHP\Package\Orm\Contract\EntityManagerInterface`, `NeoPHP\Component\Container\Contract\ProviderInterface`, `NeoPHP\Package\WebProfiler\Contract\ProfilerInterface`).

The services are registered under the new interfaces, under the manager class and under their string aliases (`'scheduler'`, `'profiler'`...), as in 1.x.

## Managers

Every manager is `final` and is the only class at the root of its module with its interface. The abstract class of a manager is merged into it and removed:

| Module | 1.x | 2.0 |
|---|---|---|
| Asset | `Contract\AbstractAsset` | `AssetManager` |
| Config | `Contract\AbstractConfig` | `ConfigManager` |
| Container | `Contract\AbstractContainer` | `ContainerManager` |
| Cookie | `Contract\AbstractCookie` | `CookieManager` |
| Csrf | `Contract\AbstractCsrf` | `CsrfManager` |
| Database | `Contract\AbstractDatabase` | `DatabaseManager` |
| Event | `Contract\AbstractEventDispatcher` | `EventManager` |
| Flash | `Contract\AbstractFlash` | `FlashManager` |
| Form | `Contract\AbstractFormManager` | `FormManager` |
| Http | `Contract\AbstractHttp` | `HttpManager` |
| HttpClient | `Contract\AbstractHttpClient` | `HttpClientManager` |
| Mailer | `Contract\AbstractMailer` | `MailerManager` |
| Middleware | `Contract\AbstractMiddlewareManager` | `MiddlewareManager` |
| Routing | `Contract\AbstractRouting` | `RoutingManager` |
| Serializer | `Contract\AbstractSerializer` | `SerializerManager` |
| Service | `Contract\AbstractService` | `ServiceManager` |
| Session | `Contract\AbstractSession` | `SessionManager` |
| Upload | `Contract\AbstractUploader` | `UploadManager` |
| Validator | `Contract\AbstractValidator` | `ValidatorManager` |
| View | `Contract\AbstractView` | `ViewManager` |
| Debug | `Contract\AbstractDebug` | `DebugManager` |
| Dotenv | `Contract\AbstractDotenv` | `DotenvManager` |
| Markdown | `Contract\AbstractMarkdownParser` | `MarkdownManager` |
| Orm | `Contract\AbstractOrm` | `OrmManager` |
| Security | `Contract\AbstractSecurity` | `SecurityManager` |
| Tailwind | `Contract\AbstractTailwind` | `TailwindManager` |
| Translation | `Contract\AbstractTranslator` | `TranslationManager` |
| Yaml | `Contract\AbstractYaml` | `YamlManager` |
| Console | `Contract\AbstractConsoleManager` | `ConsoleManager` |
| Installer | `Contract\AbstractInstaller` | `InstallerManager` |

A class extending one of them must implement the interface instead, or decorate the manager:

```php
// 1.x
class MyView extends AbstractView
{
}

// 2.0
final class MyView implements ViewManagerInterface
{
    public function __construct(private ViewManagerInterface $inner)
    {
    }

    // delegate each method to $this->inner
}
```

Two managers are renamed:

| 1.x | 2.0 |
|---|---|
| `NeoPHP\Package\Scheduler\Scheduler` | `NeoPHP\Package\Scheduler\SchedulerManager` |
| `NeoPHP\Package\WebProfiler\Profiler` | `NeoPHP\Package\WebProfiler\WebProfilerManager` |

The base classes made to be extended stay in `Contract\`: `AbstractKernel`, `AbstractController`, `AbstractConsole`, `AbstractEvent`, `AbstractLogger`, `AbstractCache`, `AbstractAdapter`, `AbstractForm`, `AbstractType`, `AbstractConstraint`, `AbstractProvider`, `AbstractProfiler`, `AbstractVoter`, `AbstractAuthenticator`, `AbstractRepository`, `AbstractMigration`, `AbstractPlatform`, `AbstractTransport`, `AbstractDriver`, `AbstractConnection`, `AbstractException`, `AbstractAiCommand`.

## Moved classes

Only the manager and its interface stay at the root of a module. The other classes move into a sub-folder, with the same name:

| 1.x | 2.0 |
|---|---|
| `NeoPHP\Component\Cache\CachePool` | `NeoPHP\Component\Cache\Pool\CachePool` |
| `NeoPHP\Component\Exception\FrameworkException` | `NeoPHP\Component\Exception\Exception\FrameworkException` |
| `NeoPHP\Component\Form\Form` | `NeoPHP\Component\Form\Model\Form` |
| `NeoPHP\Component\Form\FormBuilder` | `NeoPHP\Component\Form\Model\FormBuilder` |
| `NeoPHP\Component\Form\FormError` | `NeoPHP\Component\Form\Model\FormError` |
| `NeoPHP\Component\Form\FormView` | `NeoPHP\Component\Form\Model\FormView` |
| `NeoPHP\Component\Form\ResolvedType` | `NeoPHP\Component\Form\Type\ResolvedType` |
| `NeoPHP\Component\Kernel\AppVariable` | `NeoPHP\Component\Kernel\Helper\View\AppVariable` |
| `NeoPHP\Component\Logger\LogLevel` | `NeoPHP\Component\Logger\Contract\LogLevel` |
| `NeoPHP\Package\Markdown\MarkdownConversion` | `NeoPHP\Package\Markdown\Converter\MarkdownConversion` |
| `NeoPHP\Package\Queue\Envelope` | `NeoPHP\Package\Queue\Message\Envelope` |
| `NeoPHP\Package\Scheduler\Schedule` | `NeoPHP\Package\Scheduler\Schedule\Schedule` |
| `NeoPHP\Package\Scheduler\Task` | `NeoPHP\Package\Scheduler\Schedule\Task` |
| `NeoPHP\Package\Translation\LocaleDetector` | `NeoPHP\Package\Translation\Locale\LocaleDetector` |

A class implementing an interface of `Contract\` that uses a moved class must update its import, otherwise PHP reports an incompatible declaration:

```php
// 1.x
use NeoPHP\Package\Scheduler\Contract\ScheduleProviderInterface;
use NeoPHP\Package\Scheduler\Schedule;

// 2.0
use NeoPHP\Package\Scheduler\Contract\ScheduleProviderInterface;
use NeoPHP\Package\Scheduler\Schedule\Schedule;

final class AppSchedule implements ScheduleProviderInterface
{
    public function schedule(Schedule $schedule): void
    {
    }
}
```

The same applies to the custom queue transports (`TransportInterface`, `AbstractTransport`), which receive a `Queue\Message\Envelope`.

The exceptions of the framework still extend `FrameworkException`: catch `NeoPHP\Component\Exception\Exception\FrameworkException` (or `NeoPHP\Component\Exception\Contract\ExceptionInterface`) instead of `NeoPHP\Component\Exception\FrameworkException`.

## Internal classes

The providers, the discoveries, the traces and the helpers that plug a module into another one (`Helper/View`, `Helper/Console`, `Helper/Event`, `Helper/WebProfiler`, `Helper/Form`) are marked `@internal`: they can change in any version. Use the manager interfaces, `Contract\`, the attributes, the exceptions and the events instead. The controller traits of `Helper/Controller` stay public.

The helper folders are named after the module they plug into:

| 1.x | 2.0 |
|---|---|
| `Helper/Profiler/` | `Helper/WebProfiler/` |
| `Helper/Listener/` | `Helper/Event/` |

A module of the framework adding an element to the WebProfiler puts it in `Helper/WebProfiler/<Module>Profiler.php`. The elements of the application (`#[AsProfiler]`) are unchanged.

## Kernel

### Application kernel

`KernelManager` is now `final`: `src/Kernel.php` extends `AbstractKernel`.

```php
// 1.x
use NeoPHP\Component\Kernel\KernelManager;

class Kernel extends KernelManager
{
}

// 2.0
use NeoPHP\Component\Kernel\Contract\AbstractKernel;

class Kernel extends AbstractKernel
{
}
```

### Modules

The kernel discovers the modules (`#[Component]`, `#[Package]`, `#[Process]`) by itself: every module of the framework is enabled, as in 1.x. A Composer package declares its modules in `extra.neophp.modules` of its `composer.json` (see the Kernel documentation).

- `AbstractKernel::coreProviders()` is removed. A kernel that overrides it must disable the unwanted modules in `config/config.php` instead. `providers()` is unchanged.
- `KernelInterface` is replaced by `NeoPHP\Component\Kernel\KernelManagerInterface`, with two new methods, `getModules()` and `isEnabled()`: a class implementing it without extending `AbstractKernel` must add them.

To disable modules, create `config/config.php` (or run `php bin/neo install`, which keeps the existing files and adds the missing ones):

```php
<?php

declare(strict_types=1);

return [
    NeoPHP\Package\WebProfiler\WebProfilerManager::class => ['dev' => true],
    NeoPHP\Package\NeoAI\NeoAiManager::class => ['dev' => true],
];
```

Run `php bin/neo cache:clear --env=prod` after each change in production.

### Console

`bin/neo` stops with a clear message when the Console process is disabled. Copy the new check from `vendor/neophp/framework/src/process/Installer/Resources/skeleton/bin/neo.stub`, or regenerate the file with `php bin/neo install --force` (this overwrites every generated file).

A module required by an enabled module cannot be disabled: the kernel stops with an error naming both modules. The modules required by the kernel (Container, Exception, Yaml, Dotenv, Config, Http, Event, Middleware, Routing, Controller) are always enabled.

## Flash and WebProfiler

The "Flash messages" tab of the Session panel of the profiler becomes a "Flash messages" panel, provided by the Flash component (`src/components/Flash/Helper/WebProfiler/FlashProfiler.php`). The Session panel is now "Session / Cookies". Nothing to change in the applications.

## Documentation

The documentation of each module is in `Docs/v2.x/README.md`; the 1.x one stays in `Docs/v1.x/README.md`. The getting started guide is [docs/v2.x/README.md](docs/v2.x/README.md).