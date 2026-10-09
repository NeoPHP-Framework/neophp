# Flash

The Flash component stores messages in the session until they are read, to display them after a redirect.
It requires the Session component.

## Summary

- [Adding messages](#adding-messages)
- [Displaying messages](#displaying-messages)
- [FlashInterface](#flashinterface)
- [Configuration](#configuration)
- [Profiler](#profiler)
- [Changelog](#changelog)

## Adding messages

In a controller (trait `FlashController`):

```php
$this->addFlash('success', 'Your profile has been saved.');
```

In a service, inject `NeoPHP\Component\Flash\Contract\FlashInterface`:

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

## FlashInterface

Implemented by `FlashManager` (extending `Contract\AbstractFlash`).

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

When the WebProfiler is enabled, `FlashProvider` attaches a `NeoPHP\Component\Flash\Trace\FlashTrace` to the flash manager (`setTrace()` / `getTrace()` of `AbstractFlash`): the messages added with `add()` and read with `get()` / `all()` during the request are listed in the "Flash messages" tab of the Session panel, next to the messages still pending in the session. Without the profiler no trace is attached and nothing is recorded.

## Changelog

- v1.38.0 — Opt-in `FlashTrace` (`AbstractFlash::setTrace()` / `getTrace()`), attached by `FlashProvider` when the WebProfiler is enabled.
- v1.6.0 — Flash component: `FlashInterface`, `addFlash()`, `flashes()` view helper.