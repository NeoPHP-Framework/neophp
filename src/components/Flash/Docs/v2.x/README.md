# Flash

The Flash component stores messages in the session until they are read, to display them after a redirect.
It requires the Session component.

## Summary

- [Module](#module)
- [Adding messages](#adding-messages)
- [Displaying messages](#displaying-messages)
- [FlashManagerInterface](#flashmanagerinterface)
- [Configuration](#configuration)
- [Profiler](#profiler)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Flash\FlashManager` (`final`) |
| Interface | `NeoPHP\Component\Flash\FlashManagerInterface` |
| Attribute | `#[Component(provider: FlashProvider::class, requires: [SessionManager::class])]` |
| Requires | Session |

Inject `FlashManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Flash\FlashManagerInterface;

public function __construct(private FlashManagerInterface $flash)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\Flash\FlashManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` (provider, discoveries, traces, `Helper/View`, `Helper/Console`, `Helper/Event`, `Helper/WebProfiler`) are used by the framework only.

## Adding messages

In a controller (trait `FlashController`):

```php
$this->addFlash('success', 'Your profile has been saved.');
```

In a service, inject `NeoPHP\Component\Flash\FlashManagerInterface`:

```php
$this->flash->add('error', 'The payment failed.');
```

## Displaying messages

The view helper `flashes($type = null)` reads and removes the messages: with a type, it returns a list of messages; without, an array `[type => messages]`.

```twig
{% for message in flashes('success') %}
    <div class="alert alert-success">{{ message }}</div>
{% endfor %}

{% for type, messages in flashes() %}
    {% for message in messages %}
        <div class="alert alert-{{ type }}">{{ message }}</div>
    {% endfor %}
{% endfor %}
```

```php
<?php foreach ($this->flashes('success') as $message): ?>
    <div class="alert alert-success"><?= $this->e($message) ?></div>
<?php endforeach ?>
```

## FlashManagerInterface

Implemented by `FlashManager`.

| Method | Description |
|---|---|
| `add($type, $message)` | adds a message |
| `get($type)` | reads and removes the messages of a type |
| `all()` | reads and removes all the messages |
| `peek($type)`, `peekAll()` | reads without removing |
| `has($type)` | whether a type has messages |
| `clear()` | removes all the messages |

## Configuration

`config/framework/app.yaml`:

```yaml
flash:
  key: _flashes
```

`key` is the session key where the messages are stored.

## Profiler

When the WebProfiler is enabled, `FlashProvider` attaches a `NeoPHP\Component\Flash\Trace\FlashTrace` to the flash manager (`setTrace()` / `getTrace()` of `FlashManager`): the messages added with `add()` and read with `get()` / `all()` during the request are recorded. Without the profiler no trace is attached and nothing is recorded.

The bridge with the WebProfiler lives in the Flash component, `src/components/Flash/Helper/WebProfiler/FlashProfiler.php` (`@internal`, priority 285): it adds a "Flash messages" panel with the messages added, read and still pending in the session (session key from `FlashManager::getKey()`, `flash.key` in the configuration). It is loaded only when both Flash and WebProfiler are enabled, and uses only the public API of the WebProfiler (`Contract\AbstractProfiler`, `Contract\ProfilerInterface`, `Model\`, `Block\`).

## Changelog

- v2.0.0 — `FlashManager` is the `final` entry point of the module, declared with `#[Component]`; `FlashManagerInterface` replaces `Contract\FlashInterface`; `Contract\AbstractFlash` is merged into the manager; `FlashManager::getKey()`; the "Flash messages" panel of the profiler moves from the Session panel of the WebProfiler to `Helper/WebProfiler/FlashProfiler`; the internal classes are marked `@internal`.
- v1.38.0 — Opt-in `FlashTrace` (`AbstractFlash::setTrace()` / `getTrace()`), attached by `FlashProvider` when the WebProfiler is enabled.
- v1.6.0 — Flash component: `FlashInterface`, `addFlash()`, `flashes()` view helper.