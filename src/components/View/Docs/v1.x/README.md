# View

The View component renders templates with a built-in PHP engine or with Twig when it is installed.
View helpers written once work in both engines and are discovered automatically in every `Helper/View/` directory.

## Summary

- [Engines](#engines)
- [Rendering](#rendering)
- [PHP templates](#php-templates)
- [Twig templates](#twig-templates)
- [View helpers](#view-helpers)
- [Configuration](#configuration)
- [API](#api)
- [Changelog](#changelog)

## Engines

Templates live in `templates/`. The extension selects the engine:

| Extension | Engine |
|---|---|
| `.php` | built-in PHP engine |
| `.html.twig`, `.twig` | Twig, only when `twig/twig` is installed (`composer require twig/twig`) |

`render('user/show')` looks for `user/show.php`, then `user/show.html.twig`, then `user/show.twig`. A name with its extension (`user/show.html.twig`) is used as is. Rendering a `.twig` template without Twig installed throws an explicit `ViewException`; a missing template throws a `TemplateNotFoundException` listing the searched files.

## Rendering

In a controller:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;

final class UserController extends AbstractController
{
    public function show(int $id): Response
    {
        return $this->render('user/show', ['id' => $id]);
    }
}
```

| Method | Description |
|---|---|
| `render(string $template, array $parameters = [], int $status = 200, array $headers = []): Response` | renders a template into a response |
| `renderView(string $template, array $parameters = []): string` | renders a template into a string |

Outside a controller, inject `NeoPHP\Component\View\Contract\ViewInterface`:

```php
$html = $this->view->render('emails/welcome', ['name' => $name]);
```

## PHP templates

Inside a PHP template, the parameters are variables and `$this` gives access to:

| Method | Description |
|---|---|
| `$this->extend('base', [...])` | renders the template inside the `base` layout (layouts can be nested) |
| `$this->start('name')` / `$this->stop()` | captures a section; `start('name', true)` appends to it |
| `$this->section('name', 'default')` | outputs a section in a layout |
| `$this->hasSection('name')` | whether a section is defined |
| `$this->include('partials/menu', [...])` | renders a partial |
| `$this->e($value)` | escapes a value for HTML |
| `$this->filter('name', $value, ...)` | applies a view filter |
| `$this->name(...)` | calls a view function (`path()`, `asset()`, `config()`...) |

`templates/base.php`

```php
<!DOCTYPE html>
<html>
<head><title><?= $this->e($title ?? 'NeoPHP') ?></title></head>
<body>
<?= $this->section('content') ?>
</body>
</html>
```

`templates/user/show.php`

```php
<?php $this->extend('base', ['title' => 'User']) ?>

<?php $this->start('content') ?>
<h1>User #<?= $this->e($id) ?></h1>
<a href="<?= $this->path('home') ?>">Home</a>
<?php $this->stop() ?>
```

## Twig templates

`templates/base.html.twig`

```twig
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}{{ config('framework.app.name') }}{% endblock %}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
{% block body %}{% endblock %}
</body>
</html>
```

`templates/user/show.html.twig`

```twig
{% extends 'base.html.twig' %}

{% block body %}
<h1>User #{{ id }}</h1>
<a href="{{ path('home') }}">Home</a>
{% endblock %}
```

Twig errors are wrapped in a `ViewException`.

## View helpers

A view helper works with every engine and never imports Twig. Its interfaces define what it provides:

| Interface | Result |
|---|---|
| `ViewFunctionInterface` | function: `{{ name(...) }}` / `$this->name(...)` |
| `ViewFilterInterface` | filter: `{{ value\|name }}` / `$this->filter('name', $value)` |
| `ViewGlobalInterface` | global variable (`getValue()`): `{{ name }}` / `$name` |
| `ViewSafeHtmlInterface` | the output is not escaped |

`getName()` gives the name used in templates. Functions and filters are called through `__invoke()`.

### Writing a helper

`src/Shop/Helper/View/PriceViewHelper.php`

```php
<?php

declare(strict_types=1);

namespace App\Shop\Helper\View;

use NeoPHP\Component\View\Contract\ViewFilterInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

class PriceViewHelper implements ViewFunctionInterface, ViewFilterInterface
{
    public function getName(): string
    {
        return 'price';
    }

    public function __invoke(float $amount, string $currency = '€'): string
    {
        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }
}
```

```twig
{{ price(12.5) }}  {{ amount|price('$') }}
```

```php
<?= $this->price(12.5) ?>  <?= $this->filter('price', $amount, '$') ?>
```

### Discovery

The View component contains no helper: it discovers every `Helper/View/` directory of the framework and of the application (`src/**/Helper/View/`). Dependencies are autowired. An application helper with the same name as a framework helper replaces it.

The file name must match the class (PSR-4): in debug, a helper file that declares another class (e.g. `MardownViewHelper.php` containing `MarkdownViewHelper`) throws an error naming the file; in production it is ignored.

Helpers stored outside a `Helper/View/` directory can be listed under `helpers` in `config/framework/view.yaml`.

### Framework helpers

| Helper | Name |
|---|---|
| Asset | `asset()` |
| Config | `config()` |
| Cookie | `cookie()` |
| Flash | `flashes()` |
| Routing | `path()`, `url()` |
| Session | `session()` |
| Csrf | `csrf_token()`, `csrf_field()` |
| Form | `form_*()` |
| Security (package) | `app_user()`, `is_granted()`, `logout_path()`, `last_username()`, `last_authentication_error()` |
| Debug (package) | `dump()` |

See the documentation of each feature.

## Configuration

`config/framework/view.yaml`

```yaml
paths:
  - '%kernel.templates_path%'

namespaces:
  admin: '%kernel.root_path%/templates/admin'

helpers: []

twig:
  enabled: true
  cache: '%kernel.root_path%/var/cache/twig'
  auto_reload: true
  strict_variables: '%kernel.debug%'
```

| Option | Description |
|---|---|
| `paths` | template directories |
| `namespaces` | named directories: `@admin/dashboard` renders `templates/admin/dashboard.*` |
| `helpers` | additional helper classes |
| `twig.enabled` | `false` disables Twig even when it is installed |
| `twig.*` | Twig options: `cache`, `debug`, `auto_reload`, `strict_variables`, `autoescape`, `charset` |

## API

### ViewInterface

`NeoPHP\Component\View\Contract\ViewInterface`, implemented by `ViewManager(array $paths = [], ?array $engines = null)` (extends `AbstractView`, PHP engine by default):

| Method | Description |
|---|---|
| `render(string $template, array $parameters = []): string` | renders a template |
| `exists(string $template): bool` | whether a template exists |
| `locate(string $template, ?string $engine = null): string` | absolute file of a template |
| `addPath(string $path, ?string $namespace = null): static` | adds a template directory |
| `getPaths(): array` | template directories |
| `addGlobal(string $name, mixed $value): static` | adds a global variable |
| `addHelper(string $name, callable $helper, bool $safe = false): static` | adds a function |
| `addFilter(string $name, callable $filter, bool $safe = false): static` | adds a filter |
| `addExtension(ViewHelperInterface $extension): static` | adds a helper object |
| `addEngine(EngineInterface $engine): static` | adds an engine |
| `getEngines(): array` | registered engines |

```php
$view->addGlobal('site_name', 'My shop');
$view->addHelper('year', static fn (): string => date('Y'));
$view->addFilter('shout', static fn (string $text): string => strtoupper($text));
```

### Helper contracts

| Interface | Methods |
|---|---|
| `ViewHelperInterface` | `getName(): string` |
| `ViewFunctionInterface`, `ViewFilterInterface` | extend `ViewHelperInterface`; `__invoke(...)` |
| `ViewGlobalInterface` | `getValue(): mixed` |
| `ViewSafeHtmlInterface` | marker: output not escaped |

### EngineInterface

`NeoPHP\Component\View\Engine\EngineInterface` (implemented by `PhpEngine` and `TwigEngine`):

| Method | Description |
|---|---|
| `getName(): string` | engine name |
| `getExtensions(): array` | handled file extensions |
| `render(string $template, string $file, array $parameters): string` | renders a file |
| `addFunction()`, `addFilter()`, `addGlobal()`, `addPath()` | receive the helpers and paths of the view |

`TwigEngine(array $options = [])` exposes `getEnvironment(): Twig\Environment`. `PhpEngine` limits nested layouts to `MAX_LAYOUT_DEPTH` (20).

### Other classes

| Class | Description |
|---|---|
| `Discovery\HelperDiscovery` | finds helper classes: `addSource(string $path, string $namespace)`, `getSources()`, `discover(): array`, `setStrict(bool)` / `isStrict()` |
| `Template\Template` | the `$this` of PHP templates |
| `Provider\ViewProvider` | builds the view from `framework.view` |
| `Exception\ViewException` | engine or template error |
| `Exception\TemplateNotFoundException` | template not found |

## Changelog

- Bugfix after v1.17.0 — In debug, a view helper file whose name does not match its class throws an error.
- v1.4.0 — `render()` and `renderView()` provided by the `ViewController` trait.
- v1.2.0 — Optional Twig engine, engine-agnostic view helpers discovered in each `Helper/View/` directory, `config/framework/view.yaml`; the `asset()` helper moves to the Asset feature.
- v1.0.0 — PHP views with layouts, sections and partials.