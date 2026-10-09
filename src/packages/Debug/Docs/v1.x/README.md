# Debug

The Debug package (`src/packages/Debug`) dumps variables while developing: `dump()` and `dd()` global functions, collapsible HTML dumps, colored console dumps and a `dump()` view helper.
Dumps are active only when `APP_DEBUG` is true, so a forgotten dump never leaks data in production.

## Summary

- [Dumping values](#dumping-values)
- [Output](#output)
- [Configuration](#configuration)
- [DebugInterface](#debuginterface)
- [Error page](#error-page)
- [Console command](#console-command)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Dumping values

```php
dump($user);
dump($request, $post, 42);
dd($form->getData());
```

| Function | Description |
|---|---|
| `dump(mixed ...$values): mixed` | dumps the values and continues; returns the value (or the array of values when several are given) |
| `dd(mixed ...$values): never` | dumps the values and stops the script |

`dump()` and `dd()` are global functions loaded by Composer (`autoload.files`; run `composer dump-autoload` after upgrading), available everywhere: controllers, services, templates, commands and plain scripts. Each dump shows the file and line where it was called.

When the package is disabled, `dump()` does nothing and `dd()` only stops the script (HTTP 500).

### In templates

```twig
{{ dump(items, post) }}
```

```php
<?= $this->dump($items) ?>
```

The `dump` view helper renders the dump at this place, and nothing when the package is disabled (see the Views documentation).

## Output

| Where | Output |
|---|---|
| HTTP | the dumps are collected and inserted at the top of the `<body>` of the response (before the text for non-HTML responses) by a `ResponseEvent` listener, so the session, the cookies and the headers still work after a `dump()`; `dd()` prints them immediately |
| Console | colored text on the standard output (plain text when it is not a terminal or when `NO_COLOR` is set) |
| Templates | the HTML dump, at the place of the helper |

The HTML dump is collapsible (click on ▼ / ▶): the first level is open, the deeper ones are closed. Values show:

- their type and size (`array:3`, `"string"` with its length on hover)
- objects with their class and id (`App\Entity\Post {#12}`), properties with their visibility (`+` public, `#` protected, `-` private, `~` virtual)
- enums (`PostStatus::Draft "draft"`), closures (parameters, file, lines), dates, resources and binary strings (`b"\xFF"`)

An object already dumped in the same value is shown as `{#12}` (no infinite recursion).

## Configuration

`config/packages/debug.yaml`:

```yaml
enabled: '%kernel.debug%'
max_depth: 10
max_items: 250
max_string: 1000
expand_depth: 1
```

| Key | Default | Description |
|---|---|---|
| `enabled` | `%kernel.debug%` | enables the dumps |
| `max_depth` | `10` | nested levels dumped |
| `max_items` | `250` | items per array / properties per object |
| `max_string` | `1000` | characters per string |
| `expand_depth` | `1` | levels open in the HTML dump |

## DebugInterface

Outside `dump()`, inject `NeoPHP\Package\Debug\Contract\DebugInterface` (implemented by `NeoPHP\Package\Debug\DebugManager`):

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Package\Debug\Contract\DebugInterface;

class ReportService
{
    public function __construct(private DebugInterface $debug)
    {
    }

    public function describe(array $data): string
    {
        return $this->debug->isEnabled() ? $this->debug->toHtml($data, 'Report') : '';
    }
}
```

| Method | Description |
|---|---|
| `isEnabled(): bool` / `setEnabled(bool $enabled): static` | state of the dumps |
| `isCli(): bool` | whether PHP runs in the console |
| `dump(mixed ...$values): void` | dumps the values (collected in HTTP, printed in the console) |
| `dumpFrom(?string $location, array $values): void` | same, with the location (`file:line`) shown |
| `dd(mixed ...$values): never` / `ddFrom(?string $location, array $values): never` | dumps and stops |
| `toHtml(mixed $value, ?string $label = null, ?int $maxDepth = null): string` | the HTML dump of a value |
| `toText(mixed $value, ?string $label = null, bool $colors = false): string` | the text dump of a value |
| `hasPending(): bool` | whether collected dumps wait to be output |
| `flush(bool $html = true): string` | returns and clears the collected dumps |
| `injectInto(Response $response): Response` | inserts the collected dumps in a response |
| `getCloner(): VarCloner` | the cloner that turns values into dump nodes |

`DebugManager::getInstance()` / `DebugManager::setInstance()` give the instance used by the global functions. `AbstractDebug` also provides `getOptions()` and `setStream(mixed $stream)` (the stream of the console output).

### Dumpers

| Class | Description |
|---|---|
| `NeoPHP\Package\Debug\Cloner\VarCloner` | `cloneVar(mixed $value): array` builds the dump tree, limited by `getMaxDepth()`, `getMaxItems()`, `getMaxString()` |
| `NeoPHP\Package\Debug\Contract\DumperInterface` | `dump(array $node, ?string $label = null): string` |
| `NeoPHP\Package\Debug\Dumper\HtmlDumper` | HTML output, `new HtmlDumper(int $expandDepth = 1)`, `resetAssets()` outputs the CSS/JS again |
| `NeoPHP\Package\Debug\Dumper\CliDumper` | text output, `new CliDumper(bool $colors = false)`, `hasColors()` |

## Error page

When the debug mode is on, the error page uses the dumper to display the context of the exception (`FrameworkException` context) and the arguments of each frame of the stack trace (collapsed; PHP only records them when `zend.exception_ignore_args` is `Off`, the default of the development `php.ini`). See the Exceptions documentation.

## Console command

```bash
php bin/neo debug:container
php bin/neo debug:container orm
php bin/neo debug:container NeoPHP\\Package\\Orm\\Contract\\OrmInterface --dump
php bin/neo debug:container --parameters
```

`debug:container [search] [--dump|-d] [--parameters|-p]`:

- without argument, lists the services of the container (id, kind: singleton / factory / instance / alias, class, resolved or not); a text filters the list
- an id shows the details of a service (alias target, class, constructor arguments); `--dump` builds it and dumps it
- `--parameters` lists the parameters (`kernel.*`)

## Exceptions

`NeoPHP\Package\Debug\Exception\DebugException` (extends `FrameworkException`) is thrown by the package on errors.

## Changelog

- v1.15.0 — `debug:container` rewritten as an `AbstractConsole` command.
- v1.14.0 — Debug package: `dump()` and `dd()` global functions, collapsible HTML dumps inserted in the response, colored console dumps, `dump()` view helper, dumps disabled when `APP_DEBUG` is false, exception context and stack trace arguments dumped on the error page, `debug:container` command, `config/packages/debug.yaml`.