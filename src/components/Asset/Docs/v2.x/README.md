# Asset

The Asset component compiles the files of `assets/` into `public/builds/` with hashed file names and a `manifest.json`.
Templates get the compiled URL with the `asset()` view helper; CSS references are rewritten and CSS / JavaScript can be minified.

## Summary

- [Module](#module)
- [Build layout](#build-layout)
- [In templates](#in-templates)
- [Compilation modes](#compilation-modes)
- [Command](#command)
- [CSS rewriting](#css-rewriting)
- [Configuration](#configuration)
- [PHP API](#php-api)
- [Custom compilers](#custom-compilers)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Asset\AssetManager` (`final`) |
| Interface | `NeoPHP\Component\Asset\AssetManagerInterface` |
| Attribute | `#[Component(provider: AssetProvider::class)]` |
| Requires | nothing |

Inject `AssetManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Asset\AssetManagerInterface;

public function __construct(private AssetManagerInterface $asset)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\Asset\AssetManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` (provider, discoveries, traces, `Helper/View`, `Helper/Console`, `Helper/Event`, `Helper/WebProfiler`) are used by the framework only.

## Build layout

Assets are stored in `assets/` and compiled into `public/builds/`, with the same structure. A hash of the content is added to every file name: `{filename}-{hash}.{extension}`.

```
assets/css/app.css        ->  public/builds/css/app-3f2a9c1b.css
assets/img/logo.png       ->  public/builds/img/logo-d07ec8c2.png
assets/js/app.js          ->  public/builds/js/app-8801909f.js
```

`public/builds/manifest.json` maps each asset to its compiled URL:

```json
{
    "css/app.css": "/builds/css/app-3f2a9c1b.css",
    "img/logo.png": "/builds/img/logo-d07ec8c2.png"
}
```

## In templates

`asset($path)` returns the compiled URL:

```twig
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<img src="{{ asset('img/logo.png') }}" alt="">
```

```php
<link rel="stylesheet" href="<?= $this->e($this->asset('css/app.css')) ?>">
```

Absolute URLs given to `asset()` (`https://...`, `//...`) are returned unchanged.

## Compilation modes

| Mode | Behavior of `asset()` |
|---|---|
| debug (`auto_compile: true`) | compiles the asset again when its content changed, updates the manifest and removes the previous build |
| production (`auto_compile: false`) | reads the manifest; an asset missing from the manifest is compiled once |

## Command

```bash
php bin/neo asset:reload
php bin/neo asset:reload --minify
```

`asset:reload` empties `public/builds/`, compiles every file of `assets/` and rebuilds the manifest. Run it on every deployment.

| Option | Description |
|---|---|
| `--minify`, `-m` | minifies CSS (comments and whitespace) and JavaScript (comments and indentation, line breaks are kept) |

## CSS rewriting

In CSS files, `url(...)` and `@import` pointing to another file of `assets/` are rewritten to the compiled URL: `url('../img/logo.png')` becomes `url('../img/logo-d07ec8c2.png')` (a path relative to the compiled CSS file, so it works in a sub-directory and on a CDN). External URLs, absolute paths and files outside `assets/` are kept as is.

## Configuration

`config/framework/asset.yaml` (key `framework.asset`):

```yaml
source_path: '%kernel.root_path%/assets'
build_path: '%kernel.public_path%/builds'
public_url: /builds
auto_compile: '%kernel.debug%'

hash:
  algorithm: xxh128
  length: 8
```

| Option | Default | Description |
|---|---|---|
| `source_path` | `%kernel.root_path%/assets` | directory of the assets |
| `build_path` | `%kernel.public_path%/builds` | directory of the compiled files and of `manifest.json` |
| `public_url` | `/builds` | URL prefix of the compiled files (a CDN URL can be used) |
| `auto_compile` | `%kernel.debug%` | compiles the changed assets on each request |
| `hash.algorithm` | `xxh128` | hash function (any `hash_algos()` value) |
| `hash.length` | `8` | number of characters kept (minimum 4) |

## PHP API

Inject `NeoPHP\Component\Asset\AssetManagerInterface` (implemented by `AssetManager`).

| Method | Description |
|---|---|
| `url(string $path): string` | compiled URL of an asset (what `asset()` returns) |
| `compile(string $path): string` | compiles one asset and returns its URL |
| `reload(bool $minify = false): array` | clears the builds, compiles every asset, saves the manifest; returns `[path => url]` |
| `clear(): void` | empties the build directory and the manifest |
| `addCompiler(CompilerInterface $compiler): static` | registers a compiler |
| `setSourceFile(string $path, ?string $file): static` | compiles `$file` in place of `assets/$path`; `null` removes the override |
| `getSourceFile(string $path): string` | file actually compiled for `$path` |
| `getSourcePath(): string` / `getBuildPath(): string` | configured directories |
| `getManifest(): Manifest` | the manifest |

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Asset\AssetManagerInterface;

class LogoUrl
{
    public function __construct(protected AssetManagerInterface $asset)
    {
    }

    public function get(): string
    {
        return $this->asset->url('img/logo.png');
    }
}
```

`setSourceFile('css/app.css', $file)` is used by the Tailwind package (see the Tailwind documentation); the original source is used again when `$file` does not exist.

`Manifest` (`NeoPHP\Component\Asset\Manifest\Manifest`) provides `getFile()`, `all()`, `get($path)`, `set($path, $url)`, `remove($path)`, `clear()` and `save()`.

`AssetManager` can be built directly with `new AssetManager($sourcePath, $buildPath, $publicUrl, $autoCompile, $hashAlgorithm, $hashLength, $compilers)` or `AssetManager::fromConfig(array $config, array $defaults = [])`.

## Custom compilers

A compiler implements `NeoPHP\Component\Asset\Compiler\CompilerInterface`. `$resolve` returns the compiled URL of another asset path. The built-in compilers are `CssCompiler` (`css`) and `JsCompiler` (`js`, `mjs`); other files are copied as is.

```php
<?php

declare(strict_types=1);

namespace App\Asset;

use NeoPHP\Component\Asset\Compiler\CompilerInterface;

class SvgCompiler implements CompilerInterface
{
    public function supports(string $extension): bool
    {
        return $extension === 'svg';
    }

    public function compile(string $content, string $path, callable $resolve, bool $minify = false): string
    {
        return $minify ? (string) preg_replace('/>\s+</', '><', $content) : $content;
    }
}
```

```php
$asset->addCompiler(new SvgCompiler());
```

## Exceptions

`NeoPHP\Component\Asset\Exception\AssetException` is thrown for an unsupported hash algorithm, a missing asset or an unsafe build path.

## Changelog

- v2.0.0 — `AssetManager` is the `final` entry point of the module, declared with `#[Component]`; `AssetManagerInterface` replaces `Contract\AssetInterface`; `Contract\AbstractAsset` is merged into the manager; the internal classes are marked `@internal`.
- v1.31.0 — `asset()` adds the sub-directory of the application (`setBasePath()`); URLs rewritten in CSS files are relative to the compiled file.
- v1.18.0 — `AssetInterface::setSourceFile()` (and `getSourceFile()`) to compile another file in place of an asset.
- v1.3.0 — Asset component: `assets/` compiled into `public/builds/` with hashed names, `manifest.json`, `asset()` helper, `asset:reload [--minify]` command.