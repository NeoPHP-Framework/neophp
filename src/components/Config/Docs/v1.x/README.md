# Config

The Config component loads every YAML file of `config/` into one tree of dotted keys.
Values can use placeholders for environment variables, kernel parameters and other configuration keys.

## Summary

- [Environment files](#environment-files)
- [YAML files](#yaml-files)
- [Reading values](#reading-values)
- [Placeholders](#placeholders)
- [PHP API](#php-api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Environment files

The kernel loads, in this order (later files override earlier ones):

| File | Committed | Purpose |
|---|---|---|
| `.env` | yes | default values |
| `.env.local` | no | local overrides (not loaded when `APP_ENV=test`) |
| `.env.{APP_ENV}` | yes | values for one environment (`.env.prod`, `.env.test`...) |
| `.env.{APP_ENV}.local` | no | local overrides for one environment |

Real environment variables (server, Docker...) always win over the files.

```dotenv
APP_NAME="My application"
APP_ENV=dev
APP_DEBUG=1
DATABASE_URL="mysql://${DB_USER}@localhost/app"
```

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `dev` | environment name |
| `APP_DEBUG` | `true` unless `APP_ENV=prod` | shows the detailed error page |
| `APP_SECRET` | none | key used to sign cookies |
| `APP_URL` | none | base URL of the absolute URLs generated in the console (`framework.app.url`) |
| `DATABASE_URL` | `sqlite:///%kernel.root_path%/var/data.db` | URL of the default database connection |

`php bin/neo install` creates `.env`; when `.env` already exists, it adds the missing variables (with their comments) and keeps the others.

## YAML files

Every `*.yaml` / `*.yml` file of `config/` is loaded, except `routes.yaml`, `config/routes/` and `services.yaml`. The key is the file path:

| File | Key |
|---|---|
| `config/framework/app.yaml` | `framework.app` |
| `config/packages/mail.yaml` | `packages.mail` |

```yaml
name: '%env(APP_NAME)%'
secret: '%env(APP_SECRET)%'
url: '%env(APP_URL)%'
```

## Reading values

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;

class AboutController extends AbstractController
{
    public function show(ConfigInterface $config): Response
    {
        return $this->render('about', [
            'name' => $config->get('framework.app.name'),
            'port' => $config->get('packages.mail.port', 25),
        ]);
    }
}
```

In a template, the `config($key, $default = null)` view helper:

```twig
{{ config('framework.app.name') }}
```

```php
<?= $this->e($this->config('framework.app.name')) ?>
```

A service can receive a value with `#[Autowire(config: 'framework.app.name')]` (see the Container documentation).

## Placeholders

Placeholders can be used in every YAML file, `routes.yaml` included:

```yaml
host: '%env(MAIL_HOST)%'
port: '%env(int:MAIL_PORT)%'
secure: '%env(bool:MAIL_SECURE)%'
from: 'noreply@%env(MAIL_HOST)%'
templates: '%kernel.templates_path%/emails'
app_name: '%framework.app.name%'
discount: '10%%'
```

| Placeholder | Value |
|---|---|
| `%env(NAME)%` | environment variable (string) |
| `%env(int:NAME)%`, `%env(float:NAME)%`, `%env(bool:NAME)%` | environment variable cast to a type |
| `%env(json:NAME)%`, `%env(csv:NAME)%` | environment variable decoded as JSON / split on commas |
| `%kernel.root_path%` | project root directory |
| `%kernel.config_path%` | `config/` directory |
| `%kernel.public_path%` | `public/` directory |
| `%kernel.templates_path%` | `templates/` directory |
| `%kernel.cache_path%` | `var/cache/` directory |
| `%kernel.environment%` | `APP_ENV` |
| `%kernel.debug%` | debug mode (bool) |
| `%kernel.version%` | NeoPHP version |
| `%any.config.key%` | value of another configuration key |
| `%%` | a literal `%` |

Only `%env(...)%` and keys containing a dot (`%kernel.root_path%`, `%framework.app.name%`) are placeholders: `%datetime%` or `%type%` are kept as is.

A value made of a single placeholder keeps its type (`'%kernel.debug%'` is a bool).

## PHP API

`NeoPHP\Component\Config\Contract\ConfigInterface` (implemented by `ConfigManager`):

| Method | Description |
|---|---|
| `get(string $key, mixed $default = null): mixed` | value of a dotted key |
| `has(string $key): bool` | whether the key exists |
| `set(string $key, mixed $value): void` | sets a value (not persisted) |
| `all(): array` | the whole tree |
| `loadDirectory(string $directory, array $exclude = []): static` | loads every YAML file of a directory, keyed by path |
| `loadFile(string $file, string $key = '', bool $resolve = true): static` | loads one file under `$key` (merged with the existing value; root when empty) |
| `resolve(mixed $value): mixed` | resolves the placeholders of a value or an array |

```php
$config->loadFile('/path/to/extra.yaml', 'packages.extra');
$path = $config->resolve('%kernel.root_path%/var');
```

## Exceptions

`NeoPHP\Component\Config\Exception\ConfigException` is thrown for an undefined environment variable or configuration key in a placeholder, and for a YAML file that does not contain a mapping.

## Changelog

- Bugfix after v1.17.0 — `framework.app.url` / `APP_URL` for absolute URLs outside of an HTTP request.
- v1.11.0 — `neo install` adds the missing variables to an existing `.env`.
- v1.2.0 — `config()` view helper, usable with PHP and Twig templates.
- v1.0.0 — Configuration: `.env` files, `config/**/*.yaml`, placeholders `%kernel.*%`, `%env(...)%` and `%config.key%`.