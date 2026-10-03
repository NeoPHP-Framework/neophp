# Scheduler

The Scheduler package (`src/packages/Scheduler`) runs recurring tasks: console commands, invokable classes, closures and queue messages, each with a cron expression.
A single system cron entry calls `php bin/neo schedule:run` every minute; the package selects the due tasks, prevents overlaps, records a run history and shows it in the console and in a WebProfiler panel. No external dependency is required.

## Summary

- [Quick start](#quick-start)
- [Running the scheduler](#running-the-scheduler)
- [Defining tasks](#defining-tasks)
- [Frequencies](#frequencies)
- [Cron expressions](#cron-expressions)
- [Overlaps, conditions and timezones](#overlaps-conditions-and-timezones)
- [History](#history)
- [Configuration](#configuration)
- [Commands](#commands)
- [Profiler](#profiler)
- [Exceptions](#exceptions)
- [Limitations](#limitations)
- [Changelog](#changelog)

## Quick start

1. Schedule a command:

```php
namespace App\Command;

use NeoPHP\Package\Scheduler\Attribute\AsScheduledTask;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;

#[AsCommand(name: 'app:report', description: 'Sends the daily report')]
#[AsScheduledTask(cron: '0 2 * * *', withoutOverlapping: true)]
class ReportCommand extends AbstractConsole
{
}
```

2. Check it: `php bin/neo schedule:list`
3. Run the scheduler every minute (see below), or `php bin/neo schedule:work` in development.

## Running the scheduler

Linux / macOS, `crontab -e`:

```
* * * * * cd /path/to/project && php bin/neo schedule:run >> /dev/null 2>&1
```

Windows (PowerShell): create a Task Scheduler task that starts every minute:

```powershell
$action = New-ScheduledTaskAction -Execute "php.exe" -Argument "bin\neo schedule:run" -WorkingDirectory "C:\path\to\project"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "NeoPHP scheduler" -Action $action -Trigger $trigger
```

Development (any OS): `php bin/neo schedule:work` stays in the foreground and starts `schedule:run` in a subprocess at the beginning of every minute (Ctrl+C to stop; `--runs=N` stops after N minutes).

`schedule:run` writes a heartbeat (`var/scheduler/heartbeat`): `schedule:list` and the profiler panel warn when it did not run in the last 2 minutes.

## Defining tasks

Four sources are merged; task names must be unique.

### Attribute on a class

`#[AsScheduledTask]` on an invokable class of `src/` (built by the container, `__invoke()` arguments autowired) or on a console command (the command runs in a subprocess):

```php
namespace App\Task;

use NeoPHP\Package\Scheduler\Attribute\AsScheduledTask;

#[AsScheduledTask(cron: '*/15 * * * *', name: 'purge-tokens', timezone: 'Europe/Paris', withoutOverlapping: true)]
class PurgeTokens
{
    public function __invoke(TokenRepository $tokens): string
    {
        return sprintf('%d tokens removed', $tokens->purgeExpired());
    }
}
```

| Option | Default | Description |
|---|---|---|
| `cron` | `* * * * *` | cron expression or alias |
| `name` | command line or class name | task name |
| `timezone` | `timezone` of the configuration | timezone of the expression |
| `withoutOverlapping` | `false` | skip the run while the previous one is running |
| `overlapTtl` | `1440` | lock lifetime in minutes |
| `description` | `''` | free text |
| `arguments` | `''` | arguments of a command (`'--env=prod --force'`) |

The attribute is repeatable. Classes are discovered in `src/` and cached in `var/cache/scheduler/tasks.{env}.php`.

### Configuration

```yaml
tasks:
    - { name: 'cache-clear', command: 'cache:clear', cron: '0 3 * * *' }
    - { message: App\Message\SendReport, frequency: daily, at: '02:00', without_overlapping: true }
    - { class: App\Task\PurgeTokens, frequency: everyFifteenMinutes, timezone: Europe/Paris }
```

Keys: one of `command` (with optional `arguments`), `message` (a class built without argument, dispatched with the Queue package) or `class`; then `cron` or `frequency` (a frequency method name from the table below, with `at: 'HH:MM'` for the daily ones); optional `name`, `description`, `timezone`, `without_overlapping` (`true` or a TTL in minutes), `enabled`.

### Schedule provider

A class implementing `ScheduleProviderInterface` (discovered in `src/`, or listed in `providers:`) receives a fluent `Schedule`:

```php
namespace App\Task;

use App\Message\SendNewsletter;
use NeoPHP\Package\Scheduler\Contract\ScheduleProviderInterface;
use NeoPHP\Package\Scheduler\Schedule;

class AppSchedule implements ScheduleProviderInterface
{
    public function schedule(Schedule $schedule): void
    {
        $schedule->command('app:report --format=pdf')->dailyAt('02:00')->weekdays()->withoutOverlapping();
        $schedule->job(new SendNewsletter(42))->weeklyOn(1, '08:30')->timezone('Europe/Paris');
        $schedule->call(static fn (): string => 'ping')->everyFiveMinutes()->name('ping');
        $schedule->invoke(PurgeTokens::class)->hourly()->when(static fn (): bool => date('N') < 6);
    }
}
```

| `Schedule` method | Task type |
|---|---|
| `command(string $command, string $arguments = '')` | console command, run in a subprocess (`PHP_BINARY bin/neo <command> --no-interaction`) |
| `call(callable $callback)` | callable run in-process through the container (`container->call()`) |
| `invoke(string $class)` | invokable class built by the container |
| `job(object\|string $message)` | message dispatched with `MessageBusInterface` (Queue package) |

A callable or class returning `false` or a non zero integer is a failure; a returned string is stored as output. A command fails when its exit code is not 0.

## Frequencies

| Method | Expression |
|---|---|
| `cron('...')` | any expression or alias |
| `everyMinute()`, `everyTwoMinutes()`, `everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()` | minutes |
| `hourly()`, `hourlyAt(int $minute)`, `everyTwoHours()`, `everySixHours()` | hours |
| `daily()`, `dailyAt('HH:MM')` (alias `at()`), `twiceDaily(1, 13)` | days |
| `weekly()`, `weeklyOn($day, 'HH:MM')`, `monthly()`, `monthlyOn($day, 'HH:MM')`, `quarterly()`, `yearly()` | longer periods |
| `weekdays()`, `weekends()`, `days(1, 3, 5)` | restrict the day of week |
| `between('09:00', '17:00')` | time window (filter) |

Methods change only their own fields, so they can be combined: `->dailyAt('02:00')->weekdays()` is `0 2 * * 1-5`.

## Cron expressions

`CronExpression` parses the 5 standard fields `minute hour day-of-month month day-of-week`:

- `*`, lists `1,15`, ranges `1-5`, steps `*/5`, `10-30/10`, `5/15`;
- month names `jan`-`dec` and day names `sun`-`sat` (`0` and `7` are Sunday);
- aliases `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight`, `@hourly` and `@every 5m` / `@every 2h` / `@every 1d` (minutes dividing 60, hours dividing 24);
- when both day-of-month and day-of-week are restricted, a day matches either of them (standard cron behavior).

```php
use NeoPHP\Package\Scheduler\Cron\CronExpression;

$cron = new CronExpression('*/15 9-17 * * mon-fri', 'Europe/Paris');
$cron->isDue(new DateTimeImmutable());
$cron->getNextRunDate();
$cron->getPreviousRunDate();
$cron->getNextRunDates(5);
CronExpression::isValid('0 0 * * *');
```

Dates are computed in the timezone of the expression. On a DST change, a time that does not exist (spring) is skipped and a repeated time (autumn) runs once.
`php bin/neo schedule:next "<expression>"` (alias `cron:explain`) shows the expanded fields and the next dates.

## Overlaps, conditions and timezones

- `withoutOverlapping(int $ttlMinutes = 1440)`: a file lock in `var/scheduler/locks/` (`flock()` + expiry date) skips the run while the previous one is running; the lock expires after the TTL if the process died.
- `when(callable)` / `skip(callable)`: the callable receives the current date and decides if the due task runs.
- `timezone('Europe/Paris')`: timezone of the expression; the `timezone` key of the configuration is the default of all tasks.
- `name()` / `description()`: shown by `schedule:list` and the profiler. The default name of a closure is `closure@<file>:<line>`.

## History

Every run (`success`, `failed` or `skipped`) is appended to `var/scheduler/history.jsonl`: task name, type, start date, duration (ms), status, exit code, the end of the output (2000 characters) and the error. Only the last `history.max` runs (500) are kept.
`HistoryStore::lastRuns()`, `recent()` and `getLastBeat()` read it.

## Configuration

`config/packages/scheduler.yaml` (every key is optional):

| Key | Default | Description |
|---|---|---|
| `timezone` | PHP timezone | default timezone of the tasks |
| `console` | `bin/neo` | console script of the command tasks |
| `php_binary` | `PHP_BINARY` | PHP binary of the command tasks |
| `storage` | `var/scheduler` | history, locks and heartbeat directory |
| `history.max` | `500` | runs kept in the history |
| `tasks` | `[ ]` | tasks (see above) |
| `providers` | `[ ]` | `ScheduleProviderInterface` classes (in addition to the discovered ones) |

## Commands

| Command | Arguments and options |
|---|---|
| `schedule:run` | runs the due tasks (exit code 1 when a task failed; `-v` shows the outputs) |
| `schedule:list` | name, type, cron, next run, last run, status, source |
| `schedule:work` | `--runs` (0): runs `schedule:run` every minute in the foreground |
| `schedule:test` | `name` (asked when missing): runs one task now |
| `schedule:next` (alias `cron:explain`) | `expression`, `--count`/`-c` (5), `--timezone`/`-z` |

## Profiler

When the WebProfiler is enabled, `Helper/Profiler/SchedulerProfiler` adds a "Scheduler" panel (no toolbar item): the tasks with their cron, next run and last run (status, duration, error), the 20 last runs, the recent failures, and a warning when `schedule:run` did not run in the last 2 minutes ("the system cron is not configured"). It reads the schedule and the history file only.

## Exceptions

All in `NeoPHP\Package\Scheduler\Exception\`, extending `SchedulerException` (itself a `FrameworkException`):

| Exception | Thrown when |
|---|---|
| `InvalidExpressionException` | invalid cron expression or alias |
| `ConfigurationException` | invalid task definition, duplicated name, invalid time, missing console script, message task without the Queue package |
| `TaskNotFoundException` | `schedule:test` with an unknown task |
| `SchedulerException` | no run date found (impossible date such as `0 0 31 2 *`), lock or history not writable, `proc_open()` disabled |

## Limitations

- The tasks of a minute run one after the other: a long task delays the next ones (dispatch a queue message for long work).
- Command tasks have no timeout; their output is captured, not streamed.
- The locks and the history are local files: with several servers, run `schedule:run` on a single server.
- `schedule:run` must start during the minute: a missed minute is not caught up.

## Changelog

- v1.37.0 — Scheduler package: `CronExpression` (lists, ranges, steps, names, aliases, `@every`, timezones, next / previous dates), tasks from `#[AsScheduledTask]` classes and commands, `config/packages/scheduler.yaml` and `ScheduleProviderInterface` with a fluent `Schedule`, frequencies, `when()` / `skip()` / `between()`, overlap locks, run history, `schedule:run`, `schedule:list`, `schedule:work`, `schedule:test`, `schedule:next` commands, Scheduler panel of the WebProfiler.