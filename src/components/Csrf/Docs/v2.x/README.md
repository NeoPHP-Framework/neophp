# Csrf

The Csrf component protects requests against cross-site request forgery with tokens stored in the session.
Tokens are masked differently every time they are rendered, and can be checked in forms, controllers or with the `#[Csrf]` attribute.

## Summary

- [Module](#module)
- [Tokens](#tokens)
- [In templates](#in-templates)
- [In controllers](#in-controllers)
- [Csrf attribute](#csrf-attribute)
- [Forms](#forms)
- [Configuration](#configuration)
- [PHP API](#php-api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Csrf\CsrfManager` (`final`) |
| Interface | `NeoPHP\Component\Csrf\CsrfManagerInterface` |
| Attribute | `#[Component(provider: CsrfProvider::class, requires: [SessionManager::class])]` |
| Requires | Session |

Inject `CsrfManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Csrf\CsrfManagerInterface;

public function __construct(private CsrfManagerInterface $csrf)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\Csrf\CsrfManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` (provider, discoveries, traces, `Helper/View`, `Helper/Console`, `Helper/Event`, `Helper/WebProfiler`) are used by the framework only.

## Tokens

A token is identified by a string id (`contact`, `delete-post-42`). It is created on first use and stored in the session; the same id gives a token valid until it is refreshed or removed.

## In templates

| Helper | Returns |
|---|---|
| `csrf_token($id)` | a masked token |
| `csrf_field($id, $field = null)` | a hidden input (`_token` by default), safe HTML |

```twig
<form method="post" action="{{ path('post_delete', {id: post.id}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('delete-post-' ~ post.id) }}">
    <button>Delete</button>
</form>

<form method="post">
    {{ csrf_field('contact') }}
</form>
```

```php
<?= $this->csrf_field('contact') ?>
<input type="hidden" name="_token" value="<?= $this->e($this->csrf_token('delete-post-' . $post->getId())) ?>">
```

## In controllers

```php
public function delete(int $id, Request $request): Response
{
    if (!$this->isCsrfTokenValid('delete-post-' . $id, $request->request->get('_token'))) {
        throw $this->createAccessDeniedException();
    }

    return $this->redirectToRoute('post_index');
}
```

`getCsrfToken($id)` returns a token. Outside controllers, inject `NeoPHP\Component\Csrf\CsrfManagerInterface`.

## Csrf attribute

`#[Csrf]` (`NeoPHP\Component\Csrf\Attribute\Csrf`) on a controller class or a method checks the token of the `POST`, `PUT`, `PATCH` and `DELETE` requests before the controller (it is a route middleware, handled by `CsrfMiddleware`). A missing or invalid token throws an `InvalidCsrfTokenException` (HTTP 403). `{id}` placeholders are replaced by the route parameters. The token is read from the header `X-CSRF-TOKEN` (AJAX requests), then from the field `_token`.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Post;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Csrf\Attribute\Csrf;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

class PostController extends AbstractController
{
    #[Route('/posts/{id}/delete', name: 'post_delete', methods: ['POST'])]
    #[Csrf('delete-post-{id}')]
    public function delete(int $id): Response
    {
        $post = $this->getRepository(Post::class)->find($id) ?? throw $this->createNotFoundException();

        $this->getOrm()->remove($post);
        $this->getOrm()->flush();

        return $this->redirectToRoute('post_index');
    }
}
```

A route parameter is not converted into an entity: type the parameter `int $id` and load the entity with `find()`.

| Option | Default |
|---|---|
| `id` | required; `{parameter}` placeholders are replaced by the route parameters |
| `field` | `_token` (`field_name`) |
| `header` | `X-CSRF-TOKEN` (`header_name`) |
| `methods` | `['POST', 'PUT', 'PATCH', 'DELETE']` |

```javascript
fetch('/posts/42/delete', { method: 'POST', headers: { 'X-CSRF-TOKEN': token } });
```

## Forms

A hidden field `_token` is added to every form and checked by `isValid()` (error `The CSRF token is invalid. Please try to resubmit the form.`). Disable it with the form option `'csrf_protection' => false` (API forms). The token id is the name of the form, or the `csrf_token_id` option (see the Form documentation).

## Configuration

`config/framework/csrf.yaml` (optional, key `framework.csrf`):

```yaml
field_name: _token
header_name: X-CSRF-TOKEN
session_key: _csrf
max_tokens: 200
```

| Option | Default | Description |
|---|---|---|
| `field_name` | `_token` | name of the form field |
| `header_name` | `X-CSRF-TOKEN` | name of the HTTP header |
| `session_key` | `_csrf` | session key holding the tokens |
| `max_tokens` | `200` | tokens kept in the session: the least recently used ones are removed first (a page with one form per entity, `delete-post-1`, `delete-post-2`..., no longer grows the session without limit) |

## PHP API

`NeoPHP\Component\Csrf\CsrfManagerInterface` (implemented by `CsrfManager`):

| Method | Description |
|---|---|
| `getToken(string $id): string` | masked token (created when missing) |
| `refreshToken(string $id): string` | replaces the token and returns it |
| `removeToken(string $id): void` | removes the token |
| `hasToken(string $id): bool` | whether a token exists for the id |
| `isTokenValid(string $id, ?string $token): bool` | checks a submitted token |
| `getFieldName(): string` | configured field name |
| `getHeaderName(): string` | configured header name |

`new CsrfManager(SessionManagerInterface $session, array $options = [])` builds a standalone instance.

## Exceptions

| Exception | Description |
|---|---|
| `NeoPHP\Component\Csrf\Exception\CsrfException` | base exception of the component |
| `NeoPHP\Component\Csrf\Exception\InvalidCsrfTokenException` | missing or invalid token with `#[Csrf]`, HTTP 403 |

## Changelog

- v2.0.0 — `CsrfManager` is the `final` entry point of the module, declared with `#[Component]`; `CsrfManagerInterface` replaces `Contract\CsrfInterface`; `Contract\AbstractCsrf` is merged into the manager; the internal classes are marked `@internal`.
- v1.30.0 — `max_tokens` (200 by default): the least recently used tokens are removed from the session.
- v1.12.0 — Csrf component: tokens in the session, automatic token in forms, `csrf_token()` / `csrf_field()` helpers, `getCsrfToken()` / `isCsrfTokenValid()` in controllers, `#[Csrf]` attribute.