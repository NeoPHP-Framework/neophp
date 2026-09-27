# WebProfiler

The WebProfiler package collects information about every HTTP request in development and shows it in a web debug toolbar injected at the bottom of HTML pages and in a profiler interface (`/_profiler`).
It is a structure: the package only renders, stores and routes. Each feature (component, package, process or application code) adds its own element by creating one class that returns plain data and value objects; the package does all the HTML.
No external library is used: the pages and the toolbar are self-contained (inline CSS and JS, no CDN, light and dark themes).

## Summary

- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [Adding an element to a feature](#adding-an-element-to-a-feature)
- [Custom elements in the application](#custom-elements-in-the-application)
- [Toolbar items](#toolbar-items)
- [Panels and blocks](#panels-and-blocks)
- [Custom block types](#custom-block-types)
- [Stopwatch](#stopwatch)
- [Storage](#storage)
- [Configuration](#configuration)
- [Routes](#routes)
- [Console](#console)
- [Built-in elements](#built-in-elements)
- [Exceptions](#exceptions)
- [Limitations](#limitations)
- [Changelog](#changelog)

## Quick start

The package is enabled when the kernel runs in debug mode (`APP_DEBUG=1`, default in `dev`). Nothing else is required:

1. open any HTML page of the application: the toolbar appears at the bottom;
2. click an item (or the list icon) to open the full profile;
3. every response carries `X-Debug-Token` and `X-Debug-Token-Link` headers, also for JSON APIs.

In production (`kernel.debug` false) the package never collects, never registers its routes and never injects anything.

## How it works

| Step | Event | What happens |
|---|---|---|
| 1 | `RequestEvent` (priority 2048) | the routes are registered, the stopwatch starts (`bootstrap`, `kernel.request`) |
| 2 | `ControllerEvent` (priority -2048) | `kernel.request` stops, `controller` starts |
| 3 | `ExceptionEvent` (priority 2048) | the exception is kept for the elements |
| 4 | `ResponseEvent` (priority -2048) | every element `collect()`s, the profile is stored, the headers are added, the toolbar loader is injected before `</body>` |
| 5 | browser | the loader fetches `/_wdt/{token}` (toolbar fragment) and tracks the fetch / XMLHttpRequest calls of the page |

Elements are discovered and cached in `var/cache/web_profiler/profilers.{env}.php` (refreshed automatically in debug when a file changes):

- framework: every class in `src/{components|packages|process}/<Feature>/Helper/Profiler/*.php` implementing `ProfilerElementInterface` (path convention, no attribute needed);
- application: every class of `src/` carrying `#[AsProfiler]` and implementing `ProfilerElementInterface`;
- configuration: the classes listed in `panels:` of `config/packages/web_profiler.yaml`.

Elements are resolved through the container (constructor autowiring) and sorted by priority (highest first); the priority is `#[AsProfiler(priority: …)]` when set, else `getPriority()`.

## Adding an element to a feature

Create `./src/{components|packages|process}/<Feature>/Helper/Profiler/<Feature>Profiler.php`. An element implements `ToolbarInterface`, `ProfilerInterface` or both; both extend `ProfilerElementInterface`, so the data is collected once:

```php
interface ProfilerElementInterface
{
    public function getName(): string;

    public function getPriority(): int;

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array;
}

interface ToolbarInterface extends ProfilerElementInterface
{
    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem;
}

interface ProfilerInterface extends ProfilerElementInterface
{
    public function getPanel(Profile $profile, array $data): ?Panel;
}
```

Rules:

- `collect()` runs once per request and returns **JSON-safe data** (scalars and arrays). It must not keep objects: values are passed through `ValueExporter::export()` anyway (objects become strings or arrays), and the profile is read later, in another request;
- `getToolbarItem()` and `getPanel()` run when the toolbar or the profiler page is displayed, only with the stored `$data`: never access services there;
- return `null` to hide the item or the panel for this profile;
- never write HTML: return value objects, the package escapes and renders them.

`AbstractProfiler` gives `getName()` (short class name without `Profiler`, snake case: `CacheProfiler` → `cache`), `getPriority()` (constant `PRIORITY`) and `export()`.

Full example for the Cache component (assuming the cache manager records its calls in a `getCalls()` method), `./src/components/Cache/Helper/Profiler/CacheProfiler.php`:

```php
<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Helper\Profiler;

use NeoPHP\Component\Cache\Contract\CacheManagerInterface;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

class CacheProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 50;

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(CacheManagerInterface::class)) {
            return [];
        }

        $calls = $this->container->get(CacheManagerInterface::class)->getCalls();

        return [
            'hits' => count(array_filter($calls, static fn (array $call): bool => $call['hit'])),
            'calls' => array_map(static fn (array $call): array => [$call['pool'], $call['method'], $call['key'], $call['hit'], $call['time']], $calls),
        ];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        if (($data['calls'] ?? []) === []) {
            return null;
        }

        $calls = count($data['calls']);
        $ratio = (int) round($data['hits'] / $calls * 100);

        return new ToolbarItem('Cache', (string) $calls, 'cache', $ratio < 50 ? Status::WARNING : Status::DEFAULT, [
            'Calls' => $calls,
            'Hits' => $data['hits'],
            'Hit ratio' => $ratio . ' %',
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $calls = (array) ($data['calls'] ?? []);

        return new Panel('Cache', 'cache', [
            new MetricBlock([
                new Metric('Calls', count($calls)),
                new Metric('Hits', (int) ($data['hits'] ?? 0), null, Status::SUCCESS),
            ]),
            new TableBlock(['Pool', 'Method', 'Key', 'Hit', 'Time (ms)'], $calls, 'Calls', 'No cache call.'),
            new KeyValueBlock(['Profile' => $profile->getToken()], 'Context'),
        ], count($calls));
    }
}
```

Nothing to register: the class is found on the next request (the discovery cache is refreshed in debug). If the feature does not depend on the WebProfiler package at runtime, keep the element in `Helper/Profiler`: it is only loaded when the profiler is enabled.

To measure durations in the timeline, inject the `Stopwatch` (see [Stopwatch](#stopwatch)).

## Custom elements in the application

Any class of `src/` with `#[AsProfiler]`:

```php
<?php

declare(strict_types=1);

namespace App\Profiler;

use App\Service\Cart;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Attribute\AsProfiler;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

#[AsProfiler(priority: 10)]
class CartProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public function __construct(protected Cart $cart)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        return ['items' => $this->cart->count(), 'total' => $this->cart->total()];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        return new ToolbarItem('Cart', (string) $data['items'], 'info');
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        return new Panel('Cart', 'info', [new KeyValueBlock($data)]);
    }
}
```

Classes outside `src/` (a vendor library for instance) can be listed in the configuration:

```yaml
panels:
    - Acme\Profiler\QueueProfiler
```

Two elements cannot share the same `getName()` (`InvalidElementException`). An element throwing during `collect()` or `getPanel()` does not break the request: its panel shows the error.

## Toolbar items

`NeoPHP\Package\WebProfiler\Model\ToolbarItem`:

| Argument | Type | Description |
|---|---|---|
| `label` | `string` | text after the value (hidden on small screens) |
| `value` | `string` | short value in bold (`200`, `12 ms`) |
| `icon` | `string` | a built-in icon key or a trusted inline `<svg>` |
| `status` | `string` | `Status::DEFAULT`, `SUCCESS`, `INFO`, `WARNING`, `DANGER` (red item) |
| `details` | `array` | `label => value` rows of the popover (escaped) |
| `panel` | `?string` | panel opened on click; default: the element name when it implements `ProfilerInterface` |
| `linked` | `bool` | `false` for an item without link |

Built-in icon keys: `info`, `request`, `response`, `time`, `memory`, `exception`, `config`, `database`, `route`, `user`, `mail`, `cache`, `event`, `log`, `view`, `form`, `security`, `ajax`, `php`, `logo`, `close`, `list` (`Renderer\Icons`).

The toolbar also shows an **Ajax** item listing the `fetch()` and `XMLHttpRequest` calls made by the page (method, status, URL, duration and a link to their profile read from the `X-Debug-Token` response header). The toolbar can be hidden (state remembered in `localStorage`).

## Panels and blocks

`Model\Panel(title, icon = 'info', blocks = [], badge = null, badgeStatus = Status::DEFAULT)` is displayed in the left menu of the profile page with its badge. `add(BlockInterface)` appends a block.

Built-in blocks (`NeoPHP\Package\WebProfiler\Block`), all values are escaped:

| Block | Type | Constructor |
|---|---|---|
| `TableBlock` | `table` | `(headers, rows = [], title = null, emptyMessage = 'No data.')`, a cell can be a scalar, an array (dumped) or a block |
| `KeyValueBlock` | `key_value` | `(items, title = null, emptyMessage)` |
| `MetricBlock` | `metric` | `(Metric[] metrics, title = null)`, `Metric(label, value, unit = null, status, help = null)` |
| `TimelineBlock` | `timeline` | `(events, total = null, title = null)`, events are `TimelineEvent(name, start ms, duration ms, category, memory)` or arrays (`Stopwatch::toArray()`) |
| `CodeBlock` | `code` | `(content, title = null, language = null, firstLine = 0, highlightLine = null)` |
| `AlertBlock` | `alert` | `(message, status = Status::INFO, title = null)` |
| `TextBlock` | `text` | `(text, title = null)` |
| `SectionBlock` | `section` | `(title, blocks, collapsed = false)`, collapsible |
| `TabsBlock` | `tabs` | `(['Tab label' => blocks[]], title = null)` |
| `HtmlBlock` | `html` | `(html, title = null)`: **raw HTML, not escaped**. Only for trusted HTML built by the developer, never for collected data |

## Custom block types

A block implements `BlockInterface` (`getType()`) or extends `AbstractBlock` (constant `TYPE`, optional title). Its renderer implements `BlockRendererInterface`:

```php
class ChartBlockRenderer implements BlockRendererInterface
{
    public function getTypes(): array
    {
        return [ChartBlock::TYPE];
    }

    public function render(BlockInterface $block, BlockRenderer $renderer): string
    {
        return $renderer->title($block->getTitle()) . '<div class="chart">' . $renderer->escape($block->getLabel()) . '</div>';
    }
}
```

Register it in `block_renderers:` of the configuration, or at runtime with `$container->get(BlockRenderer::class)->register(new ChartBlockRenderer())`. `BlockRenderer` gives `escape()`, `value()`, `title()`, `render()`, `renderBlocks()` and `uniqueId()`.

## Stopwatch

`NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch` (service `Stopwatch::class`, alias `stopwatch`) measures named events displayed in the Performance timeline:

```php
$stopwatch->start('orm.query', 'database');
$stopwatch->stop('orm.query');
$stopwatch->lap('import');
$result = $stopwatch->measure('render', fn () => $view->render(), 'view');
```

Times are in milliseconds from the start of the request (`REQUEST_TIME_FLOAT`). Categories `kernel`, `controller`, `database` and `view` have their own color.

## Storage

Profiles are JSON files in `var/profiler/{token}.json` with an index `var/profiler/index.jsonl` (one summary per line). `FileProfileStorage` implements `ProfileStorageInterface`:

| Method | Description |
|---|---|
| `write(Profile)` | stores the profile, then purges |
| `read(token)` | `?Profile` |
| `find(limit, filters)` | summaries, newest first; filters `ip`, `url` (contains), `method`, `status` (`404` or `4xx`), `token` (prefix), `route` |
| `purge()` | deletes the profiles over `max_profiles` or older than `lifetime` |
| `clear()` | deletes everything |

Replace the storage by binding another `ProfileStorageInterface` implementation in the container.

`Model\Profile` holds `token`, `ip`, `method`, `url`, `status`, `time`, `duration` (ms), `memory` (peak bytes), `route`, `content_type` and the data of each element (`getData('request')`).

## Configuration

`config/packages/web_profiler.yaml`:

```yaml
enabled: '%kernel.debug%'
toolbar: true
path: /_profiler
toolbar_path: /_wdt
storage: var/profiler
max_profiles: 200
lifetime: 86400
ajax_limit: 50
excluded_paths:
    - '^/(favicon\.ico|robots\.txt|build|builds|assets)(/|$)'
panels: []
block_renderers: []
```

| Option | Default | Description |
|---|---|---|
| `enabled` | `%kernel.debug%` | never enabled when `kernel.debug` is false |
| `toolbar` | `true` | injects the toolbar in HTML responses |
| `path` | `/_profiler` | prefix of the profiler pages |
| `toolbar_path` | `/_wdt` | prefix of the toolbar fragment |
| `storage` | `var/profiler` | relative to the project root |
| `max_profiles` | `200` | profiles kept |
| `lifetime` | `86400` | seconds, `0` = unlimited |
| `ajax_limit` | `50` | requests listed in the Ajax item |
| `excluded_paths` | assets, favicon, robots | regular expressions of paths never profiled |
| `panels` | `[]` | extra element classes |
| `block_renderers` | `[]` | extra `BlockRendererInterface` classes |

The profiler and toolbar paths are always excluded. The toolbar is only injected in responses with a `text/html` content type containing `</body>`, not for redirections, attachments, `HEAD` or `XMLHttpRequest` requests.

## Routes

Registered at request time (only when enabled):

| Name | Path | Description |
|---|---|---|
| `_profiler_index` | `/_profiler` | last profiles with search filters (`ip`, `url`, `method`, `status`, `token`, `limit`) |
| `_profiler_latest` | `/_profiler/latest` | redirects to the last profile |
| `_profiler_json` | `/_profiler/{token}.json` | raw profile |
| `_profiler_show` | `/_profiler/{token}?panel=name` | summary, menu of panels and selected panel |
| `_profiler_toolbar` | `/_wdt/{token}` | toolbar HTML fragment |

## Console

| Command | Description |
|---|---|
| `profiler:list [--limit=20] [--url=] [--method=] [--status=5xx] [--ip=]` | lists the last profiles |
| `profiler:clear [--expired]` | deletes all the profiles (or only the expired ones) |

## Built-in elements

In `src/packages/WebProfiler/Helper/Profiler/`:

| Element | Name | Toolbar | Panel |
|---|---|---|---|
| `RequestProfiler` | `request` | status code and route | request / response headers, query, body, attributes, cookies (values hidden), session, server |
| `ExceptionProfiler` | `exception` | red item with the class (only on exception) | message, source excerpt, stack trace, previous exceptions |
| `PerformanceProfiler` | `performance` | duration, memory in the popover | metrics, timeline, stopwatch events |
| `ConfigProfiler` | `config` | NeoPHP version, env, debug, PHP | framework, PHP, php.ini, extensions, registered elements |

Sensitive keys (`password`, `token`, `secret`, `authorization`, `cookie`, `api_key`, `csrf`) are masked.

## Exceptions

`NeoPHP\Package\WebProfiler\Exception\*`, all extend `WebProfilerException`, a `FrameworkException`:

| Exception | Thrown when |
|---|---|
| `WebProfilerException` | missing template, invalid block renderer class |
| `InvalidElementException` | an element not implementing `ProfilerElementInterface`, duplicated element name, a panel block not implementing `BlockInterface` |
| `StorageException` | profiler directory or files not writable, invalid token |

## Limitations

- The toolbar loader is an inline `<script>`: a strict Content-Security-Policy without `'unsafe-inline'` blocks it.
- Streamed or binary responses are profiled but never receive the toolbar.
- The file storage is meant for development (no concurrency guarantee beyond `LOCK_EX`).

## Changelog

- v1.25.0 — WebProfiler package: profiler and web debug toolbar structure, `ProfilerElementInterface` / `ToolbarInterface` / `ProfilerInterface` elements discovered in `Helper/Profiler` of every feature and with `#[AsProfiler]` in the application (cached), `ToolbarItem` and `Panel` value objects, blocks (`Table`, `KeyValue`, `Metric`, `Timeline`, `Code`, `Alert`, `Text`, `Section`, `Tabs`, `Html`) with an extensible `BlockRenderer`, `FileProfileStorage`, `Stopwatch`, `X-Debug-Token` headers, Ajax requests tracking, `/_profiler` and `/_wdt` routes, `profiler:list` and `profiler:clear` commands, built-in Request, Exception, Performance and Config elements.