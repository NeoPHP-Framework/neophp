# Tailwind

The Tailwind package (`src/packages/Tailwind`) runs the official Tailwind CSS v4 standalone CLI: no Node.js, no npm.
It downloads the CLI, prepares the CSS file and compiles it into the assets published by the Assets component.

## Summary

- [Quick start](#quick-start)
- [Installing](#installing)
- [Compiling](#compiling)
- [Using the CSS](#using-the-css)
- [Configuration](#configuration)
- [TailwindInterface](#tailwindinterface)
- [Requirements](#requirements)
- [Changelog](#changelog)

## Quick start

```bash
php bin/neo tailwind:install
php bin/neo tailwind:run --watch
php bin/neo tailwind:run --minify
```

1. `tailwind:install` asks the CSS file (`css/app.css`), downloads the CLI and prepares the file.
2. `tailwind:run --watch` recompiles while developing (Ctrl+C to stop).
3. `tailwind:run --minify` builds the final CSS before deploying.

## Installing

`tailwind:install [input] [--tailwind-version=4.1.13]` (plus the global `--force`):

- downloads the CLI of the operating system (Windows, macOS, Linux, x64 / arm64, musl) from the GitHub releases into `var/tailwind/tailwindcss` (`.exe` on Windows) and writes the installed version in `var/tailwind/VERSION`; `--force` downloads it again
- creates `assets/<input>` with `@import "tailwindcss";`, or adds this import at the top of an existing file
- saves the file name in `config/packages/tailwind.yaml`

`input` is relative to `assets/` (default: the configured `input`, else `css/app.css`).

## Compiling

`tailwind:run [input] [--watch|-w] [--minify|-m]` compiles `assets/css/app.css` into `var/tailwind/css/app.css`, then publishes it with the assets (`public/builds/css/app-{hash}.css`).

| Option | Description |
|---|---|
| `input` | the CSS file relative to `assets/` (default: `input` of `tailwind.yaml`) |
| `--watch`, `-w` | recompiles on every change of the templates or the CSS |
| `--minify`, `-m` | minifies the CSS |

Tailwind v4 finds the classes itself by scanning the project (the files ignored by `.gitignore`, such as `vendor/` and `var/`, are skipped). Add `@source` for other directories and customize the theme with `@theme`:

```css
@import "tailwindcss";
@source "../../other/path";

@theme {
    --color-brand: #4f46e5;
}
```

## Using the CSS

The templates keep the usual `asset()` helper (see the Assets documentation):

```twig
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
```

```php
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
```

- With `--watch` in debug (`auto_compile`), `asset()` serves the new file on the next request.
- In production, run `tailwind:run --minify` (then `asset:reload` if needed) on every deployment.
- When the file was never compiled, `asset()` publishes the source file.

The compiled file replaces the source of the asset through `AssetInterface::setSourceFile()`.

## Configuration

`config/packages/tailwind.yaml`:

```yaml
input: css/app.css
version: latest
binary: ~
```

| Key | Description |
|---|---|
| `input` | Tailwind CSS file, relative to `assets/` |
| `version` | version downloaded by `tailwind:install` (`latest` or e.g. `4.1.13`) |
| `binary` | path of an existing Tailwind CLI (absolute or relative to the project): nothing is downloaded |

## TailwindInterface

Inject `NeoPHP\Package\Tailwind\Contract\TailwindInterface` (implemented by `NeoPHP\Package\Tailwind\TailwindManager`) to drive Tailwind from code:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Package\Tailwind\Contract\TailwindInterface;

class CssBuilder
{
    public function __construct(private TailwindInterface $tailwind)
    {
    }

    public function build(): int
    {
        if (!$this->tailwind->isInstalled()) {
            $this->tailwind->install();
        }

        return $this->tailwind->run($this->tailwind->getInput() ?? TailwindInterface::DEFAULT_INPUT, minify: true);
    }
}
```

| Method | Description |
|---|---|
| `getInput(): ?string` / `setInput(string $input): static` | the configured CSS file |
| `getVersion(): string` | the configured version |
| `getBinary(): string` | path of the CLI |
| `isInstalled(): bool` / `getInstalledVersion(): ?string` | installation state |
| `getPlatform(): string` | the release file name of the CLI (e.g. `tailwindcss-linux-x64`) |
| `getDownloadUrl(?string $version = null): string` | URL of the CLI release |
| `install(?string $version = null, bool $force = false): array` | downloads the CLI |
| `getSourceFile(string $input): string` / `getOutputFile(string $input): string` | source (`assets/`) and compiled (`var/tailwind/`) paths |
| `initSource(string $input): string` | creates the file or adds the import |
| `getCommand(string $input, bool $watch = false, bool $minify = false): array` | the CLI command line |
| `run(string $input, bool $watch = false, bool $minify = false): int` | runs the CLI, returns its exit code |
| `saveConfig(): string` | writes `config/packages/tailwind.yaml` |

Constants: `TailwindInterface::DEFAULT_INPUT` (`css/app.css`), `DEFAULT_VERSION` (`latest`); `AbstractTailwind::RELEASES_URL`, `DIRECTORY` (`var/tailwind`), `CONFIG_FILE`, `IMPORT` (`@import "tailwindcss";`).

Errors throw `NeoPHP\Package\Tailwind\Exception\TailwindException` (extends `FrameworkException`).

## Requirements

- `tailwind:install` needs `allow_url_fopen` and the `openssl` extension.
- `tailwind:run` needs `proc_open()`.
- `var/` must stay out of Git (`neo install` adds it to `.gitignore`).

## Changelog

- v1.18.0 — Tailwind package: `tailwind:install` downloads the Tailwind CSS v4 standalone CLI and prepares the CSS file, `tailwind:run [--watch] [--minify]` compiles it into `var/tailwind/` and publishes it with the assets, `config/packages/tailwind.yaml`.