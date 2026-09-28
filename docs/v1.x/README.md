# NeoPHP

NeoPHP is an ultra modular PHP framework with no dependency other than PHP 8.2.
This guide creates a working application in a few minutes: installation, a first page, a database, a form, a login and the deployment.
Each feature has its own documentation in `src/{components,packages,process}/Feature/Docs/v1.x/README.md`.

## Summary

- [Requirements](#requirements)
- [Create the project](#create-the-project)
- [Project structure](#project-structure)
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
- [Changelog](#changelog)

## Requirements

- PHP 8.2 or higher
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
        "neophp/framework": "^1.0"
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
    Kernel.php
templates/              PHP (.php) and Twig (.html.twig) templates
translations/           translation files ({domain}.{locale}.yaml|xlf)
var/                    cache, logs, sessions
.env                    environment variables (APP_ENV, APP_DEBUG, APP_SECRET, APP_URL, TRUSTED_PROXIES, TRUSTED_HOSTS, DATABASE_URL, MAILER_DSN, NEO_AI_*)
.env.local              local values and secrets, never committed
```

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
```

See the Database and ORM documentation.

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

access_control:
  - { path: ^/admin, roles: ROLE_ADMIN }
```

In controllers: `$this->getUser()`, `$this->denyAccessUnlessGranted('ROLE_ADMIN')`, `#[IsGranted('ROLE_ADMIN')]`. In templates: `app_user()`, `is_granted('ROLE_ADMIN')`, `logout_path()`. See the Security documentation.

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
| `php bin/neo cache:pool:clear --all` | clears the cache pools (also `cache:pool:list`, `cache:pool:prune`) |
| `php bin/neo serializer:debug "App\Entity\Post"` | serialization metadata of a class |
| `php bin/neo asset:reload --minify` | compiles `assets/` into `public/builds/` |
| `php bin/neo openapi:dump --format=yaml` | OpenAPI document of the API routes |
| `php bin/neo debug:container` | services of the container |
| `php bin/neo translation:generate` / `translation:debug` / `translation:lint` | translation files |

`php bin/neo help <command>` shows the options and examples of a command. See the Console documentation.

## Deployment

1. `.env.local` (or real environment variables): `APP_ENV=prod`, `APP_DEBUG=0`, a new `APP_SECRET`, `APP_URL=https://example.com`, `TRUSTED_HOSTS=example.com`, `TRUSTED_PROXIES` when a reverse proxy / load balancer is in front of PHP, `DATABASE_URL`, `MAILER_DSN`
2. `composer install --no-dev --optimize-autoloader`
3. `php bin/neo migration:migrate -n`
4. `php bin/neo tailwind:run --minify` (if Tailwind is used), then `php bin/neo asset:reload --minify`
5. `php bin/neo cache:clear`
6. Point the web server document root to `public/` (`public/.htaccess` is provided for Apache: only `index.php` is executed, hidden files are never served; with Nginx, send every request to `index.php` and refuse the other `.php` files)

## Features

| Group | Features |
|---|---|
| components | Api, Asset, Cache, Config, Container, Controller, Cookie, Csrf, Database, Event, Exception, Flash, Form, Http, HttpClient, Kernel, Logger, Mailer, Middleware, Routing, Serializer, Service, Session, Validator, View |
| packages | Debug, Dotenv, Markdown, NeoAI, Orm, Security, Tailwind, Translation, WebProfiler, Yaml |
| process | Console, Installer |

The documentation of a feature is in `src/<group>/<Feature>/Docs/v1.x/README.md`, for example `src/components/Routing/Docs/v1.x/README.md`.

## Changelog

- v1.29.1 — Bugfix and security: ORM `findBy()` / `findOneBy()` / `count()` refuse unknown fields (SQL injection), `UploadedFile::move()` random name and executable extensions refused, PHP execution limited to `index.php` in `public/.htaccess`, CORS `allow_credentials` with `'*'` refused, trusted proxies and trusted hosts (`TRUSTED_PROXIES`, `TRUSTED_HOSTS`), profiler and NeoAI restricted to local networks (`allowed_ips`), `markdown` filter escapes raw HTML by default, cache entries signed with `APP_SECRET`, directories created in `0775`, login throttling files cleaned, CSRF tokens limited in the session, `RateLimitListener` file name, NeoAI `ai:test` and default models, commented configuration files and `.env`. Existing projects: run `php bin/neo install` to add the new variables to `.env`, then add `trusted_proxies` / `trusted_hosts` to `config/framework/app.yaml`
- v1.29.0 — NeoAI package (development assistant enabled only in debug: OpenAI, OpenAI-compatible (Mistral, Groq, OpenRouter, LM Studio, vLLM), Anthropic, Gemini and Ollama providers, read-only `neo-tool` loop in a sandbox with secret redaction, `ai:start` chat with patch review, `ai:scan` audit with Markdown report, `ai:test`, toolbar chat and profiler panel, `config/packages/neo_ai.yaml`); WebProfiler `ToolbarAssetInterface` to add CSS / JavaScript to the toolbar
- v1.28.2 — Bugfix: translation profiler
- v1.28.1 — Bugfix: missing `translation:generate` command
- v1.28.0 — Translation profiler (toolbar item and panel, opt-in `TranslationTrace`: defined / fallback / missing messages, locale detection source, loaded catalogues)
- v1.27.0 — Security profiler (toolbar item and panel, opt-in `SecurityTrace`: access decisions, voter votes, `access_control`, login / logout events)
- v1.26.0 — Database and ORM profiler (query logger with transactions, Database panel, unit of work statistics, ORM panel)
- v1.25.2 — Bugfix: WebProfiler controller helper location
- v1.25.1 — Bugfix: YAML indentation of the generated configuration files
- v1.25.0 — WebProfiler package (web debug toolbar and `/_profiler` interface, elements discovered in `Helper/Profiler` of every feature and with `#[AsProfiler]`, panels and blocks, `Stopwatch`, Ajax requests tracking, `profiler:list` and `profiler:clear`, `config/packages/web_profiler.yaml`)
- v1.24.0 — Api component (CORS, rate limiter with fixed window / sliding window / token bucket policies, `#[RateLimit]`, pagination with `Link` / `X-Total-Count` headers and `#[MapPagination]`, RFC 7807 problem details, OpenAPI 3.1 generation with `#[OA\Operation]` / `#[OA\Response]` / `#[OA\Tag]`, `openapi:dump`, `/api/doc`), `TooManyRequestsHttpException`
- v1.23.0 — Serializer component (JSON / XML / CSV / YAML, normalizers for objects, dates, enums and ORM entities, `#[Groups]`, `#[SerializedName]`, `#[Ignore]`, `#[MaxDepth]`, `#[Context]`, `#[Type]`, `#[MapRequestPayload]` / `#[MapQueryString]` controller arguments, `json()` with a serializer context, `serialize()` in controllers, `serializer:debug`)
- v1.22.0 — Cache component (pools with filesystem / APCu / database / array adapters, `get()` with callback and stampede protection, tags, `cache()` in controllers, `cache:pool:*` commands, HttpClient `cache` option)
- v1.21.0 — HttpClient component (requests with JSON / form / multipart bodies, curl and stream transports, parallel requests, downloads, retries, named clients, `httpClient()`, `http:request`)
- v1.20.0 — Translation package (YAML / XLIFF catalogues, ICU-lite plurals, locale detection, `translate()` / `trans`, translated validation and security messages, `translation:generate`, `translation:debug`, `translation:lint`)
- v1.19.0 — Markdown package (parser, document API, HTML to Markdown, `markdown` filter, `markdown:convert`)
- v1.18.0 — Tailwind package (`tailwind:install`, `tailwind:run`)
- Bugfix after v1.17.0 — absolute URLs (`url()`, `APP_URL`), `make:auth` base layout, `make:migration` description, misnamed view helpers reported in debug
- v1.17.0 — interactive console, `make:entity` wizard
- v1.16.0 — Mailer
- v1.15.0 — Console refactor (`#[AsCommand]`, `AbstractConsole`)
- v1.14.0 — Debug (`dump()`, `dd()`)
- v1.13.0 — Security
- v1.12.0 — Forms and CSRF
- v1.11.0 — Database and ORM
- v1.10.0 — Validator
- v1.9.0 — Events (v1.9.1: routing uses the kernel class discovery)
- v1.8.0 — `#[Autowire]`, `#[Inject]`, `config/services.yaml`
- v1.7.0 — Middlewares
- v1.6.0 — Session, cookies and flash messages
- v1.5.0 — `#[Route]` attributes, routes cache
- v1.4.0 — `AbstractController` made of feature traits
- v1.3.0 — Assets
- v1.2.0 — Twig and view helpers
- v1.1.0 — Logger
- v1.0.0 — Base: routes, YAML, views, controllers, container, HTTP, console, installer, configuration