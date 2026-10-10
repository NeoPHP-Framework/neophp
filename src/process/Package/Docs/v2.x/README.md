# Package

The Package process installs, lists, updates and removes the NeoPHP packages with Composer, and generates the skeleton of a new package.
A NeoPHP package is a Composer package of type `neophp-package` that adds modules to a project: its configuration is copied into `config/packages/<name>/`, its templates are rendered with `@<name>/`, and its commands, listeners and view helpers are discovered.

## Summary

- [Module](#module)
- [Installing a package](#installing-a-package)
- [Commands](#commands)
- [Configuration files](#configuration-files)
- [Templates](#templates)
- [Creating a package](#creating-a-package)
- [Composer](#composer)
- [API](#api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Process\Package\PackageManager` (`final`) |
| Interface | `NeoPHP\Process\Package\PackageManagerInterface` |
| Attribute | `#[Process(provider: PackageProvider::class)]` |
| Requires | nothing |

Inject `PackageManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Process\Package\PackageManagerInterface;

public function __construct(private PackageManagerInterface $package)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Process\Package\PackageManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Installing a package

```bash
php bin/neo neophp:package:install acme/neo-billing
```

1. Composer must know the package with the type `neophp-package`: any other type is refused before the installation, and checked again after it (the package is then removed).
2. `composer require acme/neo-billing` runs in the project, with its output and its questions.
3. The configuration files of the package are copied into `config/packages/billing/`.
4. `var/cache/` is emptied.

The package is then enabled like any module: its manager is registered by the kernel, its services can be injected, its commands appear in `php bin/neo list`. To disable it, add its manager to `config/config.php`:

```php
Acme\Billing\BillingManager::class => false,
```

`composer require` alone installs a package too: the modules are discovered the same way, but the configuration is not copied (run `neophp:package:update <name>` to copy it).

## Commands

| Command | Description |
|---|---|
| `neophp:package:install <package> [--dev]` | requires the package with Composer (`acme/neo-billing`, `acme/neo-billing:^1.2`), copies its configuration and clears the cache; `--dev` adds it to `require-dev` |
| `neophp:package:list` | the installed NeoPHP packages: Composer name, name, version, status (enabled / disabled in `config/config.php`) and configuration directory |
| `neophp:package:update [package]` | updates one package, or every NeoPHP package, with `composer update --with-dependencies`, then copies the new configuration files |
| `neophp:package:remove <package> [--purge]` | removes the entries of the package from `config/config.php`, runs `composer remove`, clears the cache; `--purge` also deletes `config/packages/<name>/` |
| `neophp:package:create <package> [--path=] [--namespace=] [--description=] [--link]` | generates the skeleton of a package (see [Creating a package](#creating-a-package)) |

A package is named by its Composer name (`acme/neo-billing`) or by its name (`billing`). `--force` overwrites the configuration files changed in the project (install, update) and the files of an existing directory (create). `neophp:package:remove` asks for a confirmation, except with `-n`.

The entries of `config/config.php` are removed before `composer remove`, because the Composer scripts of the project boot the kernel, which refuses a key that is not a module; they are restored when Composer fails.

## Configuration files

The files of the `config/` directory of the package (`extra.neophp.config`) are copied into `config/packages/<name>/` of the project, with the same structure. The YAML files are loaded under the key `packages.<name>.<file>`: `config/packages/billing/billing.yaml` gives `packages.billing.billing` (see the Config documentation).

| Status | Meaning |
|---|---|
| `created` | the file did not exist |
| `skipped` | the file is identical, or it was changed in the project and the package did not change it |
| `updated` | the package changed the file and the project did not: the new version replaces it |
| `changed` | the package and the project changed the file: the project file is kept, the new version is written next to it with the `.dist` extension |
| `overwritten` | `--force` replaced the file |

Merge a `.dist` file into the project file, then delete it; the next update removes it when both files are identical. `config/packages/<name>/.package.json` stores the fingerprint of the files copied by the last installation or update: commit it with the configuration.

## Templates

The `templates/` directory of the package (`extra.neophp.templates`) is registered under its name:

```php
return $this->render('@billing/invoice', ['invoice' => $invoice]);
```

`templates/packages/billing/invoice.php` of the project replaces `invoice.php` of the package (see the View documentation).

## Creating a package

```bash
php bin/neo neophp:package:create acme/neo-billing
```

| Option | Default | Description |
|---|---|---|
| `--path` | `packages/<name>` | directory of the package, relative to the project or absolute |
| `--namespace` | `Vendor\Name` (`Acme\Billing`) | PHP namespace of the package |
| `--description` | `<Name> package for NeoPHP.` | description of `composer.json` |
| `--link` | | adds the directory as a Composer path repository of the project and installs the package (`neophp:package:install acme/neo-billing:*@dev`) |

The name of the package is `acme/neo-billing` without vendor and `neo-` / `neophp-` prefix, in snake_case: `billing`. The generated package:

```
packages/neo-billing/
├── .gitignore
├── composer.json
├── README.md
├── config/
│   └── billing.yaml
├── src/
│   ├── BillingManager.php
│   ├── BillingManagerInterface.php
│   ├── Command/HelloCommand.php
│   └── Provider/BillingProvider.php
└── templates/
    └── hello.php
```

With `--link`, the package works right away:

```bash
php bin/neo neophp:package:create acme/neo-billing --link
php bin/neo billing:hello Neo
```

### composer.json

```json
{
    "name": "acme/neo-billing",
    "type": "neophp-package",
    "require": {
        "php": ">=8.2",
        "neophp/framework": "^2.1"
    },
    "autoload": {
        "psr-4": {
            "Acme\\Billing\\": "src/"
        }
    },
    "extra": {
        "neophp": {
            "name": "billing",
            "modules": ["Acme\\Billing\\BillingManager"]
        }
    }
}
```

| Key | Default | Description |
|---|---|---|
| `type` | | `neophp-package`: required by `neophp:package:install` |
| `extra.neophp.modules` | `[]` | the managers of the package, discovered by the kernel |
| `extra.neophp.name` | from the Composer name | name of the package (snake_case): `config/packages/<name>/`, `@<name>/` |
| `extra.neophp.config` | `config` | directory of the configuration files to copy |
| `extra.neophp.templates` | `templates` | directory of the templates |

### Manager and provider

The manager is the entry point of the module: a `final` class declared with `#[Package]`, at the root of `src/`, implementing its interface. Its provider registers the services in the container (see the Container and Kernel documentation):

```php
<?php

declare(strict_types=1);

namespace Acme\Billing;

use Acme\Billing\Provider\BillingProvider;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Component\Session\SessionManager;

#[Package(provider: BillingProvider::class, requires: [SessionManager::class])]
final class BillingManager implements BillingManagerInterface
{
}
```

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Provider;

use Acme\Billing\BillingManager;
use Acme\Billing\BillingManagerInterface;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;

/**
 * @internal
 */
class BillingProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(BillingManagerInterface::class, static function (ContainerManagerInterface $container): BillingManagerInterface {
            return new BillingManager((array) $container->get(ConfigManagerInterface::class)->get('packages.billing.billing', []));
        });

        $container->alias(BillingManager::class, BillingManagerInterface::class);
    }
}
```

`requires` lists the modules the package needs: they are loaded before it, and cannot be disabled while the package is enabled.

### What is discovered

| Element | Where in the package |
|---|---|
| console commands | any `#[AsCommand]` class of `src/` |
| listeners and subscribers | any `#[AsListener]` class or `EventSubscriberInterface` of `src/` |
| view helpers | `src/**/Helper/View/` |
| templates | `templates/` (`@<name>/`) |
| configuration | `config/` (copied into `config/packages/<name>/`) |

The elements of a disabled package are ignored.

### Developing a package in a project

The package can live in the project (`packages/neo-billing/`, the default of `neophp:package:create`) and be installed with a Composer path repository: `--link` adds it to `composer.json`:

```json
"repositories": [
    { 
        "type": "path", 
        "url": "packages/neo-billing", 
        "options": { 
            "symlink": true
        }
    }
]
```

```bash
php bin/neo neophp:package:install acme/neo-billing:*@dev
```

The symlink makes every change of the package visible in the project. In debug, the caches of the modules and listeners are rebuilt when a package is installed or removed; after a change of `composer.json` of the package, run `composer update acme/neo-billing`.

### Publishing a package

Push the directory of the package to its own Git repository, tag a version (`v1.0.0`) and submit it on [Packagist](https://packagist.org): `php bin/neo neophp:package:install acme/neo-billing` then works in any NeoPHP project.

## Composer

The commands run Composer in the project with the first binary found:

1. the `COMPOSER_BINARY` environment variable (`/usr/local/bin/composer`, `composer.phar`...);
2. `composer.phar` at the root of the project;
3. `composer` from the `PATH`.

A `.phar` file is run with the PHP binary of the console. `proc_open()` must be enabled.

## API

### PackageManagerInterface

`NeoPHP\Process\Package\PackageManagerInterface` (implemented by `PackageManager(string $rootPath)`):

| Method | Description |
|---|---|
| `all(): array` | the installed NeoPHP packages by Composer name (see `InstalledPackages` in the Kernel documentation) |
| `find(string $package): ?array` | a package by its Composer name or its name |
| `install(string $package, bool $dev = false, bool $force = false): array` | `['package' => [...], 'files' => [path => status]]` |
| `update(?string $package = null, bool $force = false): array` | `[name => ['package' => [...], 'files' => [...]]]` |
| `remove(string $package, bool $purge = false): array` | `['package' => [...], 'files' => [...]]` |
| `publishConfig(string $package, bool $force = false): array` | copies the configuration of an installed package: `[path => status]` |
| `addPathRepository(string $path): bool` | adds a Composer path repository to `composer.json`; `false` when it already exists |
| `getConfigDirectory(string $package): string` | `config/packages/<name>` |
| `clearCache(): int` | empties `var/cache/`, returns the number of removed files |

Status constants: `STATUS_CREATED`, `STATUS_UPDATED`, `STATUS_OVERWRITTEN`, `STATUS_CHANGED`, `STATUS_SKIPPED`, `STATUS_REMOVED`.

```php
$result = $this->package->install('acme/neo-billing:^1.2');
$configDirectory = $this->package->getConfigDirectory('billing');
```

## Exceptions

`NeoPHP\Process\Package\Exception\PackageException` extends `FrameworkException`. It is thrown for an invalid package name or namespace, a package that is not a NeoPHP package or is not installed, a failed Composer command, a non-empty directory given to `neophp:package:create`, an invalid `composer.json`, or a file that cannot be written.

## Changelog

- v2.1.0 — Package process: `neophp:package:install`, `neophp:package:list`, `neophp:package:update`, `neophp:package:remove` and `neophp:package:create`, configuration copied into `config/packages/<name>/` with `.dist` files for the files changed in the project, `PackageManagerInterface`.