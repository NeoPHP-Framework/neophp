# Session

The Session component wraps the native PHP session with a lazy `SessionInterface` service.
The session starts only when it is needed, is stored in `var/sessions/` and is saved at the end of the request.

## Summary

- [Configuration](#configuration)
- [Usage](#usage)
- [Lazy start](#lazy-start)
- [Controllers and views](#controllers-and-views)
- [API](#api)
- [Changelog](#changelog)

## Configuration

The session is configured in `config/framework/app.yaml`. The session cookie also uses the `cookie` section (see the Cookie documentation).

```yaml
session:
  name: NEOSESSID
  lifetime: 0
  gc_maxlifetime: 1440
  save_path: '%kernel.root_path%/var/sessions'

cookie:
  path: /
  domain: ~
  secure: auto
  httponly: true
  samesite: Lax
```

| Option | Default | Description |
|---|---|---|
| `session.name` | `NEOSESSID` | name of the session cookie |
| `session.lifetime` | `0` | lifetime of the session cookie in seconds (`0` = until the browser is closed) |
| `session.gc_maxlifetime` | `1440` | seconds of inactivity after which the session data can be deleted |
| `session.save_path` | `var/sessions` | directory of the session files, created when missing |
| `cookie.path`, `cookie.domain` | `/`, `~` | path and domain of the session cookie |
| `cookie.secure` | `auto` | `true`, `false` or `auto` (secure when the request is in HTTPS) |
| `cookie.httponly` | `true` | hides the session cookie from JavaScript |
| `cookie.samesite` | `Lax` | `Lax`, `Strict` or `None` |

The session always runs with `session.use_strict_mode`, `session.use_cookies` and `session.use_only_cookies` enabled.

## Usage

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

final class LoginController extends AbstractController
{
    public function login(Request $request): Response
    {
        $session = $this->getSession();
        $session->regenerate();
        $session->set('user_id', 42);

        return $this->redirectToRoute('home');
    }

    public function logout(): Response
    {
        $this->getSession()->invalidate();

        return $this->redirectToRoute('home');
    }
}
```

The service can also be injected anywhere:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Session\Contract\SessionInterface;

final class Cart
{
    public function __construct(private SessionInterface $session)
    {
    }

    public function add(int $productId): void
    {
        $items = (array) $this->session->get('cart', []);
        $items[] = $productId;
        $this->session->set('cart', $items);
    }
}
```

## Lazy start

- Writing (`set()`, `regenerate()`, `invalidate()`) starts the session.
- Reading (`get()`, `has()`, `all()`, `remove()`, `clear()`) starts it only when the browser already sent a session cookie; otherwise it returns the default value, so anonymous visitors get no session cookie.
- A listener on `ResponseEvent` (priority `-200`) saves the session at the end of the request, only when the service was used.

Starting the session after the headers were sent throws a `SessionException`.

## Controllers and views

| Helper | Where | Description |
|---|---|---|
| `getSession(): SessionInterface` | controllers | returns the session (see the Controller documentation) |
| `session(string $key, mixed $default = null)` | views | reads a session value |

```twig
{% if session('user_id') %}
    <a href="{{ path('logout') }}">Log out</a>
{% endif %}
```

```php
<?php if ($this->session('user_id')): ?>
    <a href="<?= $this->path('logout') ?>">Log out</a>
<?php endif ?>
```

## API

### SessionInterface

`NeoPHP\Component\Session\Contract\SessionInterface`, implemented by `NeoPHP\Component\Session\SessionManager` (extends `AbstractSession`).

| Method | Description |
|---|---|
| `start(): void` | starts the session (no-op when already started) |
| `isStarted(): bool` | whether the session is started |
| `getId(): string` | session id, `''` when not started |
| `getName(): string` | name of the session cookie |
| `get(string $key, mixed $default = null): mixed` | reads a value |
| `set(string $key, mixed $value): static` | writes a value |
| `has(string $key): bool` | whether a key exists |
| `remove(string $key): mixed` | removes a key and returns its value (`null` when missing) |
| `all(): array` | all the values |
| `clear(): static` | removes all the values |
| `regenerate(bool $destroy = true): static` | changes the session id, deletes the old data when `$destroy` is `true` |
| `invalidate(): static` | removes all the values and regenerates the id |
| `save(): void` | writes and closes the session |

### Other classes

| Class | Description |
|---|---|
| `SessionManager(array $options = [], bool $previous = false)` | options: `name`, `lifetime`, `gc_maxlifetime`, `save_path`, `cookie_path`, `cookie_domain`, `cookie_secure`, `cookie_httponly`, `cookie_samesite`; `$previous`: the request has a session cookie |
| `Provider\SessionProvider` | builds the manager from `framework.app.session` and `framework.app.cookie` |
| `Helper\Listener\SessionListener` | saves the session on `ResponseEvent` |
| `Exception\SessionException` | the session cannot be started or its directory cannot be created |

## Changelog

- v1.6.0 — Session configured in `app.yaml`, lazy start, `getSession()` in controllers, `session()` view helper.