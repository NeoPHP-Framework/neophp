# Doctor

The Doctor process (`src/process/Doctor`) checks a project in one command and tells what to fix: the version and the extensions of PHP, the writable directories, the environment variables, the database, the migrations, the SMTP server, the Mercure hub, the queue...

```bash
php bin/neo doctor
```

Each enabled module brings its own checks, and the application can add its own.

## Summary

- [Module](#module)
- [Running the checks](#running-the-checks)
- [Results](#results)
- [Checks of the framework](#checks-of-the-framework)
- [Writing a check](#writing-a-check)
- [In production and in CI](#in-production-and-in-ci)
- [DoctorManagerInterface](#doctormanagerinterface)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Process\Doctor\DoctorManager` (`final`) |
| Interface | `NeoPHP\Process\Doctor\DoctorManagerInterface` |
| Attribute | `#[Process(provider: DoctorProvider::class)]` |
| Requires | nothing; the checks of a module only run when the module is enabled |

Inject `DoctorManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Process\Doctor\DoctorManagerInterface;

public function __construct(private DoctorManagerInterface $doctor)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Process\Doctor\DoctorManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\CheckInterface`, the classes of `Model\` and the exception. The classes marked `@internal` are used by the framework only.

## Running the checks

```
$ php bin/neo doctor

 Environment dev, debug on

 PHP
   OK       Version             8.3.12
   INFO     php.ini             /etc/php/8.3/cli/php.ini
                                -> The web server can use another php.ini than the command line: check it too (phpinfo()).

 Application
   OK       Environment         dev, debug on
   OK       APP_SECRET          set
   OK       APP_URL             http://127.0.0.1:8000
   OK       var/cache           writable
   OK       var/log             writable
   OK       Sessions            writable

 Database
   ERROR    Connection default  Unable to connect to the "mysql" database: SQLSTATE[HY000] [2002] Connection refused
                                -> Check that the server is running, the credentials, and that the database exists (php bin/neo database:create).

 Migrations
   WARNING  Migrations          2 of 5 not executed
                                -> Run php bin/neo migration:migrate (php bin/neo migration:status lists them).

 [ERROR] 1 error(s), 1 warning(s), 7 OK, 1 info.
```

| Option | Description |
|---|---|
| `--check` / `-c` | only this check, by name (case insensitive, repeatable): `-c database -c migrations` |
| `--format` | `text` (default) or `json` |
| `--strict` | the warnings fail too |
| `--env` | the environment of the checks (global option of the console): `--env=prod` |

The command returns `1` when an error is found (or a warning with `--strict`), `0` otherwise. An unknown name in `--check` lists the existing checks.

`--format=json` prints the environment, the debug mode, `success`, the number of results by status (`summary`) and the results by check (`checks`), for a monitoring script or a CI job.

The checks only read: they never change the configuration nor the data. Two exceptions: a database transport of the queue creates its tables when `auto_setup` is true (as on its first use), and the SMTP server and the Mercure hub receive a connection (no email is sent, nothing is published).

## Results

Every checked point gives a `NeoPHP\Process\Doctor\Model\CheckResult`:

| Status | Meaning |
|---|---|
| `ok` | fine |
| `info` | for your information (a feature not configured, an advice) |
| `warning` | should be fixed (pending migrations, failed messages, missing service worker) |
| `error` | something does not work or is dangerous (missing extension, database unreachable, debug in production) |

A result has a label (what was checked), a message (what was found) and a fix (what to do), shown under the line with `->`.

A check that cannot be built (a missing service) or that throws is reported as an `error` of its group; the other checks still run.

## Checks of the framework

| Check | Module | Points |
|---|---|---|
| `PHP` | Kernel | version (8.2 or later), required extensions (`ctype`, `json`, `mbstring`, `tokenizer`), `memory_limit` (128M or more), OPcache in `prod`, the `php.ini` file in use |
| `Application` | Kernel | debug mode in `prod` (error), `APP_SECRET` (empty: error, under 32 characters: warning), `APP_URL` (empty, `http://` in `prod`), `TRUSTED_HOSTS` in `prod`, `var/cache`, `var/log` and the session directory writable |
| `Database` | Database | each connection: PDO extension, connection, server version, database |
| `Mailer` | Mailer | `MAILER_DSN` valid (`null://`, `file://` and `log://` are a warning in `prod`), `openssl` for TLS, SMTP server reachable |
| `Uploads` | Upload | upload directory writable, `fileinfo`, `file_uploads`, `upload_max_filesize` and `post_max_size` |
| `Images` | Image | `gd`, the formats of the presets supported (WebP, AVIF), `exif` (advice), cache directory writable |
| `Mercure` | Mercure | `MERCURE_URL` (empty: real-time disabled), `MERCURE_JWT_SECRET` (empty, under 32 characters), hub reachable |
| `Notifications` | Notifier | VAPID keys (both or none, valid), `openssl`, `public/notifier-sw.js` |
| `Migrations` | ORM | migrations of the project and of the packages not executed |
| `QR codes` | QrCode | short codes kept by the cache pool, signed QR codes verified with `APP_SECRET`, `gd` for the PNG images (advice), public `POST /_qr/read` route (advice) |
| `Queue` | Queue | each transport reachable, waiting messages, failed messages |

The checks of a disabled module are not run. They live in the `Helper/Doctor/` folder of their module (`src/components/Database/Helper/Doctor/DatabaseCheck.php`).

`PHP` and `Application` run first; the other checks follow in the order of their class name, then the checks of the application.

## Writing a check

A class of `src/` implementing `NeoPHP\Process\Doctor\Contract\CheckInterface` is found and run by `neo doctor`: no configuration. It is built by the container (constructor autowiring).

```php
<?php

declare(strict_types=1);

namespace App\Doctor;

use App\Service\PaymentClient;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

class PaymentCheck implements CheckInterface
{
    public function __construct(private PaymentClient $client)
    {
    }

    public function getName(): string
    {
        return 'Payment';
    }

    public function run(): iterable
    {
        if (($_ENV['STRIPE_KEY'] ?? '') === '') {
            yield CheckResult::error('STRIPE_KEY', 'empty', 'Set STRIPE_KEY in .env.local.');

            return;
        }

        yield str_starts_with($_ENV['STRIPE_KEY'], 'sk_live_')
            ? CheckResult::ok('Mode', 'live')
            : CheckResult::warning('Mode', 'test key', 'Use the live key in production.');

        try {
            $this->client->ping();
            yield CheckResult::ok('API', 'reachable');
        } catch (Throwable $exception) {
            yield CheckResult::error('API', $exception->getMessage(), 'Check the network of the server.');
        }
    }
}
```

```bash
php bin/neo doctor -c payment
```

| Method | Description |
|---|---|
| `getName(): string` | title of the group, also the name given to `--check` |
| `run(): iterable` | yields the `CheckResult`; nothing when the check does not apply to the project |

`CheckResult::ok($label, $message)`, `info($label, $message, $fix)`, `warning($label, $message, $fix)` and `error($label, $message, $fix)` build the results; `new CheckResult($status, $label, $message, $fix)` throws `DoctorException` for an unknown status. A NeoPHP package (see the Package documentation) ships its checks in its own directory, found the same way.

Keep the checks fast and harmless: read, connect with a short timeout, never write data nor send a message to a real user.

## In production and in CI

Run it on the server after a deployment, with the environment of production:

```bash
php bin/neo doctor --env=prod --strict
```

Only in `prod`: debug mode, OPcache, trusted hosts, `http://` URL and the null mailer are checked. The command line can use another `php.ini` than the web server (the `php.ini` line shows the one of the command line): check the extensions and OPcache of the web server with `phpinfo()` too.

In a CI job or a deployment script, the exit code stops the pipeline:

```bash
php bin/neo doctor --env=prod --strict || exit 1
php bin/neo doctor --format=json > doctor.json
```

## DoctorManagerInterface

| Method | Description |
|---|---|
| `run(array $only = []): DoctorReport` | runs the checks (all of them, or the names of `$only`); throws `DoctorException` when a name matches no check |
| `addCheck(CheckInterface\|string $check): static` | adds a check (an instance or a class built by the container), run after the discovered ones |
| `getChecks(): array` | the checks and the classes of the checks |

`Model\DoctorReport`:

| Method | Description |
|---|---|
| `getResults(): array` | the `CheckResult` lists, by check name |
| `count(?string $status = null): int` | the number of results, of a status or all of them |
| `hasErrors(): bool`, `hasWarnings(): bool` | an error, a warning |
| `toArray(): array` | `summary` (count by status) and `checks` (results as arrays) |

The manager is also available with the `doctor` container id.

## Changelog

- v2.9.0 — `QR codes` check of the QrCode package.
- v2.8.0 — Doctor process: `php bin/neo doctor` (`--check`, `--format=json`, `--strict`, exit code), `DoctorManager` and `DoctorManagerInterface`, `Contract\CheckInterface`, `Model\CheckResult` and `Model\DoctorReport`, checks discovered in the `Helper/Doctor/` folder of the enabled modules and in the application: PHP, application, database, mailer, uploads, images, Mercure, notifications, migrations and queue.