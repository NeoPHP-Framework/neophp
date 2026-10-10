# Queue

The Queue package (`src/packages/Queue`) runs work asynchronously: the application dispatches messages (any serializable PHP object), a transport stores them, and a worker (`php bin/neo queue:work`) handles them in the background with retries, exponential backoff and a failed storage.
It ships the `sync`, `database`, `filesystem` and `redis` transports, handler discovery with `#[AsMessageHandler]`, self-handling jobs, console commands and a WebProfiler panel. No external dependency is required (`ext-redis` only for the redis transport).

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Messages and handlers](#messages-and-handlers)
- [Self-handling jobs](#self-handling-jobs)
- [Dispatching](#dispatching)
- [Routing](#routing)
- [Transports](#transports)
- [Configuration](#configuration)
- [Workers](#workers)
- [Retries and failed messages](#retries-and-failed-messages)
- [Security of the payloads](#security-of-the-payloads)
- [Events](#events)
- [Commands](#commands)
- [Profiler](#profiler)
- [Exceptions](#exceptions)
- [Limitations](#limitations)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Package\Queue\QueueManager` (`final`) |
| Interface | `NeoPHP\Package\Queue\QueueManagerInterface` |
| Attribute | `#[Package(provider: QueueProvider::class)]` |
| Requires | nothing |

Inject `QueueManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Package\Queue\QueueManagerInterface;

public function __construct(private QueueManagerInterface $queue)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Package\Queue\QueueManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Quick start

1. Generate a message and its handler:

```bash
php bin/neo make:message SendNewsletter
```

2. Dispatch it from a controller:

```php
use App\Message\SendNewsletter;

$this->dispatchMessage(new SendNewsletter(42));
```

3. Consume the queue:

```bash
php bin/neo queue:work
```

The default transport is `QUEUE_DSN` (`database://default` in the generated `.env`): the tables are created on first use (`auto_setup: true`) or with `php bin/neo queue:setup`.

## Messages and handlers

A message is a plain serializable object; its handler is a class of `src/` carrying `#[AsMessageHandler]` whose `__invoke()` receives the message (the message class is read from the type of the first argument):

```php
namespace App\Message;

use NeoPHP\Package\Queue\Attribute\AsMessage;

#[AsMessage(transport: 'async', queue: 'emails')]
class SendNewsletter
{
    public function __construct(public int $newsletterId)
    {
    }
}
```

```php
namespace App\MessageHandler;

use App\Message\SendNewsletter;
use App\Service\NewsletterSender;
use NeoPHP\Package\Queue\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendNewsletterHandler
{
    public function __construct(protected NewsletterSender $sender)
    {
    }

    public function __invoke(SendNewsletter $message): void
    {
        $this->sender->send($message->newsletterId);
    }
}
```

`#[AsMessageHandler]` options:

| Option | Default | Description |
|---|---|---|
| `handles` | type of the first argument | message class (or parent class / interface) |
| `method` | `__invoke` | method called on the handler (class attribute) |
| `priority` | `0` | order when several handlers handle the same message (highest first) |

The attribute can also be put on public methods (one handler class for several messages). Handlers are built by the container (constructor autowiring) and may handle a parent class or an interface of the message.
Handlers are discovered in `src/` and in the NeoPHP packages installed with Composer (see the Package documentation), and cached in `var/cache/queue/handlers.{env}.php` (refreshed automatically in debug). Explicit handlers can be added in `handlers:` of `config/packages/queue.yaml`.

## Self-handling jobs

A message implementing `NeoPHP\Package\Queue\Contract\JobInterface` without a handler is a job: its `handle()` method is called through the container, so its arguments are autowired:

```php
namespace App\Message;

use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Package\Queue\Contract\JobInterface;

class SendWelcomeEmail implements JobInterface
{
    public function __construct(public string $email)
    {
    }

    public function handle(MailerManagerInterface $mailer): void
    {
    }
}
```

`JobInterface` is a marker interface: `handle()` is not declared in it so that it can receive any service.

## Dispatching

Inject `MessageBusInterface` (or `QueueManagerInterface`, which extends it) or use the controller helper:

```php
use NeoPHP\Package\Queue\Contract\MessageBusInterface;

public function __construct(protected MessageBusInterface $bus)
{
}

$envelope = $this->bus->dispatch(new SendNewsletter(42), ['queue' => 'emails', 'delay' => 60]);
$this->dispatchMessage(new SendNewsletter(42), ['delay' => new DateTimeImmutable('tomorrow 08:00')]);
```

| Option | Description |
|---|---|
| `transport` | transport name (overrides the routing) |
| `queue` | queue name (default: `#[AsMessage(queue:)]`, else the `queue` option of the transport, else `default`) |
| `delay` | seconds, or a `DateTimeInterface` (available at) |
| `priority` | integer, highest first (default `0`) |
| `max_attempts` | attempts before the failed storage (default `retry.max_attempts`) |

`dispatch()` returns the `Envelope` (id, transport, queue, attempts, available at...). The container aliases are `QueueManagerInterface`, `MessageBusInterface`, `QueueManager`, `queue` and `message_bus`.

## Routing

The transport of a message is, in order: the `transport` option, `routing:` of the configuration (class, parent class or interface), `#[AsMessage(transport:)]`, then `default_transport`.
`#[AsMessage]` also accepts `queue`, `priority` and `maxAttempts`.

## Transports

Transports are configured by DSN (`name: dsn` or `name: { dsn: ..., options: { ... } }`); the query string of the DSN is merged into the options:

| DSN | Transport | Storage |
|---|---|---|
| `sync://` | `SyncTransport` | none: the message is handled during `dispatch()` (exceptions are thrown to the caller) |
| `database://default` | `DatabaseTransport` | tables `neo_queue_jobs` and `neo_queue_failed` of a connection of the Database component (SQLite, MySQL, PostgreSQL) |
| `filesystem://%kernel.root_path%/var/queue` | `FilesystemTransport` | one JSON file per message in `<dir>/<queue>/`, reserved in `<queue>/.reserved/`, failed in `<dir>/failed/` |
| `redis://[:password@]host:6379/0` | `RedisTransport` | sorted sets `neo_queue:<transport>:ready|delayed|reserved:<queue>`, hash `...:failed` (`ext-redis`) |

Options (all transports): `queue` (default queue), `retry_after` (seconds after which a reserved message whose worker died is delivered again, default `90`), `auto_setup`. Database: `table`, `failed_table`. Redis: `prefix` (`neo_queue:`), `timeout`.

The database transport stores integer timestamps (`available_at`, `reserved_at`, `created_at`, `failed_at`) and the message class (`message_class`). A message is claimed with `UPDATE ... SET reserved_at = ? WHERE id = ? AND reserved_at IS NULL`: only one worker gets it, on every driver, without table locks.
The filesystem transport claims a message with an atomic `rename()` and writes every file through a temporary file; it is meant for a single server.

### Custom transports

Implement `TransportInterface` (or extend `Contract\AbstractTransport`) and a `TransportFactoryInterface` whose `supports(string $dsn)` recognizes your scheme, then register the factory class:

```yaml
transports_factories: [ App\Queue\SqsTransportFactory ]
```

## Configuration

`config/packages/queue.yaml` (every key is optional):

| Key | Default | Description |
|---|---|---|
| `default_transport` | first transport | transport of the unrouted messages |
| `transports` | `async: QUEUE_DSN` | name => DSN or `{ dsn, options }`; `sync: 'sync://'` is always added |
| `routing` | `{ }` | message class => transport |
| `handlers` | `{ }` | message class => handler class, list of classes or `{ handler, method, priority }` |
| `retry.max_attempts` | `3` | attempts before the failed storage |
| `retry.delay` | `1` | first retry delay (seconds) |
| `retry.multiplier` | `2` | delay multiplier per attempt |
| `retry.max_delay` | `3600` | maximum delay (seconds) |
| `auto_setup` | `true` | creates the tables / directories on first use |
| `retry_after` | `90` | see transports |
| `transports_factories` | `[ ]` | custom transport factories |
| `profiler_stats` | `true` | counters of the transports in the profiler panel |

Without configuration file, the `async` transport uses `QUEUE_DSN` when defined, else `database://default` when a database connection is configured, else `filesystem://var/queue`.

```dotenv
###> queue ###
QUEUE_DSN="database://default"
###< queue ###
```

## Workers

```bash
php bin/neo queue:work [transport] [--queue=high,default] [--limit=100] [--time-limit=3600] [--memory-limit=128] [--sleep=1] [--stop-when-empty] [--once]
```

Queues are consumed in the given order (the first queue has priority). Each message prints a line: time, message class, id, queue, attempt, status (`handled`, `retry in Ns`, `failed`) and duration.
The worker stops gracefully (after the current message) on `SIGTERM`, `SIGINT` or `SIGQUIT` when `ext-pcntl` is available, when a limit is reached, or after `php bin/neo queue:restart` (it writes a timestamp in `var/queue/restart`, read by the workers after each message).

In production, run the workers with a process manager that restarts them (systemd, Supervisor) and call `queue:restart` after each deployment. On Windows (no pcntl), stop the worker with Ctrl+C between two messages or use `--time-limit` / `queue:restart`; the Task Scheduler can start `php bin/neo queue:work --stop-when-empty` every minute.

`Worker::run()` and `Worker::process()` can also be used in code (`$container->get(Worker::class)`).

## Retries and failed messages

When a handler throws, the message is released with an exponential backoff: `delay * multiplier ^ (attempt - 1)` seconds, capped by `max_delay` (1 s, 2 s, 4 s... with the defaults). After `max_attempts` attempts, the message moves to the failed storage of its transport (table `neo_queue_failed`, directory `failed/` or the redis `failed` hash) with the last error.
Exceptions implementing `UnrecoverableExceptionInterface` (for example `UnrecoverableMessageException`, a missing handler or an invalid signature) skip the retries.

```bash
php bin/neo queue:failed
php bin/neo queue:retry 12        # or: queue:retry all
php bin/neo queue:forget 12
php bin/neo queue:flush-failed
```

## Security of the payloads

Messages are serialized with `serialize()` and signed with HMAC-SHA256 using `APP_SECRET` (`framework.app.secret`). A payload whose signature is invalid (modified in the database, the files or redis, or signed with another secret) is never unserialized: it goes to the failed storage with `SerializationException`. Changing `APP_SECRET` makes the pending messages unreadable.

## Events

Dispatched when the Event component is available:

| Event | When |
|---|---|
| `MessageDispatchedEvent` | after `dispatch()` (`isHandledSync()`) |
| `WorkerMessageReceivedEvent` | a worker received a message |
| `WorkerMessageHandledEvent` | the message was handled (`getDuration()` in ms) |
| `WorkerMessageFailedEvent` | the handler failed (`getError()`, `willRetry()`, `getRetryDelay()`) |

## Commands

| Command | Arguments and options |
|---|---|
| `queue:work` | `transport`, `--queue`, `--limit`/`-l`, `--time-limit`/`-t`, `--memory-limit`/`-m` (128), `--sleep`/`-s` (1), `--stop-when-empty`, `--once` |
| `queue:status` | `transport`: ready, delayed, reserved and failed messages per queue |
| `queue:failed` | `--transport`, `--limit`/`-l` (50) |
| `queue:retry` | `id...` or `all`, `--transport` |
| `queue:forget` | `id`, `--transport` |
| `queue:flush-failed` | `--transport`, global `--force` skips the confirmation |
| `queue:purge` | `transport`, `--queue`, global `--force` skips the confirmation (failed messages are kept) |
| `queue:restart` | stops the running workers after their current message |
| `queue:setup` | `transport`: creates the tables / directories |
| `make:message` | `name`: `src/Message/<Name>.php` and `src/MessageHandler/<Name>Handler.php` |

## Profiler

When the WebProfiler is enabled, `Helper/WebProfiler/QueueProfiler` adds a "Queue" panel (no toolbar item): the messages dispatched during the request (class, transport, queue, delay, priority, handled sync or sent with its id, duration, error) and the counters of every transport (ready, delayed, reserved, failed; disable with `profiler_stats: false`).
The messages are recorded only when the profiler is enabled (`QueueTrace` is attached to the bus only in that case).

## Exceptions

All in `NeoPHP\Package\Queue\Exception\`, extending `QueueException` (itself a `FrameworkException`):

| Exception | Thrown when |
|---|---|
| `ConfigurationException` | unknown transport or scheme, invalid DSN, missing `APP_SECRET`, missing `ext-redis`, invalid handler |
| `TransportException` | storage error (tables, files, redis connection) |
| `SerializationException` | the message cannot be serialized, the payload is not signed or its signature is invalid (unrecoverable) |
| `NoHandlerException` | no handler for the message (unrecoverable) |
| `UnrecoverableMessageException` | thrown by a handler to skip the retries |

## Limitations

- At-least-once delivery: a worker killed during a handler, or a handler running longer than `retry_after`, leads to a second delivery. Handlers should be idempotent.
- The filesystem transport is for a single server (local disk); the redis transport ignores the priority between delayed messages until they are ready and requires Redis 5 (`ZPOPMIN`).
- The `sync` transport has no retry and no failed storage.
- With `auto_setup: true`, the profiler panel may create the queue tables on its first display.

## Changelog

- v2.1.0 — The handlers of the NeoPHP packages are discovered.
- v2.0.0 — `QueueManager` is the `final` entry point of the module, declared with `#[Package]`; `QueueManagerInterface` replaces `Contract\QueueInterface`; `Helper/Profiler` is renamed `Helper/WebProfiler`; `Envelope` moves to `Message\Envelope`; the internal classes are marked `@internal`.
- v1.37.0 — Queue package: messages and `#[AsMessage]` routing, `#[AsMessageHandler]` handlers (discovered and cached), self-handling `JobInterface` jobs, `MessageBusInterface` / `QueueInterface`, `sync`, `database`, `filesystem` and `redis` transports configured by DSN with custom factories, HMAC-signed payloads, delays, priorities, retries with exponential backoff, failed storage, workers with limits, signals and `queue:restart`, events, `dispatchMessage()` in controllers, `queue:*` and `make:message` commands, Queue panel of the WebProfiler.