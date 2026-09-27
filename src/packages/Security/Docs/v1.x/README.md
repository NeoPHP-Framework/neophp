# Security

The Security package (`src/packages/Security`) authenticates users (login form, HTTP Basic, access tokens, custom authenticators, remember-me) and checks their permissions (roles, role hierarchy, voters, `#[IsGranted]`, `access_control`).
It is enabled when `config/packages/security.yaml` defines at least one firewall or one `access_control` rule; otherwise it does nothing.

## Summary

- [Quick start](#quick-start)
- [Users](#users)
- [User providers](#user-providers)
- [Passwords](#passwords)
- [Firewalls](#firewalls)
- [Login form](#login-form)
- [Logout](#logout)
- [Access tokens](#access-tokens)
- [Custom authenticators](#custom-authenticators)
- [Tokens](#tokens)
- [Authorization](#authorization)
- [Voters](#voters)
- [Helpers](#helpers)
- [Events](#events)
- [Exceptions](#exceptions)
- [Commands](#commands)
- [Profiler](#profiler)
- [Changelog](#changelog)

## Quick start

```bash
php bin/neo make:user
php bin/neo make:migration
php bin/neo migration:migrate
php bin/neo make:auth
php bin/neo security:hash-password secret
```

`make:user` creates `src/Entity/User.php` and `src/Repository/UserRepository.php`; `make:auth` creates `src/Controller/SecurityController.php` and `templates/security/login.php` (`--twig` for Twig), plus the base layout when it is missing.

```yaml
providers:
  users:
    entity:
      class: App\Entity\User
      property: email

password_hashers:
  default: auto

firewalls:
  assets:
    pattern: ^/builds/
    security: false
  main:
    pattern: ^/
    provider: users
    form_login:
      login_path: app_login
      enable_csrf: true
      default_target_path: /
    logout:
      path: app_logout
      target: /
    remember_me:
      lifetime: 604800
    login_throttling:
      max_attempts: 5
      interval: 60

role_hierarchy:
  ROLE_ADMIN: [ROLE_USER]

access_control:
  - { path: ^/admin, roles: ROLE_ADMIN }
  - { path: ^/profile, roles: IS_AUTHENTICATED }

access_decision_manager:
  strategy: affirmative
  allow_if_all_abstain: false
  allow_if_equal_granted_denied: true

voters: []
```

`neo install` creates a default `config/packages/security.yaml` (memory provider without users, login form on `/login`).

| Key | Description |
|---|---|
| `providers` | user providers, see [User providers](#user-providers) |
| `password_hashers` | hashers per user class, see [Passwords](#passwords) |
| `firewalls` | see [Firewalls](#firewalls) |
| `role_hierarchy` | role => list of inherited roles |
| `access_control` | list of rules, see [Authorization](#authorization) |
| `access_decision_manager` | voting strategy, see [Voters](#voters) |
| `voters` | extra voter classes |

## Users

A user implements `NeoPHP\Package\Security\Contract\UserInterface`, and `PasswordAuthenticatedUserInterface` when it logs in with a password.

| Interface | Methods |
|---|---|
| `UserInterface` | `getUserIdentifier(): string`, `getRoles(): array` |
| `PasswordAuthenticatedUserInterface` | `getPassword(): ?string` |

`make:user [User] [--property=email] [--force]` generates an ORM entity with `id`, the identifier property (unique), `roles` (JSON, `ROLE_USER` always added) and `password`.

`NeoPHP\Package\Security\User\InMemoryUser` is a ready-made user: `new InMemoryUser(string $identifier, ?string $password = null, array $roles = [])`, with `setPassword()`.

`NeoPHP\Package\Security\User\UserClass::of(UserInterface $user): string` returns the real class of a user, ORM lazy proxies included (use it to compare classes or pick a hasher).

## User providers

| Provider | Configuration | Class |
|---|---|---|
| ORM entity | `entity: { class: App\Entity\User, property: email }`; without `property`, the repository must define `loadUserByIdentifier()` | `EntityUserProvider` |
| Memory | `memory: { users: { admin: { password: '$2y$...', roles: [ROLE_ADMIN] } } }` | `InMemoryUserProvider` (`createUser(InMemoryUser $user)`) |
| Chain | `chain: { providers: [users, admins] }` | `ChainUserProvider` (`getProviders()`) |
| Custom | `id: App\Security\MyProvider` | implements `UserProviderInterface` |

A firewall uses its `provider` option, or the single provider when only one is defined. The user is stored in the session by identifier and reloaded on the first access to the user of the request; when its password changed, the session is closed.

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Contract\UserProviderInterface;
use NeoPHP\Package\Security\Exception\UserNotFoundException;

class LdapUserProvider implements UserProviderInterface
{
    public function __construct(private LdapClient $ldap)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->ldap->findUser($identifier) ?? throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, User::class, true);
    }
}
```

A provider that also implements `PasswordUpgraderInterface` (`upgradePassword(PasswordAuthenticatedUserInterface $user, string $hashedPassword): void`) receives the rehashed passwords; the entity and chain providers implement it.

## Passwords

`password_hashers` maps a class (or an interface, or `default`) to a hasher:

```yaml
password_hashers:
  App\Entity\User: auto
  App\Entity\Admin: { algorithm: bcrypt, cost: 12 }
  App\Entity\Legacy: { id: App\Security\LegacyHasher }
  default: auto
```

| Value | Hasher |
|---|---|
| `auto`, `bcrypt`, `argon2i`, `argon2id` | `NativePasswordHasher(string $algorithm = 'auto', ?int $cost = null, ?int $memoryCost = null, ?int $timeCost = null)` |
| `plaintext` | `PlaintextPasswordHasher` (tests only) |
| `{ id: ... }` | class implementing `PasswordHasherInterface` |

`PasswordHasherInterface`: `hash(string $plainPassword): string`, `verify(string $hashedPassword, string $plainPassword): bool`, `needsRehash(string $hashedPassword): bool`; `MAX_PASSWORD_LENGTH = 4096`.

Inject `NeoPHP\Package\Security\Hasher\UserPasswordHasher`:

```php
$user->setPassword($hasher->hashPassword($user, $plainPassword));
$valid = $hasher->isPasswordValid($user, $plainPassword);
```

| Method | Description |
|---|---|
| `hashPassword(PasswordAuthenticatedUserInterface\|string $user, string $plainPassword): string` | hashes with the hasher of the user (or class name) |
| `isPasswordValid(PasswordAuthenticatedUserInterface $user, string $plainPassword): bool` | checks a password |
| `needsRehash(PasswordAuthenticatedUserInterface $user): bool` | the hash uses older options |
| `getPasswordHasher(string\|object $user = 'default'): PasswordHasherInterface` | the hasher of a user or class (`UserPasswordHasher::DEFAULT_KEY`) |

When a password hashed with older options is valid, it is rehashed and saved through the provider (`PasswordUpgraderInterface`).

## Firewalls

The first firewall whose `pattern` (regular expression, plus optional `host`, `methods`, `ips`) matches the request is used.

| Option | Description |
|---|---|
| `pattern`, `host`, `methods`, `ips` | request matcher |
| `security: false` | no authentication at all (assets...) |
| `stateless: true` | nothing stored in the session (APIs) |
| `provider` | name of the user provider |
| `context` | firewalls with the same context share the logged user |
| `form_login` | login form, see [Login form](#login-form) |
| `http_basic` | `{ realm: 'Secured Area' }` |
| `access_token` | see [Access tokens](#access-tokens) |
| `custom_authenticators` | list of classes implementing `AuthenticatorInterface` |
| `remember_me` | `{ lifetime: 604800, name: REMEMBERME, parameter: _remember_me, always: false, path: /, domain: ~, secure: auto, samesite: Lax }`: `parameter` is the checkbox of the login form, `always` sets the cookie on every login |
| `login_throttling` | `{ max_attempts: 5, interval: 60 }`: attempts per IP + identifier (and 5 × more per IP) during `interval` seconds, stored in `var/cache/security/throttling/`; the IP is `REMOTE_ADDR` |
| `logout` | see [Logout](#logout) |
| `user_checker` | class implementing `UserCheckerInterface` |
| `entry_point` | `form_login`, `http_basic`, `access_token` or a class implementing `EntryPointInterface` |

Paths accept a path (`/login`) or a route name (`app_login`).

When an anonymous user is denied, the entry point answers: the login form redirects to `login_path` (the requested URL is restored after the login) or returns a 401 JSON response for AJAX/JSON requests, HTTP Basic returns a 401 with `WWW-Authenticate`, the access token returns a 401 JSON response. A logged user who is denied gets a 403, except a remembered user on `IS_AUTHENTICATED_FULLY`, who is sent to the login form.

`EntryPointInterface`: `start(Request $request, ?AuthenticationException $exception = null): Response`.

`UserCheckerInterface` rejects banned or disabled accounts by throwing an `AuthenticationException` (usually `CustomUserMessageAuthenticationException`):

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use NeoPHP\Package\Security\Contract\UserCheckerInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\CustomUserMessageAuthenticationException;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isBanned()) {
            throw new CustomUserMessageAuthenticationException('Your account is banned.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
```

## Login form

| `form_login` option | Default |
|---|---|
| `login_path` | `/login` |
| `check_path` | `login_path` (the `POST` on this path is the login) |
| `username_parameter` / `password_parameter` | `_username` / `_password` |
| `enable_csrf` / `csrf_parameter` / `csrf_token_id` | `false` / `_csrf_token` / `authenticate` |
| `default_target_path` | `/` |
| `always_use_default_target_path` | `false` |
| `target_path_parameter` | `_target_path` (relative paths only) |
| `use_referer` | `false` |
| `failure_path` | `login_path` |

```php
#[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
public function login(): Response
{
    return $this->render('security/login.php', [
        'last_username' => $this->getLastUsername(),
        'error' => $this->getLastAuthenticationError(),
    ]);
}
```

The error is a safe message (`Invalid credentials.`, `Invalid CSRF token.`, `Too many failed login attempts, please try again in 1 minute(s).`): an unknown user and a wrong password give the same message.

## Logout

Disabled unless declared:

```yaml
logout:
  path: /logout
  target: /
  invalidate_session: true
  enable_csrf: false
  csrf_parameter: _csrf_token
  csrf_token_id: logout
  clear_cookies: []
```

The request on `path` is handled by the firewall (the route needs no code). `logout_path()` in views returns the URL, with the CSRF token when enabled.

## Access tokens

```yaml
access_token:
  token_handler: App\Security\ApiTokenHandler
  header: Authorization
  token_type: Bearer
  query_parameter: ~
  realm: ~
```

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use NeoPHP\Package\Security\Contract\AccessTokenHandlerInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\BadCredentialsException;

class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private ApiTokenRepository $tokens)
    {
    }

    public function getUserFrom(string $accessToken): string|UserInterface
    {
        return $this->tokens->findOneBy(['value' => $accessToken])?->getOwner()?->getEmail()
            ?? throw new BadCredentialsException('Invalid access token.');
    }
}
```

The handler returns a user identifier (loaded by the provider) or a user.

## Custom authenticators

A custom authenticator extends `AbstractAuthenticator` and is listed in `custom_authenticators`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\AbstractAuthenticator;
use NeoPHP\Package\Security\Contract\UserInterface;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(private UserRepository $users)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('X-API-KEY');
    }

    public function authenticate(Request $request): Passport
    {
        $key = (string) $request->headers->get('X-API-KEY');

        return Passport::selfValidating($key, fn (string $key): ?UserInterface => $this->users->findOneBy(['apiKey' => $key]));
    }
}
```

`AuthenticatorInterface`:

| Method | Description |
|---|---|
| `supports(Request $request): bool` | whether the request carries credentials |
| `authenticate(Request $request): Passport` | builds the passport, throws an `AuthenticationException` on error |
| `createToken(Passport $passport, string $firewall): TokenInterface` | `AbstractAuthenticator` creates a `SecurityToken` |
| `onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewall): ?Response` | response, or `null` to continue the request |
| `onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response` | response, or `null` to continue the request |

### Passport

`NeoPHP\Package\Security\Authentication\Passport` carries the credentials:

| Method | Description |
|---|---|
| `new Passport(string $userIdentifier, ?string $password = null, ?Closure $userLoader = null, array $badges = [], array $attributes = [])` | the password is checked with the hasher |
| `Passport::selfValidating(string $userIdentifier, ?Closure $userLoader = null, array $badges = [], array $attributes = [])` | no password check |
| `csrf(string $id, ?string $token)` | adds a CSRF check (`BADGE_CSRF`) |
| `rememberMe(bool $enabled = true)`, `isRememberMe()` | sets the remember-me cookie (`BADGE_REMEMBER_ME`) |
| `addCheck(Closure $check)`, `getChecks()` | extra checks `fn (UserInterface $user) => ...` (throw to reject) |
| `setBadge()`, `hasBadge()`, `getBadge()` | badges |
| `getAttributes()`, `setAttribute()` | attributes copied to the token |
| `getUserIdentifier()`, `getUserLoader()`, `getUser()`, `setUser()`, `hasPassword()`, `getPassword()`, `erasePassword()` | accessors |

Without a user loader, the user is loaded by the provider of the firewall. `NeoPHP\Package\Security\Authentication\AuthenticationManager` runs the authenticators (`authenticate()`, `loadUser()`); it is used by the firewall and rarely needed directly.

## Tokens

`TokenInterface` represents the authentication of the current request:

| Method | Description |
|---|---|
| `getUser()`, `setUser()`, `getUserIdentifier()` | the user |
| `getRoleNames()` | the roles of the user |
| `getFirewall()`, `getAuthenticator()` | where and how the user logged in |
| `isAuthenticated()`, `isRemembered()` | state |
| `getAttributes()`, `getAttribute($name, $default)`, `setAttribute()` | attributes |

Implementations: `SecurityToken`, `RememberMeToken` (`isRemembered()` is `true`), `NullToken` (anonymous). `TokenStorage` holds the token of the request (`getToken()`, `setToken()`, `isInitialized()`, `reset()`).

## Authorization

| Attribute | Granted when |
|---|---|
| `ROLE_*` | the user has the role, directly or through `role_hierarchy` |
| `IS_AUTHENTICATED` | a user is logged in (also `IS_AUTHENTICATED_REMEMBERED`) |
| `IS_AUTHENTICATED_FULLY` | logged in during this session (not by the remember-me cookie) |
| `IS_REMEMBERED` | logged in by the remember-me cookie |
| `PUBLIC_ACCESS` | always |
| anything else | decided by the voters |

An array of attributes is granted when one of them is granted.

### #[IsGranted]

`#[IsGranted(string|array $attribute, string|array|null $subject = null, ?string $message = null, ?int $statusCode = null)]` on a class or a method (repeatable) runs before the controller, as a route middleware (`IsGrantedMiddleware`):

```php
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('/admin/posts/{id}')]
    #[IsGranted('ROLE_EDITOR')]
    #[IsGranted('ROLE_SUPER_ADMIN', statusCode: 404, message: 'Not found')]
    public function show(int $id): Response
    {
        $post = $this->posts->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('POST_EDIT', $post);

        return $this->render('admin/post.php', ['post' => $post]);
    }

    #[Route('/admin/sections/{section}')]
    #[IsGranted('SECTION_ACCESS', subject: 'section')]
    public function section(string $section): Response
    {
        return $this->render('admin/section.php', ['section' => $section]);
    }
}
```

`subject` is the name of a route parameter (or a list of names): the voter receives its raw value (`'news'`, `'42'`), so load entities in the controller and use `denyAccessUnlessGranted()` to vote on them. `statusCode` replaces the 403 by another status (404 hides the page) and never redirects to the login form.

### access_control

Rules are checked on every request; the first matching rule applies (`path`, `host`, `methods`, `ips`, `roles`) and the user needs one of the `roles`.

```yaml
access_control:
  - { path: ^/login, roles: PUBLIC_ACCESS }
  - { path: ^/api, methods: [POST, DELETE], roles: ROLE_API }
  - { path: ^/admin, ips: [127.0.0.1], roles: [ROLE_ADMIN, ROLE_SUPER_ADMIN] }
```

## Voters

A voter is a class of `src/` with `#[AsVoter(priority: 0)]` extending `AbstractVoter`. `make:voter Post` generates `src/Security/Voter/PostVoter.php` with `POST_VIEW`, `POST_EDIT` and `POST_DELETE`.

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Post;
use NeoPHP\Package\Security\Attribute\AsVoter;
use NeoPHP\Package\Security\Contract\AbstractVoter;
use NeoPHP\Package\Security\Contract\TokenInterface;

#[AsVoter]
class PostVoter extends AbstractVoter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'POST_EDIT' && $subject instanceof Post;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return $subject->getAuthor() === $token->getUser();
    }
}
```

A voter can also implement `VoterInterface` directly: `vote(TokenInterface $token, mixed $subject, array $attributes): int` returning `ACCESS_GRANTED` (1), `ACCESS_ABSTAIN` (0) or `ACCESS_DENIED` (-1). Voters can also be listed in `voters: [App\Security\MyVoter]`. Built-in voters: `RoleVoter` (roles, with `RoleHierarchy`) and `AuthenticatedVoter` (`IS_*`, `PUBLIC_ACCESS`).

`access_decision_manager` strategies (`AccessDecisionManager::STRATEGIES`):

| Strategy | Granted when |
|---|---|
| `affirmative` (default) | one voter grants |
| `consensus` | more grants than denials (`allow_if_equal_granted_denied` on equality) |
| `unanimous` | no voter denies |
| `priority` | the first voter that does not abstain grants |

`allow_if_all_abstain` decides when every voter abstains. `AccessDecisionManager` exposes `decide(TokenInterface $token, array $attributes, mixed $subject = null): bool`, `getVoters()`, `addVoter()`, `getStrategy()`; `RoleHierarchy` exposes `getReachableRoleNames(array $roles)` and `getMap()`.

## Helpers

| Controller | View | Result |
|---|---|---|
| `getUser()` | `app_user()` | the logged user or `null` |
| `isGranted($attribute, $subject = null)` | `is_granted($attribute, $subject = null)` | `bool` |
| `denyAccessUnlessGranted($attribute, $subject = null, $message = 'Access Denied.')` | | throws an `AccessDeniedException` (403 or entry point) |
| `getLastUsername()` | `last_username()` | last submitted username |
| `getLastAuthenticationError($clear = true)` | `last_authentication_error($clear = true)` | last login error message |
| `loginUser($user, $firewall = null, $rememberMe = false)` | | logs a user in (after a registration...) |
| `logoutUser()` | `logout_path($firewall = null)` | logs out and returns the redirection / URL of the logout (with the CSRF token when enabled, `null` without `logout`) |

```twig
{% if is_granted('ROLE_ADMIN') %}
    <a href="/admin">Admin</a>
{% endif %}
{% if app_user() %}
    {{ app_user().userIdentifier }} <a href="{{ logout_path() }}">Logout</a>
{% endif %}
```

Outside controllers, inject `NeoPHP\Package\Security\Contract\SecurityInterface` (implemented by `SecurityManager`):

| Method | Description |
|---|---|
| `isEnabled()` | a firewall or rule is configured |
| `getToken()`, `setToken()`, `getUser()` | current authentication |
| `isGranted()`, `denyAccessUnlessGranted()` | authorization |
| `getFirewall()` | the `Firewall` of the request |
| `login(UserInterface $user, ?string $firewall = null, bool $rememberMe = false)`, `logout(): Response` | log in / out |
| `getLogoutPath(?string $firewall = null)` | logout URL |
| `getLastAuthenticationError(bool $clear = true)`, `getLastUsername()` | login form state (session keys `LAST_ERROR`, `LAST_USERNAME`) |
| `handleRequest(Request $request)`, `handleException(Request $request, Throwable $exception)` | called by the kernel |

## Events

In `NeoPHP\Package\Security\Event` (see the Events documentation):

| Event | When | Methods |
|---|---|---|
| `LoginSuccessEvent` | after a successful authentication | `getToken()`, `getUser()`, `getFirewall()`, `getRequest()`, `getAuthenticator()`, `getResponse()`, `setResponse()` |
| `LoginFailureEvent` | after a failed authentication | `getException()`, `getFirewall()`, `getRequest()`, `getAuthenticator()`, `getResponse()`, `setResponse()` |
| `LogoutEvent` | on logout | `getToken()`, `getFirewall()`, `getRequest()`, `getResponse()`, `setResponse()` |

```php
#[AsListener(LoginSuccessEvent::class)]
public function onLogin(LoginSuccessEvent $event): void
{
    $this->logger->info('Login of ' . $event->getToken()->getUserIdentifier());
}
```

## Exceptions

In `NeoPHP\Package\Security\Exception`, all extending `SecurityException`:

| Exception | Description |
|---|---|
| `AccessDeniedException` | access denied (`getAttributes()`, `getSubject()`) |
| `AuthenticationException` | authentication failure; `getSafeMessage()` is the message shown to the user, translated through the `security` domain of the Translation package (`translations/security.{locale}.yaml` or `.xlf`, the key is the English message: `Invalid credentials.`, `Invalid CSRF token.`, `Too many failed login attempts, please try again in {minutes} minute(s).`, `An authentication exception occurred.`) |
| `BadCredentialsException` | invalid credentials |
| `UserNotFoundException` | unknown user (shown as `Invalid credentials.`) |
| `InvalidCsrfTokenException` | invalid CSRF token |
| `TooManyLoginAttemptsException` | login throttled (`getRetryAfter()`, `Retry-After` header) |
| `CustomUserMessageAuthenticationException` | its message is shown as is: `new CustomUserMessageAuthenticationException(string $message, array $context = [])` |

## Commands

| Command | Description |
|---|---|
| `make:user [User] [-p email]` | generates a user entity and its repository |
| `make:auth [SecurityController] [--twig]` | generates a login controller and its template (and the base layout when missing) |
| `make:voter Post` | generates `src/Security/Voter/PostVoter.php` (the subject is the entity of the same name when it exists) |
| `security:hash-password [password] [user-class]` | hashes a password with the configured hasher (asked without echo when omitted) |

The `make:*` commands never overwrite a file without `--force`. See the Console documentation.

## Profiler

When the WebProfiler package is enabled (`web_profiler.config` → `enabled: true`), `SecurityProvider` attaches a `SecurityTrace` to the `AccessDecisionManager`; otherwise nothing is recorded and `decide()` costs a single null check. The `Helper/Profiler/SecurityProfiler.php` element (auto-discovered, priority 70) then shows:

- **Toolbar** (`user` icon): user identifier or `n/a`; green when authenticated, orange when an access was denied during the request. Details: user, roles, firewall, token class, decisions count, logout path.
- **Panel**: metrics (authenticated, reachable roles, decisions, denied), user & token, and tabs **Roles** (direct / inherited through `role_hierarchy`), **Firewall** (config summary: pattern, provider, stateless, authenticators, entry point, login / logout paths), **Access decisions** (every `isGranted()` / `denyAccessUnlessGranted()` / `#[IsGranted]` / `access_control` check with attributes, subject, result, strategy and each voter vote), **Access control** (rules and the one matching the request), **Events** (login success / failure, logout) and **Voters**.

Passwords, secrets and tokens are masked (`******`). Subjects are stored as a short string (`Post #12`), never as objects.

```php
$trace = $security->getAccessDecisionManager()->getTrace();
$trace?->getDecisions();
```

## Changelog

- v1.25.2 — profiler integration: `SecurityProfiler` toolbar item and panel, opt-in `SecurityTrace` on the access decision manager (decisions, voter votes, access_control, login / logout events), `AbstractSecurity::getAccessMap()`.
- v1.20.0 — Messages translated through the Translation package (domain security).
- bugfix — `UserClass::of()` resolves the real class of ORM proxies; `AuthenticationManager` and `Passport` moved to `NeoPHP\Package\Security\Authentication`.
- bugfix (after v1.17.0) — `make:auth` creates the missing base layout.
- v1.17.0 — `make:user`, `make:auth` and `make:voter` ask for their values.
- v1.15.0 — commands rewritten on the new Console API.
- v1.13.0 — Security package: firewalls with login form, HTTP Basic, access tokens, custom authenticators and remember-me, entity / memory / chain / custom providers, password hashers with automatic rehash, login throttling, user checkers, logout, role hierarchy, voters, `#[IsGranted]`, `access_control`, decision strategies, controller and view helpers, login events, `make:user`, `make:auth`, `make:voter`, `security:hash-password`.