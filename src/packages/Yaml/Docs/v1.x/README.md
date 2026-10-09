# Yaml

The Yaml package is a dependency-free YAML parser used for the configuration, `routes.yaml` and `services.yaml`.
It supports the YAML subset needed by configuration files and reports errors with the line, the file and a snippet.

## Summary

- [Usage](#usage)
- [Supported syntax](#supported-syntax)
- [Scalars](#scalars)
- [Errors](#errors)
- [API](#api)
- [Changelog](#changelog)

## Usage

```php
<?php

declare(strict_types=1);

use NeoPHP\Package\Yaml\YamlManager;

$yaml = new YamlManager();

$config = $yaml->parseFile(__DIR__ . '/config/framework/app.yaml');
$data = $yaml->parse("name: NeoPHP\ntags: [php, yaml]");
```

In the application, inject `NeoPHP\Package\Yaml\Contract\YamlInterface`.

## Supported syntax

```yaml
---
app:
  name: 'My application'
  debug: true
  ports: [80, 443]
  mail: { host: localhost, port: 25 }
  admins:
    - alice
    - bob
  users:
    - name: alice
      roles: [ROLE_ADMIN]
  motd: |
    Welcome
    to NeoPHP
  summary: >
    Folded
    text
  empty: ~
```

| Feature | Support |
|---|---|
| mappings, nested by indentation | yes |
| sequences (`- item`), also at the same indentation as their key | yes |
| flow collections (`[a, b]`, `{a: 1}`) | yes |
| single and double quoted strings (`'it''s'`, `"a\nb"`) | yes |
| block scalars `\|` and `>` with chomping (`\|-`, `\|+`) | yes |
| comments (`#`) | yes |
| document marker `---` at the start | yes |
| anchors, aliases, tags, several documents | no |

## Scalars

| Value | PHP result |
|---|---|
| `~`, `null` | `null` |
| `true`, `false` | `bool` |
| `42`, `-7`, `0x1F`, `0o17` | `int` (a too large integer becomes a `float`) |
| `3.14`, `1e3`, `.5` | `float` |
| `.inf`, `-.inf`, `.nan` | `INF`, `-INF`, `NAN` |
| anything else, or quoted | `string` |

## Errors

A syntax error throws a `ParseException`:

```
Unexpected indentation at line 4 in "config/framework/app.yaml" (near "  debug: true").
```

```php
<?php

declare(strict_types=1);

use NeoPHP\Package\Yaml\Exception\ParseException;
use NeoPHP\Package\Yaml\YamlManager;

try {
    $data = (new YamlManager())->parseFile('config/routes.yaml');
} catch (ParseException $exception) {
    $line = $exception->getParsedLine();
    $file = $exception->getParsedFile();
}
```

## API

### YamlInterface

`NeoPHP\Package\Yaml\Contract\YamlInterface`, implemented by `YamlManager` (extends `AbstractYaml`):

| Method | Description |
|---|---|
| `parse(string $input): mixed` | parses a YAML string (an empty input returns `null`) |
| `parseFile(string $file): mixed` | parses a file; a missing or unreadable file throws a `ParseException` |

### ParseException

`NeoPHP\Package\Yaml\Exception\ParseException(string $message = '', int $parsedLine = 0, string $snippet = '', ?string $parsedFile = null)`:

| Method | Description |
|---|---|
| `getParsedLine(): int` | line of the error (`0` when unknown) |
| `getSnippet(): string` | content near the error |
| `getParsedFile(): ?string` | parsed file |
| `withFile(string $file): static` | copy of the exception with the file |

### Other classes

| Class | Description |
|---|---|
| `Parser\Parser` | `parse(string $input): mixed`, the parser used by `YamlManager` |
| `Provider\YamlProvider` | registers `YamlInterface` |

## Changelog

- v1.0.0 — YAML parser: mappings, sequences, flow collections, quoted strings, scalars, block scalars and comments.