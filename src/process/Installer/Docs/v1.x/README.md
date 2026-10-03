# Installer

The Installer process generates the files of a NeoPHP project from a skeleton: `bin/neo`, `public/`, `src/Kernel.php`, `config/`, `templates/`, `.env`...
It never overwrites an existing file unless forced, completes `.env` with the missing variables and adds the `App\` autoload to `composer.json`.

## Summary

- [Project setup](#project-setup)
- [Install command](#install-command)
- [Generated files](#generated-files)
- [Environment file](#environment-file)
- [API](#api)
- [Changelog](#changelog)

## Project setup

Until the framework is published on Packagist, require it through a Composer path repository (symlink). Create a folder next to the framework with this `composer.json`:

```json
{
    "name": "neophp/test",
    "type": "project",
    "repositories": [
        { "type": "path", "url": "../neophp", "options": { "symlink": true } }
    ],
    "require": {
        "php": ">=8.2",
        "neophp/framework": "*@dev"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "scripts": {
        "neo:install": "@php vendor/bin/neo install",
        "post-create-project-cmd": "@neo:install",
        "post-install-cmd": "@neo:install",
        "post-update-cmd": "@neo:install"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Then:

```bash
composer install
php bin/neo serve
```

`composer install` runs `neo install` automatically. A generated file that was deleted is created again on the next `composer install` or `composer update`.

## Install command

```bash
php bin/neo install
php bin/neo install --force
```

| Behavior | Description |
|---|---|
| missing file | created |
| existing file | kept (`skipped`, shown with `-v`) |
| existing file with `--force` | overwritten |
| existing `.env` | the missing variables are appended (`updated`), even without `--force` |
| missing directory | created with a `.gitkeep` |
| `composer.json` without `App\` | the `App\` → `src/` PSR-4 autoload is added (`updated`); run `composer dump-autoload` |

Each line of the report shows a status: `created`, `overwritten`, `updated` or `skipped`.

## Generated files

```
.env
.gitignore
assets/css/app.css
bin/neo
config/routes.yaml
config/services.yaml
config/framework/api.yaml
config/framework/app.yaml
config/framework/asset.yaml
config/framework/csrf.yaml
config/framework/database.yaml
config/framework/event.yaml
config/framework/form.yaml
config/framework/http_client.yaml
config/framework/logger.yaml
config/framework/mailer.yaml
config/framework/middleware.yaml
config/framework/serializer.yaml
config/framework/view.yaml
config/packages/debug.yaml
config/packages/neo_ai.yaml
config/packages/orm.yaml
config/packages/queue.yaml
config/packages/scheduler.yaml
config/packages/security.yaml
config/packages/translation.yaml
public/.htaccess
public/index.php
src/Controller/HomeController.php
src/Kernel.php
templates/base.php
templates/home/index.php
```

Directories: `assets/`, `config/packages/`, `migrations/`, `public/builds/`, `src/Command/`, `src/Entity/`, `src/Event/`, `src/Form/`, `src/Listener/`, `src/Message/`, `src/MessageHandler/` `src/Middleware/`, `src/Repository/`, `src/Security/`, `src/Service/`, `src/Task/`, `tests/`, `translations/`, `var/cache/`, `var/log/`, `var/sessions/`.

`bin/neo` is made executable. It boots the kernel with the environment given by `--env` and runs the console (see the Console documentation).

## Environment file

The generated `.env` contains a random `APP_SECRET` (64 hexadecimal characters) and `APP_URL`, the base URL of the absolute URLs generated in the console.

```bash
APP_NAME=NeoPHP
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=...
APP_URL=http://127.0.0.1:8000
DATABASE_URL="sqlite:///%kernel.root_path%/var/data.db"
MAILER_DSN="null://null"
MAILER_RECIPIENTS=

###> neo_ai ###
# NeoAI development assistant (active only when APP_DEBUG=1)
# Provider of the "default" connection: ollama, openai, anthropic, gemini, mistral, groq, openrouter, lmstudio, vllm, openai_compatible
NEO_AI_PROVIDER=ollama
NEO_AI_MODEL=qwen2.5-coder:7b
# Key and base URL of the "default" connection (empty = none / provider default), e.g. NEO_AI_API_KEY=${OPENAI_API_KEY}
NEO_AI_API_KEY=
NEO_AI_BASE_URL=
# Real keys go in .env.local (never commit them)
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
MISTRAL_API_KEY=
OLLAMA_URL=http://localhost:11434
###< neo_ai ###

###> queue ###
QUEUE_DSN="database://default"
###< queue ###
```

The `neo_ai` block configures the NeoAI development assistant (see the NeoAI documentation). Every variable must exist, even empty, because `config/packages/neo_ai.yaml` references them with `%env(...)%`. Put the real API keys in `.env.local`, never in `.env`. By default the assistant uses a local Ollama server, so no data leaves the machine.

When `.env` already exists, only the variables it does not define (even commented out) are appended, with their comments. See the Dotenv documentation for the file format.

## API

### InstallerInterface

`NeoPHP\Process\Installer\Contract\InstallerInterface`, implemented by `InstallerManager(?string $skeletonDir = null)` (extends `AbstractInstaller`, default skeleton `Resources/skeleton`):

| Method | Description |
|---|---|
| `install(string $projectDir, bool $force = false): array` | installs the skeleton; returns `path => status` |
| `getSkeletonDir(): string` | skeleton directory |
| `getFiles(): array` | files to generate (`relative path => stub file`) |
| `getDirectories(): array` | directories to create |

| Constant | Value |
|---|---|
| `STATUS_CREATED` | `created` |
| `STATUS_OVERWRITTEN` | `overwritten` |
| `STATUS_UPDATED` | `updated` |
| `STATUS_SKIPPED` | `skipped` |

`AbstractInstaller` constants: `STUB_EXTENSION` (`.stub`), `EXECUTABLES`, `MERGEABLE` (`.env`), `DIRECTORIES`. Stubs can use the `{{ app_secret }}` variable.

```php
<?php

declare(strict_types=1);

use NeoPHP\Process\Installer\InstallerManager;

$report = (new InstallerManager())->install(__DIR__, false);

foreach ($report as $path => $status) {
    echo $status . ' ' . $path . PHP_EOL;
}
```

### Exceptions

`NeoPHP\Process\Installer\Exception\InstallerException`: the project directory does not exist or is not writable, a file cannot be written, or `composer.json` is not valid JSON.

## Changelog

- v1.37.0 — `config/packages/queue.yaml` and `config/packages/scheduler.yaml` are generated, `QUEUE_DSN` is added to `.env`, directories `src/Message/`, `src/MessageHandler/` and `src/Task/`.
- v1.26.0 — `config/packages/neo_ai.yaml` is generated (NeoAI connections, context, scan and web options) and the `NEO_AI_*`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `MISTRAL_API_KEY` and `OLLAMA_URL` variables are added to `.env`.
- v1.24.0 — `config/framework/api.yaml` is generated (CORS, rate limiter, pagination, problem details, OpenAPI).
- v1.23.0 — `config/framework/serializer.yaml` is generated.
- v1.20.0 — `config/packages/translation.yaml` and the `translations/` directory are generated.
- Bugfix after v1.17.0 — `APP_URL` written in `.env`.
- v1.11.0 — The missing variables are added to an existing `.env`.
- v1.0.0 — `neo install` generates the project files, `bin/neo` and the `App\` autoload; existing files kept unless `--force`.