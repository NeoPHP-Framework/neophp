# Console

The Console process (`src/process/Console`) runs the `php bin/neo` command line: commands declared with `#[AsCommand]`, typed arguments and options, interactive questions, styled output and progress bars.
Commands are discovered in `src/` and in every framework feature, and their constructor is autowired.

## Summary

- [Usage](#usage)
- [Interactive commands](#interactive-commands)
- [Global options](#global-options)
- [Commands](#commands)
- [Writing a command](#writing-a-command)
- [Input](#input)
- [Output](#output)
- [Formatting](#formatting)
- [Console manager](#console-manager)
- [Exceptions](#exceptions)
- [Migrating from v1.14](#migrating-from-v114)
- [Changelog](#changelog)

## Usage

```bash
php bin/neo
php bin/neo list make
php bin/neo make
php bin/neo make:entity --help
php bin/neo help make:entity
php bin/neo m:ent Post
php bin/neo --version
```

- Without argument, the commands are listed grouped by namespace; `list <namespace>` (or just the namespace) lists one namespace.
- Abbreviations (`m:ent`) are resolved when they are not ambiguous; a mistyped command shows the closest names (`Did you mean this?`).
- Exit codes: `0` success, `1` failure, `2` invalid input (unknown command, option or missing argument).

## Interactive commands

A command run without its arguments asks for them:

```
$ php bin/neo make:form
 Name of the form (e.g. Post, Contact):
 > Post
 Entity mapped by the form (empty for a form working with an array) (? to list):
 > ?
   1) Category
   2) Post
 Entity mapped by the form (empty for a form working with an array) (? to list):
 > 2
```

- Every missing required argument is asked, and the arguments and options that declare a question are asked when they are not given on the command line. The value between brackets is the default answer (press <return>).
- In a list, `?` shows the choices; answer with the number, the value, or its beginning when it is not ambiguous (`int` → `integer`).
- `-n` (or `-q`) disables every question: the missing required arguments are then reported as errors, which keeps the commands usable in scripts.

## Global options

Every command accepts (`AbstractConsole::getGlobalOptions()`):

| Option | Description |
|---|---|
| `-h, --help` | displays the help of the command: description, usage, arguments, options, help and examples |
| `-q, --quiet` | no output (errors are still displayed), implies `--no-interaction` |
| `-v`, `-vv`, `-vvv`, `--verbose` | verbose, very verbose and debug output |
| `-f, --force` | force the operation (overwrite generated files, skip confirmations) |
| `-n, --no-interaction` | never ask a question: the default answers are used |
| `-e, --env=ENV` | the environment (`APP_ENV`), read by `bin/neo` before the kernel boots |
| `--ansi`, `--no-ansi` | force or disable the colors (`NO_COLOR` is also supported) |

When a command fails, the message is displayed in an error block; `-v` adds the exception class and file, `-vvv` the stack trace.

## Commands

Commands of the Console process:

| Command | Description |
|---|---|
| `help [command]` | displays the help of a command |
| `list [namespace]` | lists the commands, grouped by namespace |
| `install [--force]` | generates the project files (`public/`, `src/Kernel.php`, `config/`, `templates/`...); existing files are kept without `--force`, missing `.env` variables are always added |
| `serve [--host=127.0.0.1] [-p 8000]` | starts the PHP development server |
| `route:list [filter]` (`routes`) | lists the routes |
| `make:command Class [name]` | generates a console command in `src/Command/` |

Other features add their own commands (`cache:clear`, `make:entity`, `make:auth`, `mailer:test`...): see their documentation. The `make:*` commands never overwrite an existing file, unless `--force` is used.

## Writing a command

```bash
php bin/neo make:command SendReport
php bin/neo make:command Admin/CleanUsers admin:clean-users
```

The first one creates `src/Command/SendReportCommand.php` named `app:send-report`.

A command is a class declared with `#[AsCommand]` that extends `AbstractConsole`. Arguments, options, help and examples are declared in `configure()`, the work is done in `do()`, which returns an exit code:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReportService;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

#[AsCommand(name: 'app:send-report', description: 'Sends the monthly report', aliases: ['report'])]
class SendReportCommand extends AbstractConsole
{
    public function __construct(protected ReportService $reports)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('month', InputArgument::REQUIRED, 'The month (YYYY-MM)', null, 'Month of the report (YYYY-MM)');
        $input->addArgument('emails', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The recipients');
        $input->addOption('format', null, InputOption::VALUE_REQUIRED, 'pdf or csv', 'pdf', 'Format of the report');
        $input->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not send anything');
        $this->setHelp('The report is sent to the administrators when no email is given.');
        $this->addExample('app:send-report 2026-09');
        $this->addExample('app:send-report 2026-09 alice@example.com bob@example.com --format=csv');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$input->isArgumentProvided('emails')) {
            $email = $output->select('Recipient (empty for the administrators)', $this->reports->knownRecipients(), null, false);

            if ($email !== null) {
                $input->setArgument('emails', [$email]);
            }
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $month = $input->getArgument('month');

        if (!$output->confirm('Send the report of ' . $month . '?')) {
            return self::SUCCESS;
        }

        foreach ($output->progressIterate($this->reports->recipients($input->getArgument('emails'))) as $email) {
            $this->reports->send($month, $email, $input->getOption('format'), $input->getOption('dry-run'));
        }

        $output->success('Report sent.');

        return self::SUCCESS;
    }
}
```

### #[AsCommand]

`#[AsCommand(string $name, string $description = '', array $aliases = [], bool $hidden = false, ?string $help = null)]`: the metadata is read without creating the command; the constructor is autowired when the command runs. Hidden commands are not listed but can be run.

### Discovery

Commands are discovered (`CommandDiscovery`) in `src/` (any instantiable class with `#[AsCommand]` implementing `CommandInterface`) and in the `Helper/Console/` directory of each framework feature.

### AbstractConsole

| Method | Description |
|---|---|
| `configure(InputInterface $input, OutputInterface $output): void` | declares arguments, options, help and examples |
| `interact(InputInterface $input, OutputInterface $output): void` | asks custom questions before the missing values are asked |
| `do(InputInterface $input, OutputInterface $output): int` | abstract: the work, returns `SUCCESS`, `FAILURE` or `INVALID` |
| `setHelp(string $help)`, `addExample(string $example)` | help and examples shown by `--help` |
| `getName()`, `getDescription()`, `getAliases()`, `isHidden()`, `getHelp()`, `getExamples()`, `getDefinition()` | metadata (`CommandInterface`) |
| `run(InputInterface $input, OutputInterface $output): int` | runs the command (called by the console manager) |

A command can also implement `CommandInterface` directly (constants `SUCCESS = 0`, `FAILURE = 1`, `INVALID = 2`).

### Arguments and options

| Constant | Description |
|---|---|
| `InputArgument::REQUIRED` | required argument |
| `InputArgument::OPTIONAL` | optional argument (default) |
| `InputArgument::IS_ARRAY` | collects the remaining values (last argument) |
| `InputOption::VALUE_NONE` | flag (default) |
| `InputOption::VALUE_REQUIRED` | `--name=value` |
| `InputOption::VALUE_OPTIONAL` | `--name` or `--name=value` |
| `InputOption::VALUE_IS_ARRAY` | repeatable: `--tag=a --tag=b` |

- Accepted syntaxes: `--name=value`, `--name value`, `-n value`, `-nvalue`, grouped flags `-abc`; `--` ends the options.
- Missing required arguments, unknown options and missing values are reported before `do()` runs; a command reports its own invalid input by throwing `InvalidInputException` (exit code 2, usage displayed).
- The global options cannot be redefined; read `--force` with `$input->getOption('force')`.

### Questions

The last parameter of `addArgument()` / `addOption()` is the question asked when the value is missing. A required argument without question is asked with its description; a flag with a question is asked with `confirm()`. `interact()` runs before them for richer questions (lists, validation, values depending on each other). Neither runs with `-n`.

## Input

`NeoPHP\Process\Console\Contract\InputInterface` (implemented by `IO\Input`):

| Method | Description |
|---|---|
| `addArgument(string $name, int $mode = InputArgument::OPTIONAL, string $description = '', mixed $default = null, ?string $question = null)` | declares an argument |
| `addOption(string $name, ?string $shortcut = null, int $mode = InputOption::VALUE_NONE, string $description = '', mixed $default = null, ?string $question = null)` | declares an option |
| `getArgument($name)`, `getArguments()`, `hasArgument($name)` | arguments |
| `isArgumentProvided($name)`, `setArgument($name, $value)` | given on the command line / set an answer |
| `getOption($name)`, `getOptions()`, `hasOption($name)` | options |
| `isOptionProvided($name)`, `setOption($name, $value)` | given on the command line / set an answer |
| `getOptionCount($name)` | number of occurrences (`-vvv` → 3) |
| `hasParameterOption(string\|array $names)` | raw token check (`'--force'`, `['-f', '--force']`) |
| `getTokens()` | raw tokens |
| `isInteractive()`, `setInteractive(bool)` | interaction state |
| `getDefinition()`, `bind()`, `validate()` | definition, parsing and validation (called by `run()`) |

`Input::environment(array $argv, ?string $default = null): ?string` reads `--env` / `-e` from `$argv`; `bin/neo` uses it before the kernel boots:

```php
$kernel = new Kernel(Input::environment($argv));
```

`IO\InputDefinition` holds the `InputArgument` / `InputOption` objects (`getArguments()`, `getOptions()`, `getSynopsis()`, `findByShortcut()`...).

## Output

`NeoPHP\Process\Console\Contract\OutputInterface` (implemented by `IO\Output`):

| Method | Description |
|---|---|
| `writeln($message = '', $verbosity)`, `write()`, `newLine($count = 1)` | raw output, shown from the given verbosity |
| `title()`, `section()`, `text()`, `comment()`, `listing()` | layout |
| `table($headers, $rows)`, `definitionList($definitions)` | tables and key / value lists |
| `success()`, `error()`, `warning()`, `caution()`, `info()`, `note()` | message blocks (string or array) |
| `ask($question, $default = null, $validator = null)` | asks a question; the validator throws an exception to ask again, or returns the value |
| `confirm($question, $default = true)` | yes / no question (`y`, `yes`, `o`, `oui`, `true`, `1` / `n`, `no`, `non`, `false`, `0`) |
| `choice($question, $choices, $default = null)` | shows the choices and returns the value (list) or the key (associative array) |
| `select($question, $choices, $default = null, $strict = true, $validator = null)` | same answers as `choice()` without showing the list: `?` lists the choices, a number, a value or (strict) its unambiguous beginning is accepted; with `$strict = false`, any other value is returned as typed and an empty answer returns `null` |
| `secret($question, $validator = null)` | hidden answer |
| `progressStart($max = 0)`, `progressAdvance($step = 1)`, `progressFinish()`, `progressIterate($iterable, $max = null)` | progress bar (`IO\ProgressBar`) |
| `getVerbosity()`, `setVerbosity()`, `isQuiet()`, `isVerbose()`, `isVeryVerbose()`, `isDebug()` | verbosity |
| `isInteractive()`, `setInteractive()`, `isDecorated()`, `setDecorated()` | state |

Verbosity constants: `VERBOSITY_QUIET`, `VERBOSITY_NORMAL`, `VERBOSITY_VERBOSE`, `VERBOSITY_VERY_VERBOSE`, `VERBOSITY_DEBUG`.

```php
$output->writeln('Query: ' . $sql, OutputInterface::VERBOSITY_VERBOSE);
$output->table(['Name', 'Email'], [['Alice', 'alice@example.com']]);
$name = $output->ask('Your name', 'Alice', static fn (string $value): string => $value !== '' ? $value : throw new \RuntimeException('Required.'));
```

Without interaction (`-n`, `-q` or a closed input), the questions return their default answer.

## Formatting

Messages accept the tags `<info>`, `<success>`, `<comment>`, `<warning>`, `<error>`, `<question>`, `<title>`, `<muted>`, `<bold>` and `<underline>`:

```php
$output->writeln('<info>Done</info> in <bold>3</bold> seconds');
$output->text('User: ' . Formatter::escape($name));
```

`IO\Formatter`: `escape(string $message)` (static) escapes a text displayed as is, `width(string $text)` (static) is the visible width, `format()`, `strip()`, `hasStyle()`, `setStyle(string $name, string $code)` add ANSI styles; `$output->getFormatter()` returns it.

## Console manager

`NeoPHP\Process\Console\Contract\ConsoleInterface` (service `ConsoleManager`, registered by `ConsoleProvider`) runs the commands:

| Method | Description |
|---|---|
| `add(CommandInterface\|string $command)` | registers a command instance or class |
| `all()`, `has(string $name)` | registered commands |
| `resolveName(string $name)` | resolves an alias or an abbreviation |
| `find(string $name): CommandInterface` | the command, or `CommandNotFoundException` |
| `getVersion()` | framework version (`--version`) |
| `run(array $argv, ?OutputInterface $output = null): int` | runs `$argv`, returns the exit code |

```php
$code = $console->run(['bin/neo', 'cache:clear', '-q']);
```

## Exceptions

In `NeoPHP\Process\Console\Exception`:

| Exception | Description |
|---|---|
| `ConsoleException` | base exception of the console |
| `CommandNotFoundException` | unknown or ambiguous command (`getAlternatives()`) |
| `InvalidInputException` | invalid input: exit code 2 and usage displayed |

## Migrating from v1.14

| Before | After |
|---|---|
| `extends AbstractCommand` | `extends AbstractConsole` + `#[AsCommand(name: ..., description: ...)]` |
| `protected string $name`, `$description` | `#[AsCommand]` |
| `execute(Input $input, Output $output)` | `do(InputInterface $input, OutputInterface $output)` |
| `$input->getArgument(0)` | `$input->addArgument('name', ...)` in `configure()`, then `$input->getArgument('name')` |
| `$input->getOption('x', $default)` | `$input->addOption('x', null, InputOption::VALUE_REQUIRED, '', $default)`, then `$input->getOption('x')` |
| commands only in `Helper/Console/` | any class of `src/` declared with `#[AsCommand]` |

`bin/neo` passes the environment to the kernel: replace `new Kernel()` with `new Kernel(Input::environment($argv))`, or run `php bin/neo install --force` on a copy of the project to get the new file.

## Changelog

- v1.17.0 — interactive console: missing arguments are asked, questions declared by `addArgument()` / `addOption()`, `interact()` hook, `select()` (`?` to list, numbers, prefixes), `isArgumentProvided()` / `isOptionProvided()`, `setArgument()` / `setOption()`.
- v1.15.0 — console rewritten: `#[AsCommand]` and `AbstractConsole` (`configure()` / `do()`), argument and option definitions with validation, global options, unified help with examples, styled output, questions (`ask`, `confirm`, `choice`, `secret`), progress bar, namespaces, abbreviations, "Did you mean" suggestions, aliases, `make:command`, discovery in `src/`, `--env` read by `bin/neo`; `AbstractCommand` removed.
- v1.3.0 — commands discovered in the `Helper/Console/` directory of each feature.
- v1.0.0 — `neo` console with `install`, `serve` and `route:list`.