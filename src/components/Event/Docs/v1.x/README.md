# Event

The Event component dispatches event objects to listeners by order of priority, with an API mirroring PSR-14 without dependency.
Listeners are declared with `#[AsListener]`, subscribers or YAML, and are built lazily by the container.

## Summary

- [Events](#events)
- [Dispatching](#dispatching)
- [Listeners](#listeners)
- [Configuration](#configuration)
- [Commands](#commands)
- [Kernel events](#kernel-events)
- [Changelog](#changelog)

## Events

```php
<?php

declare(strict_types=1);

namespace App\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;

class UserRegisteredEvent extends AbstractEvent
{
    public function __construct(public string $email)
    {
    }
}
```

## Dispatching

```php
$this->dispatch(new UserRegisteredEvent($email));
```

Outside a controller, inject `NeoPHP\Component\Event\Contract\EventDispatcherInterface` and call `dispatch($event)`: it returns the event, so a listener can fill it with data.

An event extending `AbstractEvent` (or implementing `StoppableEventInterface`) can be stopped: `$event->stopPropagation()` prevents the next listeners from being called.

`EventDispatcherInterface` (implemented by `EventManager`):

| Method | Description |
|---|---|
| `dispatch($event)` | calls the listeners and returns the event |
| `addListener($event, $listener, $priority = 0)` | adds a callable, or `[class, method]` built by the container on dispatch |
| `addSubscriber($subscriber)` | adds an `EventSubscriberInterface` (object or class) |
| `getListeners($event = null)` | listeners of an event, sorted, or of every event |
| `hasListeners($event)` | whether an event has listeners |

```php
$dispatcher->addListener(UserRegisteredEvent::class, fn (UserRegisteredEvent $event) => $logger->info($event->email), 10);
```

## Listeners

`#[AsListener]` on a class (method `__invoke()`) or on public methods. The event is the type of the first parameter:

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\UserRegisteredEvent;
use NeoPHP\Component\Event\Attribute\AsListener;

#[AsListener]
class SendWelcomeMail
{
    public function __construct(protected MailerInterface $mailer)
    {
    }

    public function __invoke(UserRegisteredEvent $event): void
    {
        $this->mailer->send($event->email);
    }
}

class AuditListener
{
    #[AsListener(priority: 100)]
    public function onRegistered(UserRegisteredEvent $event): void
    {
    }
}
```

| Argument | Description |
|---|---|
| `event` | event class (default: type of the first parameter) |
| `method` | on a class: method to call (default: `__invoke`) |
| `priority` | highest first (default `0`); listeners with the same priority are called in their declaration order |

A listener can also listen to a parent class or an interface: it is then called for every event that extends it.

A subscriber lists several events:

```php
use NeoPHP\Component\Event\Contract\EventSubscriberInterface;

class UserSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            UserRegisteredEvent::class => 'onRegistered',
            UserDeletedEvent::class => ['onDeleted', 10],
            PasswordChangedEvent::class => [['notify', 10], ['log']],
        ];
    }
}
```

Listeners and subscribers are discovered in `src/` and in the framework (`Feature/Helper/Listener/`), built by the container (their dependencies are autowired) and instantiated only when their event is dispatched. The discovery is cached in `var/cache/event/`.

## Configuration

Listeners and subscribers can also be declared in `config/framework/event.yaml`:

```yaml
listeners:
  App\Event\UserRegisteredEvent:
    - App\Listener\SendWelcomeMail
    - { listener: App\Listener\Audit, method: onRegistered, priority: 10 }

subscribers:
  - App\Subscriber\UserSubscriber
```

## Commands

```bash
php bin/neo event:list
php bin/neo event:list User
```

`event:list [filter]` lists the events and their listeners in the order they are called; `filter` keeps the events whose name contains the text.

An invalid listener (unknown event, missing method...) throws an `EventException`.

## Kernel events

| Event | When | Usage |
|---|---|---|
| `RequestEvent` | before the global middlewares | `setResponse()` answers without routing (maintenance...) |
| `ControllerEvent` | after the route middlewares, before the controller | `setController()`, `setParameters()` |
| `ResponseEvent` | for every response, errors included | modifies or replaces the response |
| `ExceptionEvent` | when an exception is thrown | `setResponse()` replaces the error page, `setThrowable()` |
| `TerminateEvent` | after the response is sent | slow work (mails, logs) |

They are in `NeoPHP\Component\Kernel\Event\` and give access to `getKernel()` and `getRequest()`. The framework uses them too: the queued cookies are added and the session is saved by listeners of `ResponseEvent` (`Cookie/Helper/Listener/`, `Session/Helper/Listener/`).

## Changelog

- v1.15.0 — `event:list` rewritten for the new console.
- v1.9.0 — Event component: dispatcher, `#[AsListener]`, subscribers, `event.yaml`, stoppable events, kernel events, `dispatch()` in controllers, `event:list` command.