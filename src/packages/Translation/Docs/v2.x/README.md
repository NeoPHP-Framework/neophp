# Translation

The Translation package (`src/packages/Translation`) translates the application messages from YAML and XLIFF 1.2 files without any dependency (no `intl` needed).
It supports parameters, ICU-lite plurals and selects, fallback locales, locale detection (route, query, session, cookie, `Accept-Language`), view helpers, a controller trait, and translates the validation and security messages.

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Translation files](#translation-files)
- [Parameters, plurals and selects](#parameters-plurals-and-selects)
- [Templates](#templates)
- [Controllers](#controllers)
- [Locale detection](#locale-detection)
- [Validation, forms and security](#validation-forms-and-security)
- [Commands](#commands)
- [Cache](#cache)
- [TranslationManagerInterface](#translationmanagerinterface)
- [Profiler](#profiler)
- [Limitations](#limitations)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Package\Translation\TranslationManager` (`final`) |
| Interface | `NeoPHP\Package\Translation\TranslationManagerInterface` |
| Attribute | `#[Package(provider: TranslationProvider::class)]` |
| Requires | nothing |

Inject `TranslationManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Package\Translation\TranslationManagerInterface;

public function __construct(private TranslationManagerInterface $translation)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Package\Translation\TranslationManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Quick start

`config/packages/translation.yaml`:

```yaml
default_locale: en
locales: [en, fr]
```

`translations/messages.fr.yaml`:

```yaml
home:
    title: Bienvenue
    hello: "Bonjour {name} !"
    posts: "{count, plural, =0 {Aucun article} one {# article} other {# articles}}"
```

```twig
<h1>{{ 'home.title'|trans }}</h1>
<p>{{ translate('home.hello', {name: app_user().name}) }}</p>
<p>{{ translate('home.posts', {count: posts|length}) }}</p>
```

`/?lang=fr` shows the French page; a missing key is returned as written (after the parameters are replaced), so `translate('Welcome')` works before any translation exists.

## Configuration

`config/packages/translation.yaml` (`packages.translation`), every key is optional:

```yaml
default_locale: en
locales: [en, fr]
fallbacks: [en]
path: '%kernel.root_path%/translations'
default_domain: messages
format: yaml
detection:
    order: [route, query, session, cookie, header]
    query_parameter: lang
    session_key: _locale
    cookie_name: locale
    cookie_lifetime: 31536000
cache: true
extract:
    paths: [templates, src]
```

| Key | Default | Description |
|---|---|---|
| `default_locale` | `en` | locale used when nothing is detected |
| `locales` | `[default_locale]` | enabled locales (the default locale is always enabled); `fr-FR` is normalized into `fr_FR` |
| `fallbacks` | `[default_locale]` | locales tried when a key is missing, after the parent locales (`fr_CA` → `fr` → fallbacks) |
| `path` | `translations` | directory of the application files (relative to the project) |
| `default_domain` | `messages` | domain used when none is given |
| `format` | `yaml` | format of the files created by `translation:generate`: `yaml` or `xliff` |
| `detection.order` | `[route, query, session, cookie, header]` | locale sources, in order (remove a source to disable it) |
| `detection.query_parameter` | `lang` | query parameter (`?lang=fr`) |
| `detection.session_key` | `_locale` | session key written by `switchLocale()` |
| `detection.cookie_name` | `locale` | cookie written by `switchLocale()` (empty: no cookie) |
| `detection.cookie_lifetime` | `31536000` | lifetime of this cookie, in seconds |
| `cache` | `true` | compile the catalogues into `var/cache/translation/` |
| `extract.paths` | `[templates, src]` | directories scanned by `translation:generate` and `translation:debug` |

Without a configuration file, the only locale is `en` and every key is returned as written: nothing changes for an application which does not translate.

## Translation files

The files are named `{domain}.{locale}.{yaml|yml|xlf|xliff}` in `translations/`: `messages.fr.yaml`, `admin.de.xlf`, `validators.fr_CA.yaml`.

YAML: nested keys are flattened with dots (`home.title`). A key can also be the English sentence itself:

```yaml
home:
    title: Bienvenue
"Read more": "Lire la suite"
```

XLIFF 1.2 (the key is the `resname` attribute, or the `source` when there is none; requires the `dom` extension):

```xml
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="fr" datatype="plaintext" original="messages">
        <body>
            <trans-unit id="1" resname="home.title">
                <source>home.title</source>
                <target>Bienvenue</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

An empty message (`""` or an empty `<target>`) is considered untranslated: the fallback locales are tried, then the key is returned.

Priority, from the lowest: the files added with `addResource()` and the files of the NeoPHP packages, then the application files of `translations/`.

The `translations/` directory of a NeoPHP package installed with Composer (`extra.neophp.translations`, see the Package documentation) is loaded automatically when the package is enabled: `translations/billing.fr.yaml` of the package gives the domain `billing`, and the same key in `translations/billing.fr.yaml` of the project replaces it. The framework ships no translation file. A bundle can register its own files or messages in a provider:

```php
$translator->addResource(__DIR__ . '/../Resources/translations/shop.fr.yaml');
$translator->addMessages(['cart' => ['empty' => 'Panier vide']], 'fr', 'shop');
```

## Parameters, plurals and selects

```php
$translator->translate('Hello %name%!', ['name' => 'Ann']);
$translator->translate('Hello {name}!', ['name' => 'Ann']);
$translator->translate('home.posts', ['count' => 3]);
$translator->translate('title', [], 'admin', 'de');
```

ICU-lite syntax (without the `intl` extension, the result is identical everywhere):

```yaml
items: "{count, plural, =0 {No item} one {# item} other {# items}}"
liked: "{gender, select, male {He} female {She} other {They}} liked {count, plural, one {# post} other {# posts}}"
guests: "{n, plural, offset:1 =0 {nobody} =1 {you} one {you and # other} other {you and # others}}"
price: "{amount, number} €"
```

- `plural`: `=N` exact values first, then the category of the locale (`zero`, `one`, `two`, `few`, `many`, `other`); `other` is required; `#` is the number (minus `offset`)
- `select`: the value of the parameter, or `other` (required)
- arguments can be nested; `{name}` with an unknown parameter stays as written
- plural rules: `en`, `de`, `nl`, `es`, `it`, `sv`... (1 = one), `fr`, `pt` (0 and 1 = one), `ru`, `uk`, `be`, `sr`, `hr` and `pl` (one / few / many), `cs`, `sk`, `lt`, `ro`, `ga`, `he` (two), `ar` (zero / one / two / few / many), `ja`, `zh`, `ko`, `th`, `vi`, `id` (other only)

The messages without parameters are returned as written: `{{ limit }}` placeholders of the validation messages are replaced by the Validator.

## Templates

| Helper | Type | Description |
|---|---|---|
| `translate(key, params = {}, domain = null, locale = null)` | function | translated message |
| `trans(params = {}, domain = null, locale = null)` | filter | same, for `'key'\|trans` |
| `locale()` | function | current locale |
| `locales()` | function | enabled locales |

```twig
<html lang="{{ locale()|replace({'_': '-'}) }}">
<h1>{{ 'home.title'|trans }}</h1>
<p>{{ translate('cart.items', {count: cart.count}, 'shop') }}</p>
{% for code in locales() %}
    <a href="{{ path('locale_switch', {locale: code}) }}">{{ code|upper }}</a>
{% endfor %}
```

PHP templates:

```php
<h1><?= $this->e($this->translate('home.title')) ?></h1>
<p><?= $this->e($this->filter('trans', 'home.hello', ['name' => $name])) ?></p>
```

The output is escaped like any other value.

## Controllers

`AbstractController` uses the `TranslationController` trait:

| Method | Description |
|---|---|
| `translate(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string` | translated message |
| `switchLocale(string $locale, bool $remember = true): string` | changes the locale of the request and, with `$remember`, stores it in the session and in the cookie; returns the enabled locale (`fr-FR` → `fr` when only `fr` is enabled); throws a `TranslationException` when the locale is not enabled |

```php
#[Route('/locale/{locale}', name: 'locale_switch', methods: ['GET'])]
public function switchTo(string $locale, Request $request): Response
{
    $this->switchLocale($locale);
    $this->addFlash('success', $this->translate('locale.changed'));

    return $this->redirect($request->headers->get('Referer') ?? '/');
}
```

Do not name an action `translate()` or `switchLocale()`: they are methods of `AbstractController`. Elsewhere, inject `NeoPHP\Package\Translation\TranslationManagerInterface` (autowired, also `translator` in the container).

## Locale detection

`NeoPHP\Package\Translation\Helper\Event\LocaleListener` detects the locale in the order of `detection.order`; the first enabled locale wins, then `default_locale`:

| Source | Read from |
|---|---|
| `route` | the `{_locale}` parameter of the matched route (`/{_locale}/blog`, add `requirements: { _locale: 'en\|fr' }`) |
| `query` | `?lang=fr` |
| `session` | the `_locale` session key (only read when the session cookie exists: no session is started for visitors) |
| `cookie` | the `locale` cookie |
| `header` | `Accept-Language`, sorted by `q` (`fr-CH, fr;q=0.9, en;q=0.8`); `fr-CH` matches `fr` when only `fr` is enabled, and `fr` matches `fr_FR` |

The route parameters only exist after the routing, so the listener runs twice: on `RequestEvent` (priority 64, before the Security listener: login errors are translated) with every source except `route`, then on `ControllerEvent` (priority 64) with every source, route included. The detected locale is set on the translator and in the request attributes `_locale` and `_locale_source` (`route`, `query`, `session`, `cookie`, `header` or `default`). With a single enabled locale, the detection is skipped.

A value which is not an enabled locale is ignored (the next source is tried).

## Validation, forms and security

When a message is translated in the current locale, the framework uses it; otherwise the original English message is kept:

- Validator: every violation message goes through the `validators` domain (the key is the English message with its `{{ placeholders }}`), then the placeholders are replaced
- Form: labels, helps, placeholders and choice labels go through the `translation_domain` option of the field (inherited from the parent, `messages` by default, `false` disables it); errors go through `validators`
- Security: `AuthenticationException::getSafeMessage()` (login errors, `last_authentication_error()`, JSON errors) goes through the `security` domain, with the exception parameters (`{minutes}`)

The framework ships no translation file: its messages are written in English and used as keys. To translate or reword them, create `translations/validators.{locale}.yaml|xlf` and `translations/security.{locale}.yaml|xlf` in the application, with only the messages you need (the others stay in English).

`translations/validators.fr.yaml`:

```yaml
"This value should not be blank.": "Cette valeur ne doit pas être vide."
"This value is not a valid email address.": "Cette valeur n'est pas une adresse email valide."
"This value is too short. It should have {{ limit }} character(s) or more.": "Cette chaîne est trop courte. Elle doit avoir au minimum {{ limit }} caractère(s)."
"The username {{ value }} is already used.": "Le nom {{ value }} est déjà utilisé."
"The CSRF token is invalid. Please try to resubmit the form.": "Le jeton CSRF est invalide. Veuillez renvoyer le formulaire."
```

`translations/security.fr.xlf`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en" target-language="fr" datatype="plaintext" original="security">
    <body>
      <trans-unit id="1" resname="Invalid credentials.">
        <source>Invalid credentials.</source>
        <target>Identifiants invalides.</target>
      </trans-unit>
      <trans-unit id="2" resname="Too many failed login attempts, please try again in {minutes} minute(s).">
        <source>Too many failed login attempts, please try again in {minutes} minute(s).</source>
        <target>Trop de tentatives de connexion, réessayez dans {minutes} minute(s).</target>
      </trans-unit>
    </body>
  </file>
</xliff>
```

The keys of the `validators` domain are the default messages of the constraints (listed in the Validator documentation) and `The CSRF token is invalid. Please try to resubmit the form.` (Form). The keys of the `security` domain are `Invalid credentials.`, `Invalid CSRF token.`, `Too many failed login attempts, please try again in {minutes} minute(s).` and `An authentication exception occurred.`, plus the safe message of a custom `AuthenticationException`.

The login error is translated when the login fails: it is stored in the session in the locale of the login request.

## Commands

| Command | Description |
|---|---|
| `translation:generate [--locale=fr]... [--domain=] [--format=yaml\|xliff] [--dry-run] [--clean] [--package=]` | extracts the keys and adds the missing ones to the files |
| `translation:debug [locale] [--domain=] [--only-missing] [--only-unused]` | table of the keys with their state per locale |
| `translation:lint` | checks that every file can be parsed and that every message has a valid syntax (exit code 1 on error) |

`translation:generate` scans `extract.paths` for literal keys: `translate('key')` and `'key'|trans` in Twig, `$this->translate('key')` in PHP templates, `->translate('key')` and `translate('key')` in PHP code; the domain is read from the third argument of `translate()` (second of `trans()`) or a named `domain:` argument when it is a literal. For each locale (all enabled ones by default) and domain, the missing keys are added to `translations/{domain}.{locale}.{ext}` (created when needed): the value is the key for the default locale and `""` for the others. Existing translations are kept, an existing file keeps its format and `--clean` removes the keys that are not found in the code (only in the domains found in the code). `--dry-run` lists the changes without writing.

`--package=billing` scans `templates/` and `src/` of the NeoPHP package instead and writes its `translations/` directory: use it while developing a package. The templates of a package overridden in `templates/packages/<name>/` of the project are scanned like the other templates of the project.

```bash
php bin/neo translation:generate --dry-run
php bin/neo translation:generate --locale=fr --format=xliff
php bin/neo translation:generate --package=billing
php bin/neo translation:debug fr --only-missing
php bin/neo translation:lint
```

`translation:debug` states: `translated` (the locale translates it), `fallback` (a fallback locale does), `missing` (nobody does), `unused` (in a file but not found in the code). The keys of the `validators` and `security` domains are never `unused`: the framework uses them.

## Cache

The catalogue of each locale is compiled into `var/cache/translation/{locale}.php`. In debug, it is rebuilt when a translation file changes or a file is added; in production, run `php bin/neo cache:clear` after a deployment (`translation:generate` clears it). `cache: false` loads the files at each request.

## TranslationManagerInterface

| Method | Description |
|---|---|
| `translate(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string` | translated message (the key when missing) |
| `has(string $key, ?string $domain = null, ?string $locale = null): bool` | the key is translated in the locale or a fallback |
| `getLocale()` / `setLocale(string $locale)` | current locale (`setLocale()` throws for a disabled locale) |
| `getDefaultLocale()`, `getLocales()`, `getDefaultDomain()` | configuration |
| `getFallbackLocales(string $locale): array` | `fr_CA` → `['fr', 'en']` |
| `matchLocale(string $locale): ?string` | enabled locale matching a locale or a language, or `null` |
| `getCatalogue(string $locale, ?string $domain = null): array` | flat messages of a locale and a domain |
| `getDomains(?string $locale = null): array` | known domains |
| `addResource(string $file, ?string $locale = null, ?string $domain = null)` | adds a file (bundles) |
| `addMessages(array $messages, string $locale, ?string $domain = null)` | adds messages at runtime (highest priority) |
| `getResources(?string $locale = null): array` | loaded files (`file`, `domain`, `locale`, `format`, `priority`) |
| `loadFile(string $file): array` | flat messages of a file |
| `getFormatter(): MessageFormatter` | message formatter (`format()`, `validate()`) |
| `clearCache()` | removes the compiled catalogues |

Other classes: `TranslationManager` (implementation), `Locale\LocaleDetector` (`detect()`, `fromHeader()`), `Loader\YamlLoader`, `Loader\XliffLoader`, `Dumper\YamlDumper`, `Dumper\XliffDumper`, `Extractor\TranslationExtractor`, `Formatter\MessageFormatter`, `Formatter\PluralRules`. Errors throw `NeoPHP\Package\Translation\Exception\TranslationException`.

## Profiler

When the WebProfiler package is enabled (`web_profiler.config` → `enabled: true`), `TranslationProvider` attaches a `TranslationTrace` to the translator; otherwise nothing is recorded and `translate()` costs a single null check. The `Helper/WebProfiler/TranslationProfiler.php` element (auto-discovered, priority 60) then shows:

- **Toolbar** (`info` icon): current locale; blue when a message came from a fallback locale, orange when a message is missing. Details: locale, fallbacks, messages, defined / fallback / missing counts.
- **Panel**: metrics (locale, defined, fallback, missing), a hint when keys are missing (`php bin/neo translation:generate` adds them), and tabs **Missing**, **Fallback** (requested and resolved locale), **Defined** (locale, domain, id, count, result, parameters), **Locale** (current, default, fallbacks, enabled, detection source: route, query, session, cookie, header or default, detection order) and **Catalogues** (loaded locale / domain, message count, source files).

Each (id, domain, locale) is stored once with an occurrence count, up to 1000 unique messages. Results and parameter values are truncated to 120 characters; objects are stored as their type.

```php
$trace = $translator->getTrace();
$trace?->getMessages(TranslationTrace::STATE_MISSING);
```

## Limitations

- ICU-lite: `plural` (with `offset`), `select` and `number` (no number or date styles, no `selectordinal`, no apostrophe quoting)
- the extractor only finds literal keys (not `translate($key)`)
- the file cache relies on modification times (one second resolution)

## Changelog

- v2.1.0 — Translations of the NeoPHP packages loaded automatically (overridden by the project), `translation:generate --package`.
- v2.0.0 — `TranslationManager` is the `final` entry point of the module, declared with `#[Package]`; `TranslationManagerInterface` replaces `Contract\TranslatorInterface`; `Contract\AbstractTranslator` is merged into the manager; `Helper/Profiler` is renamed `Helper/WebProfiler`; `Helper/Listener` is renamed `Helper/Event`; `LocaleDetector` moves to `Locale\LocaleDetector`; the internal classes are marked `@internal`.
- v1.31.3 — `translation:generate` indents the YAML files with 4 spaces, like the other YAML files of the framework.
- v1.25.3 — profiler integration: `TranslationProfiler` toolbar item and panel, opt-in `TranslationTrace` on the translator (defined / fallback / missing messages with counts, parameters and results), locale detection source, loaded catalogues, `AbstractTranslator::setTrace()` / `getTrace()` / `getLoadedCatalogues()`.
- v1.20.0 — Translation package: YAML / XLIFF 1.2 catalogues, fallbacks, ICU-lite plurals and selects, locale detection (route, query, session, cookie, `Accept-Language`), `translate()` / `trans` / `locale()` / `locales()` helpers, `translate()` and `switchLocale()` in controllers, translated validation, form and security messages, `translation:generate`, `translation:debug`, `translation:lint`.