<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Contract;

use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Exception\ConsoleException;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\HelpRenderer;
use NeoPHP\Process\Console\IO\Input;
use NeoPHP\Process\Console\IO\InputDefinition;
use NeoPHP\Process\Console\IO\InputOption;
use ReflectionClass;

abstract class AbstractConsole implements CommandInterface
{
    protected ?AsCommand $metadata = null;

    protected array $examples = [];

    protected ?string $help = null;

    public static function getGlobalOptions(): array
    {
        return [
            new InputOption('help', 'h', InputOption::VALUE_NONE, 'Display the help of the command'),
            new InputOption('quiet', 'q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('verbose', 'v', InputOption::VALUE_NONE, 'Increase the verbosity of messages: -v normal, -vv more, -vvv debug'),
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Force the operation (overwrite files, run destructive actions...)'),
            new InputOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
            new InputOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment name (read by bin/neo before the kernel boots)'),
            new InputOption('ansi', null, InputOption::VALUE_NONE, 'Force ANSI (colored) output'),
            new InputOption('no-ansi', null, InputOption::VALUE_NONE, 'Disable ANSI (colored) output'),
        ];
    }

    public function getName(): string
    {
        return $this->metadata()->name;
    }

    public function getDescription(): string
    {
        return $this->metadata()->description;
    }

    public function getAliases(): array
    {
        return array_values(array_map('strval', $this->metadata()->aliases));
    }

    public function isHidden(): bool
    {
        return $this->metadata()->hidden;
    }

    public function getHelp(): ?string
    {
        return $this->help ?? $this->metadata()->help;
    }

    public function getExamples(): array
    {
        return $this->examples;
    }

    public function getDefinition(OutputInterface $output): InputDefinition
    {
        return $this->prepare(new Input([]), $output)->getDefinition();
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        static::configureIo($input, $output);
        $this->prepare($input, $output);

        if ($input->hasParameterOption(['--help', '-h'])) {
            (new HelpRenderer())->render($this, $input->getDefinition(), $output);

            return self::SUCCESS;
        }

        $input->bind();

        if ($input->isInteractive() && $output->isInteractive()) {
            $this->interact($input, $output);
            $this->askMissing($input, $output);
        }

        $input->validate();

        $code = $this->do($input, $output);

        return max(0, min(255, $code));
    }

    public static function configureIo(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasParameterOption('--no-ansi')) {
            $output->setDecorated(false);
        } elseif ($input->hasParameterOption('--ansi')) {
            $output->setDecorated(true);
        }

        if ($input->hasParameterOption(['--no-interaction', '-n'])) {
            $input->setInteractive(false);
        }

        $output->setInteractive($input->isInteractive());

        if ($input->hasParameterOption(['--quiet', '-q'])) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
            $input->setInteractive(false);
            $output->setInteractive(false);

            return;
        }

        $level = 0;

        foreach ($input->getTokens() as $token) {
            if ($token === '--') {
                break;
            }

            if ($token === '--verbose') {
                $level = max($level, 1);
            } elseif (preg_match('/^--verbose=(\d)$/', $token, $matches) === 1) {
                $level = max($level, (int) $matches[1]);
            } elseif (preg_match('/^-(v+)$/', $token, $matches) === 1) {
                $level = max($level, strlen($matches[1]));
            }
        }

        $output->setVerbosity(match (true) {
            $level >= 3 => OutputInterface::VERBOSITY_DEBUG,
            $level === 2 => OutputInterface::VERBOSITY_VERY_VERBOSE,
            $level === 1 => OutputInterface::VERBOSITY_VERBOSE,
            default => OutputInterface::VERBOSITY_NORMAL,
        });
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
    }

    abstract protected function do(InputInterface $input, OutputInterface $output): int;

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
    }

    protected function askMissing(InputInterface $input, OutputInterface $output): void
    {
        $definition = $input->getDefinition();

        foreach ($definition->getArguments() as $name => $argument) {
            if ($input->isArgumentProvided($name) || (!$argument->isRequired() && $argument->getQuestion() === null)) {
                continue;
            }

            $question = $argument->getQuestion() ?? ucfirst($argument->getDescription() !== '' ? $argument->getDescription() : $name);
            $default = $argument->isRequired() || $argument->isArray() || !is_scalar($argument->getDefault()) ? null : (string) $argument->getDefault();
            $required = $argument->isRequired();
            $answer = $output->ask($question, $default, static function (mixed $value) use ($required): ?string {
                $value = trim((string) $value);

                if ($value === '' && $required) {
                    throw new InvalidInputException('A value is required.');
                }

                return $value === '' ? null : $value;
            });

            if ($answer !== null) {
                $input->setArgument($name, $argument->isArray() ? [$answer] : $answer);
            }
        }

        foreach ($definition->getOptions() as $name => $option) {
            if ($definition->isGlobal($name) || $option->getQuestion() === null || $input->isOptionProvided($name)) {
                continue;
            }

            if (!$option->acceptValue()) {
                $input->setOption($name, $output->confirm($option->getQuestion(), (bool) $option->getDefault()));
                continue;
            }

            $default = $option->getDefault();
            $answer = $output->ask($option->getQuestion(), is_scalar($default) && !is_bool($default) ? (string) $default : null);

            if (is_string($answer) && trim($answer) !== '') {
                $input->setOption($name, $option->isArray() ? [trim($answer)] : trim($answer));
            }
        }
    }

    protected function addExample(string $example): static
    {
        $this->examples[] = $example;

        return $this;
    }

    protected function setHelp(string $help): static
    {
        $this->help = $help;

        return $this;
    }

    protected function prepare(InputInterface $input, OutputInterface $output): InputInterface
    {
        $this->examples = [];
        $this->help = null;
        $this->configure($input, $output);

        $definition = $input->getDefinition();

        foreach (static::getGlobalOptions() as $option) {
            if ($definition->hasOption($option->getName())) {
                throw new ConsoleException('The command "{command}" cannot define the option "--{option}": it is a global option.', 0, null, ['command' => $this->getName(), 'option' => $option->getName()]);
            }

            $definition->addOption($option, true);
        }

        return $input;
    }

    protected function metadata(): AsCommand
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }

        $attributes = (new ReflectionClass($this))->getAttributes(AsCommand::class);

        if ($attributes === []) {
            throw new ConsoleException('The command "{command}" must be declared with the #[AsCommand(name: ...)] attribute.', 0, null, ['command' => static::class]);
        }

        $metadata = $attributes[0]->newInstance();

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*$/', $metadata->name) !== 1) {
            throw new InvalidInputException('The command name "{name}" of "{command}" is not valid.', 0, null, ['name' => $metadata->name, 'command' => static::class]);
        }

        return $this->metadata = $metadata;
    }
}