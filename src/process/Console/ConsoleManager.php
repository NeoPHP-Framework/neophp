<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Process;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\CommandInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\CommandNotFoundException;
use NeoPHP\Process\Console\Exception\ConsoleException;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\HelpRenderer;
use NeoPHP\Process\Console\IO\Input;
use NeoPHP\Process\Console\IO\Output;
use NeoPHP\Process\Console\Provider\ConsoleProvider;
use ReflectionClass;
use Throwable;

#[Process(provider: ConsoleProvider::class)]
final class ConsoleManager implements ConsoleManagerInterface
{
    public const VALUE_OPTIONS = ['--env', '-e'];

    protected array $commands = [];

    protected array $aliases = [];

    protected array $instances = [];

    public function __construct(protected ?ContainerManagerInterface $container = null, protected string $version = '')
    {
    }

    public function add(CommandInterface|string $command): static
    {
        $metadata = $this->metadataOf($command);
        $name = $metadata['name'];

        $this->commands[$name] = $metadata;
        unset($this->instances[$name]);

        if ($command instanceof CommandInterface) {
            $this->instances[$name] = $command;
        }

        foreach ($metadata['aliases'] as $alias) {
            $this->aliases[$alias] = $name;
        }

        ksort($this->commands);

        return $this;
    }

    public function all(): array
    {
        return $this->commands;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->aliases[$name]);
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function resolveName(string $name): string
    {
        if (isset($this->commands[$name])) {
            return $name;
        }

        if (isset($this->aliases[$name])) {
            return $this->aliases[$name];
        }

        $names = [...array_keys($this->commands), ...array_keys($this->aliases)];
        $pattern = '/^' . implode('[^:]*:', array_map(static fn (string $part): string => preg_quote($part, '/'), explode(':', $name))) . '[^:]*$/i';
        $matches = array_values(array_unique(array_map(fn (string $candidate): string => $this->aliases[$candidate] ?? $candidate, array_filter($names, static fn (string $candidate): bool => preg_match($pattern, $candidate) === 1))));
        $visible = array_values(array_filter($matches, fn (string $match): bool => !$this->commands[$match]['hidden']));

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($visible) === 1) {
            return $visible[0];
        }

        if (count($matches) > 1) {
            throw new CommandNotFoundException('Command "' . $name . '" is ambiguous.', $visible !== [] ? $visible : $matches);
        }

        throw new CommandNotFoundException('Command "' . $name . '" is not defined.', $this->alternatives($name));
    }

    public function find(string $name): CommandInterface
    {
        $name = $this->resolveName($name);

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $class = $this->commands[$name]['class'];
        $command = $this->container !== null ? $this->container->get($class) : new $class();

        if (!$command instanceof CommandInterface) {
            throw new ConsoleException('"{command}" must implement {interface}.', 0, null, ['command' => get_debug_type($command), 'interface' => CommandInterface::class]);
        }

        return $this->instances[$name] = $command;
    }

    public function run(array $argv, ?OutputInterface $output = null): int
    {
        $output ??= new Output();
        $tokens = array_values(array_map('strval', array_slice($argv, 1)));
        [$name, $rest] = $this->split($tokens);
        $input = new Input($rest);

        try {
            AbstractConsole::configureIo($input, $output);

            if ($name === null && $input->hasParameterOption(['--version', '-V'])) {
                $output->writeln('<title>NeoPHP</title> <info>' . ($this->version !== '' ? $this->version : 'dev') . '</info>');

                return CommandInterface::SUCCESS;
            }

            $name ??= 'list';

            try {
                $command = $this->find($name);
            } catch (CommandNotFoundException $exception) {
                if ($this->isNamespace($name)) {
                    (new HelpRenderer())->renderList($this->commands, $output, $this->version, $name);

                    return CommandInterface::SUCCESS;
                }

                $this->renderNotFound($exception, $output);

                return CommandInterface::INVALID;
            }

            return $command->run($input, $output);
        } catch (CommandNotFoundException $exception) {
            $this->renderNotFound($exception, $output);

            return CommandInterface::INVALID;
        } catch (InvalidInputException $exception) {
            $output->error($exception->getMessage());

            if (isset($command)) {
                $output->writeln('<comment>Usage:</comment> ' . Formatter::escape(trim($command->getName() . ' ' . $command->getDefinition($output)->getSynopsis())), OutputInterface::VERBOSITY_QUIET);
                $output->writeln('Run <info>' . HelpRenderer::BINARY . ' ' . $command->getName() . ' --help</info> for more information.', OutputInterface::VERBOSITY_QUIET);
            }

            return CommandInterface::INVALID;
        } catch (Throwable $exception) {
            $this->renderException($exception, $output);

            return CommandInterface::FAILURE;
        }
    }

    public function renderException(Throwable $exception, OutputInterface $output): void
    {
        $output->error(Formatter::escape($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class));

        if (!$output->isVerbose()) {
            $output->writeln('<muted>Run the command with -v for the details, -vvv for the stack trace.</muted>', OutputInterface::VERBOSITY_QUIET);

            return;
        }

        $current = $exception;

        while ($current !== null) {
            $output->writeln(sprintf('<comment>%s</comment> in <info>%s:%d</info>', $current::class, $current->getFile(), $current->getLine()), OutputInterface::VERBOSITY_QUIET);

            if ($output->isDebug()) {
                foreach (explode("\n", $current->getTraceAsString()) as $line) {
                    $output->writeln('  <muted>' . Formatter::escape($line) . '</muted>', OutputInterface::VERBOSITY_QUIET);
                }
            }

            $current = $current->getPrevious();

            if ($current !== null) {
                $output->writeln('<muted>Previous: ' . Formatter::escape($current->getMessage()) . '</muted>', OutputInterface::VERBOSITY_QUIET);
            }
        }

        $output->newLine();
    }

    protected function renderNotFound(CommandNotFoundException $exception, OutputInterface $output): void
    {
        $output->error($exception->getMessage());

        if ($exception->getAlternatives() === []) {
            return;
        }

        $output->writeln(count($exception->getAlternatives()) > 1 ? '<comment>Did you mean one of these?</comment>' : '<comment>Did you mean this?</comment>', OutputInterface::VERBOSITY_QUIET);

        foreach ($exception->getAlternatives() as $alternative) {
            $output->writeln('    <info>' . $alternative . '</info>', OutputInterface::VERBOSITY_QUIET);
        }

        $output->newLine();
    }

    protected function split(array $tokens): array
    {
        $skip = false;

        foreach ($tokens as $index => $token) {
            if ($skip) {
                $skip = false;
                continue;
            }

            if ($token === '--') {
                return [null, $tokens];
            }

            if (in_array($token, self::VALUE_OPTIONS, true)) {
                $skip = true;
                continue;
            }

            if (!str_starts_with($token, '-')) {
                unset($tokens[$index]);

                return [$token, array_values($tokens)];
            }
        }

        return [null, $tokens];
    }

    protected function isNamespace(string $name): bool
    {
        foreach (array_keys($this->commands) as $command) {
            if (str_starts_with($command, $name . ':')) {
                return true;
            }
        }

        return false;
    }

    protected function alternatives(string $name): array
    {
        $alternatives = [];

        foreach ($this->commands as $command => $metadata) {
            if ($metadata['hidden']) {
                continue;
            }

            $distance = levenshtein($name, $command);

            if ($distance <= max(2, intdiv(strlen($name), 3)) || str_contains($command, $name)) {
                $alternatives[$command] = $distance;
            }
        }

        asort($alternatives);

        return array_slice(array_keys($alternatives), 0, 5);
    }

    protected function metadataOf(CommandInterface|string $command): array
    {
        if ($command instanceof CommandInterface) {
            return [
                'class' => $command::class,
                'name' => $command->getName(),
                'description' => $command->getDescription(),
                'aliases' => $command->getAliases(),
                'hidden' => $command->isHidden(),
            ];
        }

        if (!class_exists($command) || !is_subclass_of($command, CommandInterface::class)) {
            throw new ConsoleException('The command class "{command}" does not exist or does not implement {interface}.', 0, null, ['command' => $command, 'interface' => CommandInterface::class]);
        }

        $attributes = (new ReflectionClass($command))->getAttributes(AsCommand::class);

        if ($attributes === []) {
            throw new ConsoleException('The command "{command}" must be declared with the #[AsCommand(name: ...)] attribute.', 0, null, ['command' => $command]);
        }

        $attribute = $attributes[0]->newInstance();

        return [
            'class' => $command,
            'name' => $attribute->name,
            'description' => $attribute->description,
            'aliases' => array_values(array_map('strval', $attribute->aliases)),
            'hidden' => $attribute->hidden,
        ];
    }
}