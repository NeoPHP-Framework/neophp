# Mercure

The Mercure package (`src/packages/Mercure`) pushes real-time updates to the browsers with a [Mercure](https://mercure.rocks) hub: new messages of a chat, notifications, a live feed, a progress bar.
It publishes the updates to the hub, signs the JSON Web Tokens (JWT) of the publishers and the subscribers, and sets the cookie that gives access to the private updates, without any dependency.

The browsers subscribe with the native `EventSource` API (server-sent events). The hub keeps the connections open, so PHP never has to: the application only sends a POST request per update. FrankenPHP embeds a hub; it is also available as a standalone binary and a Docker image.

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Hub](#hub)
- [Configuration](#configuration)
- [Updates](#updates)
- [Publishing](#publishing)
- [Subscribing](#subscribing)
- [Private updates](#private-updates)
- [Discovery](#discovery)
- [Commands](#commands)
- [WebProfiler panel](#webprofiler-panel)
- [MercureManagerInterface](#mercuremanagerinterface)
- [Errors](#errors)
- [Security](#security)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Package\Mercure\MercureManager` (`final`) |
| Interface | `NeoPHP\Package\Mercure\MercureManagerInterface` |
| Attribute | `#[Package(provider: MercureProvider::class, requires: [HttpClientManager::class])]` |
| Requires | HttpClient component; Cookie component for the private updates, Queue package for `dispatch()` |

Inject `MercureManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Package\Mercure\MercureManagerInterface;

public function __construct(private MercureManagerInterface $mercure)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Package\Mercure\MercureManager::class => false,
```

The public API of the module is its manager and its interface, `Model\Update`, the exception and the classes documented below. The classes marked `@internal` are used by the framework only.

## Quick start

Publish an update when a message is posted:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\Package\Mercure\Model\Update;

class ChatController extends AbstractController
{
    #[Route('/chat', name: 'chat')]
    public function index(): Response
    {
        return $this->render('chat/index.html.twig');
    }

    #[Route('/chat/messages', name: 'chat_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $message = new Message((string) $request->request->get('text'));
        // ... save the message

        $this->publishUpdate(Update::json('chat', ['text' => $message->getText()]));

        return $this->json(['ok' => true]);
    }
}
```

Subscribe in the template:

```twig
<ul id="messages"></ul>

<script>
    const source = new EventSource("{{ mercure('chat')|escape('js') }}");

    source.onmessage = (event) => {
        const item = document.createElement('li');
        item.textContent = JSON.parse(event.data).text;
        document.getElementById('messages').append(item);
    };
</script>
```

Every open page receives the message as soon as it is published.

## Hub

The application and the hub share a secret key: the application signs the JWT with it, the hub checks them.

**FrankenPHP** (the hub is a directive of its Caddyfile):

```caddyfile
{
    frankenphp
}

localhost {
    root * public/

    mercure {
        publisher_jwt {env.MERCURE_JWT_SECRET}
        subscriber_jwt {env.MERCURE_JWT_SECRET}
        anonymous
    }

    php_server
}
```

`anonymous` lets the visitors without JWT receive the public updates. See the Mercure page of the FrankenPHP documentation for the other options of the directive (history, CORS, algorithms). The hub answers on `https://localhost/.well-known/mercure`, the default `MERCURE_URL` of the generated `.env`.

**Standalone hub** (another domain or port, with PHP-FPM, Apache or `php bin/neo serve`): run the [Mercure binary or Docker image](https://mercure.rocks/docs/hub/install) with `MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY` set to the value of `MERCURE_JWT_SECRET`, then set `MERCURE_URL` (and `MERCURE_PUBLIC_URL` when the browsers reach it through another address).

Use a key of at least 32 characters (256 bits) with `HS256`; `neo install` generates a random one (64 hexadecimal characters).

## Configuration

`neo install` generates `config/packages/mercure.yaml` and adds `MERCURE_URL`, `MERCURE_PUBLIC_URL` and `MERCURE_JWT_SECRET` to `.env`:

```yaml
url: '%env(MERCURE_URL)%'
public_url: '%env(MERCURE_PUBLIC_URL)%'

jwt:
    secret: '%env(MERCURE_JWT_SECRET)%'
    algorithm: HS256
    publish: [ '*' ]
    token: ~

cookie:
    lifetime: 3600
    domain: ~
    secure: auto
    samesite: Strict

http_options: { }
```

| Key | Default | Description |
|---|---|---|
| `url` | `~` | URL used by the application to publish (`MERCURE_URL`); it can be an internal address, `http://php/.well-known/mercure` in Docker for example |
| `public_url` | `~` | URL reached by the browsers (`MERCURE_PUBLIC_URL`); `url` when empty |
| `jwt.secret` | `~` | key signing the JWT (`MERCURE_JWT_SECRET`), shared with the hub |
| `jwt.algorithm` | `HS256` | `HS256`, `HS384` or `HS512` |
| `jwt.publish` | `['*']` | topics the application may publish to (`*` = every topic) |
| `jwt.token` | `~` | static publisher JWT, used when `jwt.secret` is empty (a hub of another team, a managed hub) |
| `cookie.lifetime` | `3600` | lifetime of the `mercureAuthorization` cookie and of the subscriber JWT, in seconds |
| `cookie.domain` | `~` | domain of the cookie: `.example.com` when the hub is on a sub-domain (`hub.example.com`) |
| `cookie.secure` | `auto` | `true`, `false` or `auto` (secure when the request is HTTPS) |
| `cookie.samesite` | `Strict` | `Strict`, `Lax` or `None` |
| `http_options` | `{ }` | options of the HttpClient component for the publications (`timeout`, `verify_peer`, `proxy`...) |

With the self-signed certificate of a local hub, either trust its certificate authority on the machine that publishes, or disable the verification on your machine only: `http_options: { verify_peer: '%env(bool:MERCURE_VERIFY_PEER)%' }` in `config/packages/mercure.yaml`, `MERCURE_VERIFY_PEER=1` in `.env` and `MERCURE_VERIFY_PEER=0` in `.env.local`. Never disable it in production.

## Updates

`NeoPHP\Package\Mercure\Model\Update` is the update sent to the subscribers of its topics:

```php
use NeoPHP\Package\Mercure\Model\Update;

new Update('https://example.com/books/1', '{"title":"Neo"}');
new Update(['chat/42', 'chat'], 'Hello', private: true, type: 'message');
Update::json('notifications/7', ['text' => 'Your order is shipped', 'count' => 3], private: true);
```

| Argument | Description |
|---|---|
| `topics` | a topic or a list of topics: any string, an IRI by convention (`https://example.com/books/1`) |
| `data` | the data received in `event.data` (a string; `Update::json()` encodes any value) |
| `private` | sent only to the subscribers authorized for one of the topics (see [Private updates](#private-updates)) |
| `id` | id of the event, generated by the hub when `null` (used by the reconnections: `Last-Event-ID`) |
| `type` | type of the event: received with `source.addEventListener(type, ...)` instead of `onmessage` |
| `retry` | reconnection time asked to the browsers, in milliseconds |

An update without topic, with an empty topic or a negative retry throws `MercureException`. Updates are serializable: they can be sent through the Queue package.

## Publishing

`publish(Update $update): string` sends the update immediately and returns the id given by the hub:

```php
$id = $mercure->publish(Update::json('chat/42', ['text' => 'Hello']));
```

In a controller, `publishUpdate(Update $update)` does the same (`MercureController` trait of `AbstractController`).

The publication is a POST request to `url`, authorized with a publisher JWT signed with `jwt.secret` (claim `mercure.publish` = `jwt.publish`), or with `jwt.token`.

`dispatch(Update $update, array $options = [])` publishes the update in the background: it is dispatched as a job of the Queue package and published by `php bin/neo queue:work`. The request of the visitor does not wait for the hub, and a hub that is unavailable for a while is retried with the retries of the queue. The options are the dispatch options of the Queue package:

```php
$mercure->dispatch(Update::json('chat/42', ['text' => 'Hello']));
$mercure->dispatch($update, ['transport' => 'async', 'queue' => 'mercure', 'delay' => 5]);
```

`dispatch()` throws `MercureException` when the Queue package is disabled.

## Subscribing

The `mercure()` view function returns the public URL of the hub with a `topic` parameter per topic:

```twig
<script>
    const source = new EventSource("{{ mercure(['https://example.com/books/{id}', 'notifications'])|escape('js') }}");
    source.addEventListener('book', (event) => console.log(JSON.parse(event.data)));
</script>
```

```php
<script>
    const source = new EventSource(<?= json_encode($this->mercure('chat/42')) ?>);
</script>
```

Inside a `<script>`, escape the URL for JavaScript (`|escape('js')` with Twig, `json_encode()` in a PHP template): the HTML escaping would turn the `&` between the topics into `&amp;`.

The topics accept [URI templates](https://www.rfc-editor.org/rfc/rfc6570): `https://example.com/books/{id}` receives the updates of every book. `getSubscribeUrl($topics)` returns the same URL in PHP.

`EventSource` reconnects by itself when the connection is lost and sends the id of the last event received: the hub sends the updates missed in the meantime (when its history is enabled).

## Private updates

A private update (`private: true`) is only sent to the subscribers holding a JWT whose `mercure.subscribe` claim matches one of its topics. The JWT is stored in the `mercureAuthorization` cookie, sent by the browser to the hub:

```twig
{# authorizes the visitor for the topics of the URL, then returns the URL #}
const source = new EventSource("{{ mercure('users/' ~ app.user.id ~ '/notifications', { subscribe: true })|escape('js') }}");

{# authorizes other topics than the ones of the URL #}
{{ mercure('chat/42', { subscribe: ['chat/42', 'chat/42/typing'], payload: { name: app.user.name }, lifetime: 600 })|escape('js') }}
```

In PHP, `authorize(array $topics, array $payload = [], ?int $lifetime = null)` queues the cookie on the response (`authorizeMercure()` in a controller); `clearAuthorization()` removes it, after a logout for example:

```php
$this->authorizeMercure(['users/' . $this->getUser()->getId() . '/notifications']);
```

| Option of `mercure()` | Description |
|---|---|
| `subscribe` | `true` (the topics of the URL) or a list of topics: sets the cookie |
| `payload` | data about the subscriber shared with the subscriptions API of the hub (the `mercure.payload` claim) |
| `lifetime` | lifetime of the cookie and of the JWT, in seconds (`cookie.lifetime` by default) |

The cookie is `HttpOnly`, its path is the path of the public URL (`/.well-known/mercure`) and it is set with the options of `cookie:`. A browser only sends it to a hub of the same site: the same domain (FrankenPHP), or a sub-domain with `cookie.domain: .example.com`. The cookie needs the Cookie component; without it, `authorize()` throws `MercureException`.

Never authorize a visitor for a topic they may not read: the topics are checked by the hub only.

`createSubscriberToken(array $topics, array $payload = [], ?int $lifetime = null)` returns a subscriber JWT without cookie, for a mobile application or a hub on another site (sent in the `Authorization` header with an `EventSource` polyfill).

## Discovery

`addDiscoveryLink(Response $response)` adds `Link: <public URL>; rel="mercure"` to a response, so that a client can find the hub of a resource (API clients, the Mercure specification):

```php
return $mercure->addDiscoveryLink($this->json($book));
```

## Commands

```bash
php bin/neo mercure:publish chat/42 '{"text":"Hello"}'
php bin/neo mercure:publish chat/42 "Hello" --private --type=message --id=msg-1 --retry=3000
php bin/neo mercure:token
php bin/neo mercure:token --subscribe="*" --lifetime=3600
php bin/neo mercure:token --publish=chat/42
```

| Command | Description |
|---|---|
| `mercure:publish <topic> [data]` | publishes an update (`--private`, `--id`, `--type`, `--retry`); handy to check the configuration of the hub with a page subscribed to the topic |
| `mercure:token` | prints a publisher JWT (`--publish` topics, `jwt.publish` by default) or a subscriber JWT (`--subscribe` topics); `--lifetime` in seconds. To test the hub with `curl` or its debug UI |

## WebProfiler panel

When the WebProfiler package is enabled (debug), the **Mercure** panel of the profiler lists, for the request:

| Tab | Content |
|---|---|
| Updates | every `publish()` and `dispatch()`: topics, private or not, type, id returned by the hub, data (first 500 characters), size, time spent waiting for the hub, error |
| Authorizations | every `authorize()` (topics, payload, lifetime) and `clearAuthorization()` |
| Configuration | hub and public URLs, how the publisher JWT is obtained (secret or static token, never their value), algorithm, publish topics, cookie options |

The metrics show the published, dispatched and private updates, the authorizations and the total time spent waiting for the hub. The badge of the panel counts the updates and turns red when one failed. Up to 200 entries are recorded per request.

Nothing is recorded when the profiler is disabled (production).

## MercureManagerInterface

| Method | Description |
|---|---|
| `publish(Update $update): string` | publishes now, returns the id of the update |
| `dispatch(Update $update, array $options = []): void` | publishes from a worker of the Queue package |
| `getUrl(): string` | URL used to publish (`url`) |
| `getPublicUrl(): string` | URL of the browsers (`public_url`, else `url`) |
| `getSubscribeUrl(string\|array $topics = []): string` | public URL with the `topic` parameters |
| `createPublisherToken(?array $topics = null, ?int $lifetime = null): string` | publisher JWT (`jwt.publish` topics and no expiration by default) |
| `createSubscriberToken(array $topics, array $payload = [], ?int $lifetime = null): string` | subscriber JWT (`cookie.lifetime` by default) |
| `authorize(array $topics, array $payload = [], ?int $lifetime = null): string` | sets the `mercureAuthorization` cookie, returns its JWT |
| `clearAuthorization(): void` | removes the cookie |
| `addDiscoveryLink(Response $response): Response` | adds the `Link` header of the hub |

The constant `MercureManagerInterface::COOKIE_NAME` is `mercureAuthorization`. The manager is also available with the `mercure` container id.

## Errors

Every error throws `NeoPHP\Package\Mercure\Exception\MercureException` (extends `FrameworkException`):

- the URL of the hub is not configured (`MERCURE_URL`);
- the secret is empty and no static token is configured, or the algorithm is not `HS256`, `HS384` or `HS512`;
- the hub cannot be reached (the HttpClient exception is the previous exception);
- the hub refuses the update: the message contains the HTTP status and the response of the hub (`401`: the hub does not use the same key, or the topics are not allowed by `jwt.publish`);
- an invalid update, `dispatch()` without the Queue package, `authorize()` / `clearAuthorization()` without the Cookie component.

## Security

- Keep `MERCURE_JWT_SECRET` out of the repository in production (`.env.local` or a real environment variable): anybody holding it can publish and read every private update.
- The publisher JWT never leaves the server; the subscriber JWT is in an `HttpOnly` cookie (`SameSite=Strict` by default), unreadable by JavaScript.
- Every visitor without cookie receives the public updates when the hub allows anonymous subscribers: never put private data in a public update.
- The subscriber JWT expires after `cookie.lifetime`: authorize the visitor again on each page (the `mercure()` function with `subscribe` does it).

## Changelog

- v2.8.0 — `Helper/Doctor/MercureCheck`: `neo doctor` checks `MERCURE_URL`, `MERCURE_JWT_SECRET` and that the hub is reachable (see the Doctor documentation).
- v2.4.0 — Mercure panel of the WebProfiler: updates published and dispatched (topics, data, hub time, errors), authorizations and configuration.
- v2.3.0 — Mercure package: `MercureManager` and `MercureManagerInterface`, `Model\Update` (`Update::json()`), publication to the hub with a publisher JWT signed without dependency (`HS256`, `HS384`, `HS512`) or a static token, `dispatch()` through the Queue package, `mercure()` view function, private updates with the `mercureAuthorization` cookie (`authorize()`, `clearAuthorization()`, `createSubscriberToken()`), `addDiscoveryLink()`, `publishUpdate()` / `authorizeMercure()` controller helpers, `mercure:publish` and `mercure:token` commands, `config/packages/mercure.yaml` and `MERCURE_*` variables generated by `neo install`.