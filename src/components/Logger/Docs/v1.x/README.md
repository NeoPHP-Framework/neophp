# Logger

PSR-3 compatible logger (same methods and signatures), without dependency.
Messages are written in one file per channel, with a minimum level, rotation by size or period, and optional zip / gz archives of the rotated files.

## Summary

- [Usage](#usage)
- [Levels](#levels)
- [Message format](#message-format)
- [Configuration](#configuration)
- [Rotation and archives](#rotation-and-archives)
- [API](#api)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Usage

`LoggerInterface` writes in the default channel. `LoggerManagerInterface` (or `LoggerManager`) gives access to every channel.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\Contract\LoggerManagerInterface;
use RuntimeException;

class PaymentController extends AbstractController
{
    public function __construct(
        protected LoggerInterface $logger,
        protected LoggerManagerInterface $loggers,
    ) {
    }

    public function pay(): Response
    {
        $this->logger->info('User {user} logged in', ['user' => 'neo']);
        $this->logger->error('Payment failed', ['exception' => new RuntimeException('Card declined')]);

        $this->loggers->channel('framework')->warning('Cache cleared');

        return $this->render('payment/done');
    }
}
```

Methods: `emergency()`, `alert()`, `critical()`, `error()`, `warning()`, `notice()`, `info()`, `debug()`, and `log($level, $message, $context)`. Each one takes a `string|Stringable` message and a context array.

Uncaught errors (HTTP 500) are written in the `framework` channel when it exists.

## Levels

`NeoPHP\Component\Logger\LogLevel` holds the level constants, from the lowest to the highest severity:

| Constant | Value | Severity |
|---|---|---|
| `LogLevel::DEBUG` | `debug` | 100 |
| `LogLevel::INFO` | `info` | 200 |
| `LogLevel::NOTICE` | `notice` | 250 |
| `LogLevel::WARNING` | `warning` | 300 |
| `LogLevel::ERROR` | `error` | 400 |
| `LogLevel::CRITICAL` | `critical` | 500 |
| `LogLevel::ALERT` | `alert` | 550 |
| `LogLevel::EMERGENCY` | `emergency` | 600 |

`LogLevel::normalize(mixed $level): string` lowercases a level and throws a `LoggerException` for an unknown one; `LogLevel::severity(string $level): int` returns its severity. A channel writes only the messages whose level is at least its minimum level.

## Message format

`{key}` placeholders in the message are replaced by the context values (scalars, `null`, booleans, `Stringable` and dates). The whole context is written as JSON; exceptions are converted into their class, message, code, file and line.

```
[2026-09-24 14:05:12] app.INFO User neo logged in {"user":"neo"}
```

The line is built from `settings.format_message`:

| Placeholder | Value |
|---|---|
| `%datetime%` | date, formatted with `settings.date_format` |
| `%channel%` | channel name |
| `%type%` / `%level%` | level, uppercase |
| `%message%` | interpolated message |
| `%context%` | context as JSON (empty without context) |

Line breaks are replaced by spaces: one message is always one line.

## Configuration

`config/framework/logger.yaml`

```yaml
channels:
  app:
    enabled: true
    extension: log
  framework:
    enabled: true
    extension: log
    minimum_level: warning

rotation:
  enabled: true
  max_files: 30
  when:
    filesize: 10M
    every: day

archive:
  enabled: true
  extension: zip

settings:
  path: '%kernel.root_path%/var/log'
  format_message: '[%datetime%] %channel%.%type% %message% %context%'
  date_format: 'Y-m-d H:i:s'
  timezone: Europe/Paris
  minimum_level: debug
  default_channel: app
```

| Option | Description |
|---|---|
| `channels.<name>.enabled` | writes the channel into `<path>/<name>.<extension>` (default `true`) |
| `channels.<name>.extension` | file extension (`log`, `txt`...), default `log` |
| `channels.<name>.minimum_level` | overrides `settings.minimum_level` for the channel |
| `channels.<name>.path` | overrides `settings.path` for the channel |
| `rotation.enabled` | enables the rotation |
| `rotation.when.filesize` | rotates when the file exceeds a size (`500K`, `10M`, `1G`, bytes, `~` = never) |
| `rotation.when.every` | rotates every `minute`, `hour`, `day`, `week`, `month`, `year` (`~` = never) |
| `rotation.max_files` | keeps only the N most recent rotated files |
| `archive.enabled` / `archive.extension` | compresses rotated files as `zip` (PHP `zip` extension) or `gz` (PHP `zlib` extension) |
| `settings.path` | directory of the log files (default `var/log`) |
| `settings.format_message` | line format (default `[%datetime%] %channel%.%level% %message% %context%`) |
| `settings.date_format` | PHP date format of `%datetime%` (default `Y-m-d H:i:s`) |
| `settings.timezone` | timezone of the dates (`~` = PHP `date.timezone`) |
| `settings.minimum_level` | lowest level written (default `debug`) |
| `settings.default_channel` | channel used by `LoggerInterface` (default: first channel) |

Without `channels`, a single `app` channel is created. A disabled channel exists but writes nothing, so `channel('name')` keeps working.

## Rotation and archives

Rotated files are named after the period (`app-2026-09-23.log`) or the rotation time (`app-2026-09-24_14-05-12.log`). With `archive.enabled`, each rotated file is compressed (`app-2026-09-23.log.zip`) and the original is removed. `rotation.max_files` removes the oldest rotated files.

## API

### LoggerManagerInterface

`NeoPHP\Component\Logger\Contract\LoggerManagerInterface` extends `LoggerInterface`; its log methods write in the default channel.

| Method | Description |
|---|---|
| `channel(string $name): LoggerInterface` | a channel; throws a `LoggerException` for an unknown channel |
| `hasChannel(string $name): bool` | whether the channel is defined |
| `getChannels(): array` | name => `Channel` |
| `getDefaultChannel(): string` | name of the default channel |

`LoggerManager` also provides `addChannel(Channel $channel): static` and `LoggerManager::fromConfig(array $config, string $defaultPath): static`, which builds the manager from the configuration above.

Container services: `LoggerManagerInterface`, with the aliases `LoggerManager` and `LoggerInterface`.

### Channel

`NeoPHP\Component\Logger\Channel\Channel` is a `LoggerInterface` writing in one file:

| Method | Description |
|---|---|
| `getName(): string` | channel name |
| `isEnabled(): bool` | enabled and with a writer |
| `getFile(): ?string` | log file |
| `isHandling(string $level): bool` | whether a message of this level is written |

### Custom logger

`AbstractLogger` implements the eight level methods on top of `log()`:

```php
<?php

declare(strict_types=1);

namespace App\Logger;

use NeoPHP\Component\Logger\Contract\AbstractLogger;
use Stringable;

class MemoryLogger extends AbstractLogger
{
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
```

### Building blocks

| Class | Description |
|---|---|
| `Formatter\LineFormatter` | `__construct(string $format, string $dateFormat)`, `format($channel, $level, $message, $context, $datetime): string`, `interpolate(string $message, array $context): string` |
| `Writer\FileWriter` | `__construct($file, $rotation, $maxSize, $every, $maxFiles, $archiver)`, `write()`, `rotateIfNeeded()`, `rotate()`, `getFile()`, `FileWriter::parseSize(int\|string\|null): ?int`, `PERIODS` |
| `Writer\Archiver` | `__construct(string $format = 'zip')`, `archive(string $file): string`, `getFormat()`, `FORMATS` |

## Exceptions

`NeoPHP\Component\Logger\Exception\LoggerException` extends `FrameworkException`. It is thrown for an unknown level, channel, rotation period, archive extension or timezone, an empty `channels` list, a missing PHP extension, or a file that cannot be written.

## Changelog

- v1.1.0 — PSR-3 compatible logger, channels, rotation by size or period, zip / gz archives, `config/framework/logger.yaml`.