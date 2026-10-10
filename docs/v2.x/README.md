# NeoPHP

NeoPHP is an ultra modular PHP framework with no dependency other than PHP 8.2.
This guide creates a working application in a few minutes: installation, the modules, a first page, a database, a form, a login and the deployment.
It covers NeoPHP 2; the guide of NeoPHP 1 is in [docs/v1.x](../v1.x/README.md) and the changes are listed in [UPGRADE-2.0.md](../../UPGRADE-2.0.md).
Each feature has its own documentation in `src/{components,packages,process}/Feature/Docs/v2.x/README.md`.

## Summary

- [Requirements](#requirements)
- [Create the project](#create-the-project)
- [Project structure](#project-structure)
- [Modules](#modules)
- [First page](#first-page)
- [Database and entities](#database-and-entities)
- [Forms](#forms)
- [APIs](#apis)
- [Login](#login)
- [Styles with Tailwind](#styles-with-tailwind)
- [Translations](#translations)
- [Useful commands](#useful-commands)
- [Deployment](#deployment)
- [Features](#features)
- [Versions and support](#versions-and-support)
- [Changelog](#changelog)

## Requirements

- PHP 8.2 or higher with the `ctype`, `json`, `mbstring` and `tokenizer` extensions (required by Composer)
- Composer
- The PHP extensions of the features you use: `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite` (database), `openssl` (SMTP over TLS, Tailwind download), `fileinfo` (uploads), `curl` (recommended for the HTTP client, parallel requests), `apcu` (optional, `apcu` cache adapter), `dom` (XML format of the Serializer)
- Optional: `twig/twig` ^3.0 for Twig templates

## Create the project

Create the application from the `neophp/skeleton` project:

```bash
composer create-project neophp/skeleton my-app
cd my-app
php bin/neo serve
```

The skeleton `composer.json`:

```json
{
    "name": "neophp/skeleton",
    "description": "NeoPHP application skeleton.",
    "type": "project",
    "license": "MIT",
    "keywords": ["neophp", "skeleton", "framework"],
    "require": {
        "php": ">=8.2",
        "neophp/framework": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "scripts": {
        "neo:install": "@php vendor/bin/neo install",
        "post-create-project-cmd": "@neo:install"
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

`composer create-project` runs `neo install`, which generates the project files, a random `APP_SECRET` and `APP_URL` in `.env`. Existing files are never overwritten (`php bin/neo install --force` to regenerate them). Open http://127.0.0.1:8000.

For Twig templates, add Twig to the project:

```bash
composer require twig/twig
```

## Project structure

```
assets/                 CSS, JS and images compiled into public/builds/
bin/neo                 command line
config/
    config.php          modules enabled or disabled in this project, per environment
    framework/          configuration of the components (api.yaml, app.yaml, cache.yaml, database.yaml, http_client.yaml, mailer.yaml, serializer.yaml, view.yaml...)
    packages/           configuration of the packages (orm.yaml, security.yaml, debug.yaml, translation.yaml, web_profiler.yaml, neo_ai.yaml; tailwind.yaml is created by tailwind:install)
    routes.yaml         routes
    services.yaml       services
migrations/             database migrations
public/index.php        front controller
src/
    Controller/         controllers
    Entity/             ORM entities
    Repository/         repositories
    Form/               forms
    Command/            console commands
    Kernel.php          application kernel (extends AbstractKernel)
templates/              PHP (.php) and Twig (.html.twig) templates
translations/           translation files ({domain}.{locale}.yaml|xlf)
var/                    cache, logs, sessions
.env                    environment variables (APP_ENV, APP_DEBUG, APP_SECRET, APP_URL, TRUSTED_PROXIES, TRUSTED_HOSTS, DATABASE_URL, MAILER_DSN, NEO_AI_*)
.env.local              local values and secrets, never committed
```

## Modules

NeoPHP is made of modules: the components (`src/components/`), the packages (`src/packages/`) and the processes (`src/process/`). Each module has a single entry point at its root, a `final` manager declared with an attribute, and its interface:

```php
#[Component(provider: SessionProvider::class)]
final class SessionManager implements SessionManagerInterface
{
}
```

| Attribute | Module |
|---|---|
| `#[Component]` | component of the framework (`src/components/`) |
| `#[Package]` | package, of the framework (`src/packages/`) or installed with Composer |
| `#[Process]` | process (`src/process/`): Console, Installer, Package |

The kernel discovers the modules by itself (nothing to register) and loads them in the order of their `requires`. Every module is enabled; `config/config.php` only lists what the project changes:

```php
<?php

declare(strict_types=1);

return [
    NeoPHP\Package\WebProfiler\WebProfilerManager::class => ['dev' => true],
    NeoPHP\Package\NeoAI\NeoAiManager::class => ['dev' => true],
    NeoPHP\Component\Mailer\MailerManager::class => false,
    NeoPHP\Package\Queue\QueueManager::class => ['all' => true, 'test' => false],
];
```

`false` disables a module, an array enables it only in the listed environments (`all` for the others). The modules required by the kernel cannot be disabled, and a module required by an enabled module cannot be disabled: the kernel stops with a clear error naming both modules. Run `php bin/neo cache:clear` after a change in production.

To use a module, inject its interface, never its internal classes:

```php
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Package\Orm\Contract\EntityManagerInterface;
use NeoPHP\Package\Translation\TranslationManagerInterface;

public function __construct(
    private RoutingManagerInterface $routing,
    private EntityManagerInterface $entityManager,
    private TranslationManagerInterface $translator,
) {
}
```

The public API of a module is its manager and its interface, its `Contract\` folder (interfaces and base classes to extend), its attributes, its exceptions and its events. The classes marked `@internal` (providers, discoveries, traces, helpers of other modules) can change in any version.

The integration of a module with another one lives in `Helper/<Module>/` of the module, for example `src/components/Flash/Helper/WebProfiler/FlashProfiler.php` (the Flash panel of the profiler): it is loaded only when both modules are enabled and uses only the public API of the other module. See the Kernel documentation.

### NeoPHP packages

A NeoPHP package is a Composer package of type `neophp-package` that adds modules to the project: its manager declared with `#[Package]`, its configuration, templates, commands, listeners and view helpers.

```bash
php bin/neo neophp:package:install acme/neo-billing
php bin/neo neophp:package:list
php bin/neo neophp:package:update
php bin/neo neophp:package:remove acme/neo-billing
php bin/neo neophp:package:create acme/neo-billing --link
```

The configuration of a package is copied into `config/packages/<name>/`, its routes are imported in `config/routes.yaml` (`resource: '@<name>'`), its templates are rendered with `@<name>/`, its assets with `asset('@<name>/...')`, and its translations, commands, listeners, entities and migrations are loaded with the ones of the project. The page `/_profiler/packages` of the profiler lists the packages and modules with their status per environment. See the Package documentation.

## First page

`src/Controller/HelloController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

class HelloController extends AbstractController
{
    #[Route('/hello/{name}', name: 'hello', methods: ['GET'], defaults: ['name' => 'world'])]
    public function index(string $name): Response
    {
        return $this->render('hello/index.html.twig', ['name' => $name]);
    }
}
```

`templates/hello/index.html.twig`

```twig
{% extends 'base.html.twig' %}

{% block body %}
    <h1>Hello {{ name }}!</h1>
    <a href="{{ path('hello', {name: 'NeoPHP'}) }}">Say hello to NeoPHP</a>
{% endblock %}
```

The same page with a PHP template, `templates/hello/index.php`:

```php
<?php $this->extend('base') ?>

<?php $this->start('content') ?>
<h1>Hello <?= $this->e($name) ?>!</h1>
<?php $this->stop() ?>
```

Controllers are discovered in `src/Controller/` (`#[Route]`), or declared in `config/routes.yaml`. See the Routing, Controller and View documentation.

## Database and entities

`.env`

```dotenv
DATABASE_URL="mysql://user:password@127.0.0.1:3306/app?charset=utf8mb4"
```

```bash
php bin/neo db:create --if-not-exists
php bin/neo make:entity
```

`make:entity` is a wizard: it asks the entity name, then the fields one by one (type `?` to list the types), the relations and their inverse side. Then:

```bash
php bin/neo make:migration
php bin/neo migration:migrate
```

In a controller:

```php
#[Route('/posts', name: 'post_index')]
public function index(PostRepository $posts): Response
{
    return $this->render('post/index.html.twig', ['posts' => $posts->findBy([], ['id' => 'DESC'])]);
}

#[Route('/posts/{id}', name: 'post_show')]
public function show(Post $post): Response
{
    return $this->render('post/show.html.twig', ['post' => $post]);
}
```

`Post $post` is loaded from `{id}` (or `{slug}`...), a 404 error is returned when it does not exist. See the Database and ORM documentation.

## Forms

```bash
php bin/neo make:form Post Post
```

```php
#[Route('/posts/new', name: 'post_new', methods: ['GET', 'POST'])]
public function new(Request $request): Response
{
    $post = new Post();
    $form = $this->createForm(PostForm::class, $post);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $this->getOrm()->persist($post);
        $this->getOrm()->flush();
        $this->addFlash('success', 'Post created.');

        return $this->redirectToRoute('post_index');
    }

    return $this->render('post/new.html.twig', ['form' => $form]);
}
```

```twig
{{ form(form) }}
```

The CSRF token is added automatically. Set `theme: bootstrap5` in `config/framework/form.yaml` for Bootstrap 5. See the Form, Validator and Csrf documentation.

## APIs

```php
#[Route('/api/posts', name: 'api_post_create', methods: ['POST'])]
public function create(#[MapRequestPayload] PostInput $input): JsonResponse
{
    $post = (new Post())->setTitle($input->title)->setContent($input->content);
    $this->getOrm()->persist($post);
    $this->getOrm()->flush();

    return $this->json($post, 201, [], ['groups' => ['read']]);
}
```

The JSON (or XML, form) body is decoded, mapped to the `PostInput` DTO and validated: invalid data returns a 422 JSON response with the violations, a malformed body a 400 and an unsupported `Content-Type` a 415. `json()` normalizes objects with the Serializer (`#[Groups]`, `#[SerializedName]`, `#[Ignore]`...). See the Serializer documentation.

The Api component adds the rest of an API toolkit, configured in `config/framework/api.yaml`:

```php
#[Route('/api/posts', name: 'api_post_index', methods: ['GET'])]
#[RateLimit('api')]
#[OA\Response(200, type: Post::class, groups: ['read'], paginated: true)]
public function index(PostRepository $posts, #[MapPagination] PageRequest $pageRequest): JsonResponse
{
    $page = $this->paginate($posts->createQueryBuilder('p')->orderBy('p.id', 'DESC'), $pageRequest);

    return $this->jsonPage($page, ['groups' => ['read']]);
}
```

- CORS: preflight `OPTIONS` requests answered before routing, headers added to every response of the configured paths (also errors), `#[Cors]` per controller;
- rate limiting: named limiters (fixed window, sliding window, token bucket) stored in a cache pool, `#[RateLimit('api')]` or `$this->rateLimit('login', $email)`, 429 with `Retry-After` and `X-RateLimit-*` headers;
- pagination: `?page=&limit=` for arrays, iterables and ORM query builders, `{"items", "pagination", "links"}` with `Link` and `X-Total-Count` headers;
- RFC 7807 problem details: errors of `/api` routes rendered as `application/problem+json` (validation violations included);
- OpenAPI 3.1: `php bin/neo openapi:dump` or `/api/doc` generated from the routes, DTOs, serializer groups and validation constraints.

See the Api documentation.

## Login

```bash
php bin/neo make:user
php bin/neo make:migration && php bin/neo migration:migrate
php bin/neo make:auth --twig
```

`config/packages/security.yaml`

```yaml
providers:
    users:
        entity:
            class: App\Entity\User
            property: email

firewalls:
    main:
        pattern: ^/
        provider: users
        form_login:
            login_path: app_login
            enable_csrf: true
        logout:
            path: app_logout
            enable_csrf: true
            methods: [POST]

access_control:
    - { path: ^/admin, roles: ROLE_ADMIN }
```

In controllers: `$this->getUser()`, `$this->denyAccessUnlessGranted('ROLE_ADMIN')`, `#[IsGranted('ROLE_ADMIN')]`. In templates: `app_user()`, `is_granted('ROLE_ADMIN')`, `logout_path()`, `logout_form('Logout')` (the logout is a POST form protected by a CSRF token). See the Security documentation.

## Styles with Tailwind

```bash
php bin/neo tailwind:install
php bin/neo tailwind:run --watch
```

```twig
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
```

Without Tailwind, the files of `assets/` are served with `asset()` as well. See the Asset and Tailwind documentation.

## Translations

Enable the locales in `config/packages/translation.yaml` (`locales: [en, fr]`) and write `translations/messages.fr.yaml`:

```yaml
home:
    title: Bienvenue
    posts: "{count, plural, =0 {Aucun article} one {# article} other {# articles}}"
```

```twig
<h1>{{ 'home.title'|trans }}</h1>
<p>{{ translate('home.posts', {count: posts|length}) }}</p>
```

The locale is detected from the route `{_locale}`, `?lang=`, the session, a cookie or `Accept-Language`; `$this->switchLocale('fr')` in a controller remembers it. Validation and login errors are written in English and translated through `translations/validators.fr.yaml` and `translations/security.fr.yaml`. `php bin/neo translation:generate` adds the missing keys to the files. See the Translation documentation.

## Useful commands

| Command | Description |
|---|---|
| `php bin/neo serve` | development server |
| `php bin/neo list` | every command |
| `php bin/neo route:list` | routes |
| `php bin/neo make:entity` (also `make:form`, `make:command`, `make:user`, `make:auth`, `make:voter`, `make:email`) | code generators (they ask the missing values) |
| `php bin/neo make:migration` / `migration:migrate` | database migrations |
| `php bin/neo cache:clear` | clears `var/cache/` |
| `php bin/neo neophp:package:install vendor/name` (also `neophp:package:list`, `update`, `remove`, `create`) | NeoPHP packages |
| `php bin/neo cache:pool:clear --all` | clears the cache pools (also `cache:pool:list`, `cache:pool:prune`) |
| `php bin/neo serializer:debug "App\Entity\Post"` | serialization metadata of a class |
| `php bin/neo asset:reload --minify` | compiles `assets/` into `public/builds/` |
| `php bin/neo openapi:dump --format=yaml` | OpenAPI document of the API routes |
| `php bin/neo debug:container` | services of the container |
| `php bin/neo translation:generate` / `translation:debug` / `translation:lint` | translation files |

`php bin/neo help <command>` shows the options and examples of a command. See the Console documentation.

## Deployment

1. `.env.local` (or real environment variables): `APP_ENV=prod`, `APP_DEBUG=0`, a new `APP_SECRET`, `APP_URL=https://example.com`, `TRUSTED_HOSTS=example.com`, `TRUSTED_PROXIES` when a reverse proxy / load balancer is in front of PHP, `DATABASE_URL`, `MAILER_DSN`; in `config/framework/app.yaml`, keep `security_headers.enabled: true` and set `hsts: 31536000` once HTTPS works
2. `composer install --no-dev --optimize-autoloader`
3. `php bin/neo migration:migrate -n`
4. `php bin/neo tailwind:run --minify` (if Tailwind is used), then `php bin/neo asset:reload --minify`
5. `php bin/neo cache:clear`
6. Point the web server document root to `public/`. In a sub-directory of the domain (`https://example.com/app/`), make `/app/` serve `public/` (Apache `Alias /app /var/www/app/public` and `RewriteBase /app/` in `public/.htaccess`) and set `APP_URL=https://example.com/app`: the routes stay the same, the generated URLs contain `/app` (`public/.htaccess` is provided for Apache: only `index.php` is executed, hidden files are never served; with Nginx, send every request to `index.php` and refuse the other `.php` files)

## Features

| Group | Features |
|---|---|
| components | Api, Asset, Cache, Config, Container, Controller, Cookie, Csrf, Database, Event, Exception, Flash, Form, Http, HttpClient, Kernel, Logger, Mailer, Middleware, Routing, Serializer, Service, Session, Upload, Validator, View |
| packages | Debug, Dotenv, Markdown, NeoAI, Orm, Queue, Scheduler, Security, Tailwind, Translation, WebProfiler, Yaml |
| process | Console, Installer, Package |

The documentation of a feature is in `src/<group>/<Feature>/Docs/v2.x/README.md`, for example `src/components/Routing/Docs/v2.x/README.md`. The documentation of NeoPHP 1 stays next to it, in `Docs/v1.x/`.

## Versions and support

NeoPHP follows [semantic versioning](https://semver.org/) inside a major version:

| Version | Contains | Upgrade |
|---|---|---|
| patch (`v2.0.1`) | bug and security fixes | always safe: `composer update` |
| minor (`v2.1.0`) | new features, new options; a changed default only in the generated files of new projects | safe; read the changelog for the new options |
| major (`v3.0.0`) | incompatible changes | follow the upgrade notes |

- `v2.x` is the maintained branch of NeoPHP 2: releases are tagged automatically from its merged pull requests (`feat` gives a minor version, `fix` / `perf` / `refactor` a patch).
- In `v2.x`, the public API (managers, their interfaces, `Contract\`, attributes, exceptions, events, options and configuration keys) is not removed nor renamed. When it has to change, the old one keeps working and is marked deprecated in the documentation until the next major version. The classes marked `@internal` are not covered.
- Security fixes are released on the latest `v2.x` version. Report a vulnerability privately, see [SECURITY.md](../../SECURITY.md).
- Require NeoPHP with `^2.0` in `composer.json` to receive the fixes and features of v2 without breaking changes. To upgrade from 1.x, follow [UPGRADE-2.0.md](../../UPGRADE-2.0.md).

## Changelog

The history of the versions is in [CHANGELOG.md](../../CHANGELOG.md); each feature documentation has its own Changelog section.