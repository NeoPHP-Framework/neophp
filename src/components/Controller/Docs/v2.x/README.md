# Controller

The Controller component resolves the controller of a route, builds its arguments and turns its return value into a response.
`AbstractController` gathers the shortcuts of every feature through traits.

## Summary

- [Module](#module)
- [Writing a controller](#writing-a-controller)
- [Generating a controller](#generating-a-controller)
- [Controller formats](#controller-formats)
- [Arguments](#arguments)
- [Return values](#return-values)
- [AbstractController](#abstractcontroller)
- [Traits](#traits)
- [Resolver API](#resolver-api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Controller\ControllerManager` (`final`) |
| Interface | `NeoPHP\Component\Controller\ControllerManagerInterface` |
| Attribute | `#[Component(provider: ControllerProvider::class)]` |
| Requires | nothing |
| Required by | Kernel |

Inject `ControllerManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Controller\ControllerManagerInterface;

public function __construct(private ControllerManagerInterface $controller)
{
}
```

The kernel requires this module: it is always loaded and cannot be disabled in `config/config.php`.

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Writing a controller

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    #[Route('/users/{id}', name: 'user_show')]
    public function show(int $id, Request $request): Response
    {
        if ($id > 100) {
            throw $this->createNotFoundException('User {id} not found.', ['id' => $id]);
        }

        return $this->render('user/show', ['id' => $id, 'tab' => $request->query->get('tab')]);
    }
}
```

Routes are declared with `#[Route]` or in `routes.yaml` (see the Routing documentation). The controller is built by the container, so its constructor is autowired (see the Container documentation).

## Generating a controller

```bash
php bin/neo make:controller Post                    # src/Controller/PostController.php + templates/post/index.php
php bin/neo make:controller Admin/BlogPost --twig   # App\Controller\Admin\BlogPostController + templates/admin/blog_post/index.html.twig
php bin/neo make:controller Api/Product --api       # JSON response, no template
php bin/neo make:controller Health --no-template    # plain Response
php bin/neo make:controller                         # asks the name and the response type
```

| Option | Description |
|---|---|
| `--twig` | Twig template (`.html.twig`, extends `base.html.twig` when it exists) |
| `--api` | `$this->json([...])`, no template |
| `--no-template` | `new Response(...)`, no template |
| `--force` | overwrites the existing controller and template |

The controller gets an `index()` action on `/<name>` (kebab-case, e.g. `/admin/blog-post`) named `<name>_index` (`admin_blog_post_index`). Without option, the format is a PHP template (extending `base` when `templates/base.php` exists), or Twig when the project only has `templates/base.html.twig`. Inject the services in the constructor or the action (`public function index(EntityManagerInterface $entityManager): Response`).

## Controller formats

| Format | Example |
|---|---|
| `Class::method` | `App\Controller\UserController::show` |
| `[Class, method]` | `['App\Controller\UserController', 'show']` |
| invokable class | `App\Controller\HomeController` (with `__invoke()`) |
| callable | a closure |

## Arguments

Controller arguments are resolved from, in order:

1. the `Request` (type-hint);
2. the argument resolvers (`ArgumentResolverInterface`), for example `#[MapRequestPayload]` and `#[MapQueryString]` of the Serializer;
3. the route parameters (cast to `int`, `float`, `bool` or `string`);
4. the request attributes;
5. the container (class type-hints);
6. the default values.

- With the ORM, an entity argument is loaded from the route parameters: `public function show(Post $post)` on `/post/{id}` receives the `Post` or throws a 404 (see "Entities in controllers" in the ORM documentation, `#[MapEntity]`).
- `#[MapRequestPayload] PostInput $input` maps the request body (JSON, XML, form...) to a DTO and validates it, `#[MapQueryString] SearchQuery $query` maps the query string (see the Serializer documentation).
- `#[Autowire]` and `#[Inject]` are not read on the parameters of an action. Put them on the constructor (or on a property) of the controller, or use `$this->get('service.id')` in the action.

## Return values

| Returned | Response |
|---|---|
| `Response` | returned as is |
| `string` or `Stringable` | HTML response (200) |
| `array` or `JsonSerializable` | JSON response |
| `null` | empty response (204) |

Any other value throws a `ControllerException`.

## AbstractController

`NeoPHP\Component\Controller\Contract\AbstractController` shortcuts:

| Method | Returns |
|---|---|
| `render($template, $parameters, $status, $headers)` | `Response` |
| `renderView($template, $parameters)` | `string` |
| `json($data, $status, $headers, $context)` | `JsonResponse` (objects normalized with the Serializer) |
| `serialize($data, $format, $context)` / `deserialize($data, $type, $format, $context)` | `string` / `mixed` |
| `redirect($url, $status)` | `RedirectResponse` |
| `redirectToRoute($route, $parameters, $status)` | `RedirectResponse` |
| `generateUrl($route, $parameters, $absolute)` | `string` (`$absolute = true`: `https://host/path`) |
| `createNotFoundException($message, $context)` | `NotFoundHttpException` (404) |
| `createAccessDeniedException($message, $context)` | `AccessDeniedHttpException` (403) |
| `get($id)` / `has($id)` | container access |
| `getSession()` | `SessionManagerInterface` |
| `getCookies()` | `CookieManagerInterface` |
| `addFlash($type, $message)` | adds a flash message |
| `dispatch($event)` | dispatches an event |
| `validate($value, $constraints, $groups)` | `ViolationList` |
| `getCsrfToken($id)` / `isCsrfTokenValid($id, $token)` | CSRF token / check |
| `createForm($type, $data, $options)` / `createFormBuilder($data, $options)` | `FormInterface` / `FormBuilder` |
| `getConnection($name)` | `ConnectionInterface` |
| `getOrm()` / `getRepository($entityClass)` | `OrmManagerInterface` / `RepositoryInterface` |
| `sendEmail($email)` | `?SentMessage` |
| `getUser()`, `isGranted()`, `denyAccessUnlessGranted()`, `loginUser()`, `logoutUser()`, `getLastUsername()`, `getLastAuthenticationError()` | see the Security documentation |

## Traits

`AbstractController` has no method of its own: it is made of traits, and each feature ships its trait in `Feature/Helper/Controller/`:

| Trait | Methods |
|---|---|
| `Api/Helper/Controller/ApiController` | `paginate()`, `jsonPage()`, `rateLimit()`, `createRateLimiter()`, `problemJson()` |
| `Cache/Helper/Controller/CacheController` | `cache()` |
| `Container/Helper/Controller/ContainerController` | `setContainer()`, `get()`, `has()` |
| `Cookie/Helper/Controller/CookieController` | `getCookies()` |
| `Csrf/Helper/Controller/CsrfController` | `getCsrfToken()`, `isCsrfTokenValid()` |
| `Database/Helper/Controller/DatabaseController` | `getConnection()` |
| `Event/Helper/Controller/EventController` | `dispatch()` |
| `Flash/Helper/Controller/FlashController` | `addFlash()` |
| `Form/Helper/Controller/FormController` | `createForm()`, `createFormBuilder()` |
| `Http/Helper/Controller/HttpController` | `json()`, `redirect()`, `createNotFoundException()`, `createAccessDeniedException()` |
| `HttpClient/Helper/Controller/HttpClientController` | `httpClient()` |
| `Mailer/Helper/Controller/MailerController` | `sendEmail()` |
| `Orm/Helper/Controller/OrmController` (package) | `getOrm()`, `getRepository()` |
| `Queue/Helper/Controller/QueueController` (package) | `dispatchMessage()` |
| `Routing/Helper/Controller/RoutingController` | `generateUrl()`, `redirectToRoute()` |
| `Security/Helper/Controller/SecurityController` (package) | `getUser()`, `isGranted()`, `denyAccessUnlessGranted()`, `loginUser()`, `logoutUser()`, `getLastUsername()`, `getLastAuthenticationError()` |
| `Serializer/Helper/Controller/SerializerController` | `serialize()`, `deserialize()` |
| `Session/Helper/Controller/SessionController` | `getSession()` |
| `Translation/Helper/Controller/TranslationController` (package) | `translate()`, `switchLocale()` |
| `Upload/Helper/Controller/UploadController` | `storeUpload()`, `deleteUpload()`, `uploadUrl()` |
| `Validator/Helper/Controller/ValidatorController` | `validate()` |
| `View/Helper/Controller/ViewController` | `render()`, `renderView()` |

A controller can pick only the traits it needs. It implements `ControllerInterface` (`setContainer(ContainerManagerInterface $container): void`), and `ContainerController` is required: the other traits get their services through `get()` (and `has()` for `HttpController`).

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Container\Helper\Controller\ContainerController;
use NeoPHP\Component\Controller\Contract\ControllerInterface;
use NeoPHP\Component\Http\Helper\Controller\HttpController;
use NeoPHP\Component\Http\Response\JsonResponse;

class ApiController implements ControllerInterface
{
    use ContainerController;
    use HttpController;

    public function status(): JsonResponse
    {
        return $this->json(['ok' => true]);
    }
}
```

An application trait follows the same rule: it declares `abstract protected function get(string $id): mixed;` and uses `$this->get()` to reach its services.

```php
<?php

declare(strict_types=1);

namespace App\Controller\Helper;

use App\Service\Clock;
use DateTimeImmutable;

trait ClockController
{
    abstract protected function get(string $id): mixed;

    protected function now(): DateTimeImmutable
    {
        return $this->get(Clock::class)->now();
    }
}
```

## Resolver API

`NeoPHP\Component\Controller\ControllerManagerInterface` (implemented by `ControllerManager`) is used by the kernel:

| Method | Description |
|---|---|
| `resolve(mixed $controller): callable` | turns a controller format into a callable |
| `resolveArguments(callable $controller, Request $request, array $routeParameters = []): array` | builds the arguments |
| `dispatch(mixed $controller, Request $request, array $routeParameters = []): Response` | resolves, calls and converts the result |
| `addArgumentResolver(ArgumentResolverInterface $resolver): static` / `getArgumentResolvers(): array` | argument resolvers of `ControllerManager` |

### Argument resolvers

`NeoPHP\Component\Controller\Contract\ArgumentResolverInterface`:

| Method | Description |
|---|---|
| `supports(ReflectionParameter $parameter, Request $request): bool` | whether the resolver handles this argument |
| `resolve(ReflectionParameter $parameter, Request $request): mixed` | the argument value |

`ControllerManager` asks its resolvers after the `Request` type-hint and before the route parameters. The resolvers are the services listed in the container entry `controller.argument_resolvers` (`ArgumentResolverInterface::SERVICES_ID`), an array of service ids read on the first dispatch. The Serializer registers `RequestPayloadResolver` there. To add one, append it in a provider:

```php
$resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
$container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, CurrentTenantResolver::class]);
```

## Exceptions

`NeoPHP\Component\Controller\Exception\ControllerException` is thrown when a controller cannot be resolved (missing or non-public method, class without `__invoke()`), when an argument cannot be resolved, or when the return value is not supported.

## Changelog

- v2.0.0 — `ControllerManager` is the `final` entry point of the module, declared with `#[Component]`; `ControllerManagerInterface` replaces `Contract\ControllerResolverInterface`; the internal classes are marked `@internal`.
- v1.36.0 — `make:controller` (PHP / Twig template, `--api`, `--no-template`, sub-namespaces, `ControllerMaker`).
- v1.31.0 — entity arguments are loaded by the ORM `EntityValueResolver`.
- v1.24.0 — `ApiController` trait: `paginate()`, `jsonPage()`, `rateLimit()`, `createRateLimiter()`, `problemJson()`; `PageRequest` / `#[MapPagination]` controller arguments (see the Api documentation).
- v1.23.0 — Argument resolvers (`ArgumentResolverInterface`, `controller.argument_resolvers`) used by `#[MapRequestPayload]` / `#[MapQueryString]`; `serialize()` / `deserialize()` in controllers; `json()` accepts a serializer context.
- v1.16.0 — `sendEmail()` in controllers.
- v1.13.0 — Security shortcuts (`getUser()`, `isGranted()`, `denyAccessUnlessGranted()`, `loginUser()`, `logoutUser()`...).
- v1.12.0 — `createForm()`, `createFormBuilder()`, `getCsrfToken()`, `isCsrfTokenValid()`.
- v1.11.0 — `getConnection()`, `getOrm()`, `getRepository()`.
- v1.10.0 — `validate()`.
- v1.9.0 — `dispatch()`.
- v1.6.0 — `getSession()`, `getCookies()`, `addFlash()`.
- v1.4.0 — `AbstractController` made of traits shipped by each feature in `Helper/Controller/`.
- v1.0.0 — Controllers: resolution, argument resolution, `AbstractController`.