<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;

class Input implements InputInterface
{
    protected InputDefinition $definition;

    protected array $arguments = [];

    protected array $options = [];

    protected array $counts = [];

    protected bool $interactive = true;

    protected bool $bound = false;

    public function __construct(protected array $tokens = [], ?InputDefinition $definition = null)
    {
        $this->tokens = array_values(array_map('strval', $tokens));
        $this->definition = $definition ?? new InputDefinition();
    }

    public static function environment(array $argv, ?string $default = null): ?string
    {
        $tokens = array_values(array_slice($argv, 1));

        foreach ($tokens as $index => $token) {
            if ($token === '--') {
                break;
            }

            if (str_starts_with($token, '--env=')) {
                return substr($token, 6);
            }

            if (($token === '--env' || $token === '-e') && isset($tokens[$index + 1])) {
                return $tokens[$index + 1];
            }

            if (preg_match('/^-e(.+)$/', $token, $matches) === 1) {
                return $matches[1];
            }
        }

        return $default;
    }

    public function addArgument(string $name, int $mode = InputArgument::OPTIONAL, string $description = '', mixed $default = null, ?string $question = null): static
    {
        $this->definition->addArgument(new InputArgument($name, $mode, $description, $default, $question));
        $this->bound = false;

        return $this;
    }

    public function addOption(string $name, ?string $shortcut = null, int $mode = InputOption::VALUE_NONE, string $description = '', mixed $default = null, ?string $question = null): static
    {
        $this->definition->addOption(new InputOption($name, $shortcut, $mode, $description, $default, $question));
        $this->bound = false;

        return $this;
    }

    public function getDefinition(): InputDefinition
    {
        return $this->definition;
    }

    public function bind(): static
    {
        $this->arguments = [];
        $this->options = [];
        $this->counts = [];
        $positional = [];
        $tokens = $this->tokens;
        $onlyArguments = false;

        while ($tokens !== []) {
            $token = array_shift($tokens);

            if ($onlyArguments) {
                $positional[] = $token;
            } elseif ($token === '--') {
                $onlyArguments = true;
            } elseif (str_starts_with($token, '--')) {
                $this->parseLong(substr($token, 2), $tokens);
            } elseif (str_starts_with($token, '-') && $token !== '-' && !is_numeric($token)) {
                $this->parseShort(substr($token, 1), $tokens);
            } else {
                $positional[] = $token;
            }
        }

        $this->mapArguments($positional);
        $this->bound = true;

        return $this;
    }

    public function validate(): static
    {
        $missing = [];

        foreach ($this->definition->getArguments() as $name => $argument) {
            if ($argument->isRequired() && (!array_key_exists($name, $this->arguments) || $this->arguments[$name] === [] || $this->arguments[$name] === '')) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new InvalidInputException('Not enough arguments (missing: "{missing}").', 0, null, ['missing' => implode('", "', $missing)]);
        }

        return $this;
    }

    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function hasParameterOption(string|array $names): bool
    {
        foreach ($this->tokens as $token) {
            if ($token === '--') {
                return false;
            }

            foreach ((array) $names as $name) {
                if ($token === $name || (str_starts_with($name, '--') && str_starts_with($token, $name . '='))) {
                    return true;
                }

                if (strlen($name) === 2 && $name[0] === '-' && $name[1] !== '-' && preg_match('/^-[A-Za-z]+$/', $token) === 1 && str_contains(substr($token, 1), $name[1])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getArgument(string $name): mixed
    {
        $argument = $this->definition->getArgument($name);

        return array_key_exists($name, $this->arguments) ? $this->arguments[$name] : $argument->getDefault();
    }

    public function getArguments(): array
    {
        $arguments = [];

        foreach ($this->definition->getArguments() as $name => $argument) {
            $arguments[$name] = $this->getArgument($name);
        }

        return $arguments;
    }

    public function hasArgument(string $name): bool
    {
        return $this->definition->hasArgument($name);
    }

    public function isArgumentProvided(string $name): bool
    {
        return array_key_exists($name, $this->arguments);
    }

    public function setArgument(string $name, mixed $value): static
    {
        $this->definition->getArgument($name);
        $this->arguments[$name] = $value;

        return $this;
    }

    public function getOption(string $name): mixed
    {
        $option = $this->definition->getOption($name);

        return array_key_exists($name, $this->options) ? $this->options[$name] : $option->getDefault();
    }

    public function getOptions(): array
    {
        $options = [];

        foreach ($this->definition->getOptions() as $name => $option) {
            $options[$name] = $this->getOption($name);
        }

        return $options;
    }

    public function hasOption(string $name): bool
    {
        return $this->definition->hasOption($name);
    }

    public function isOptionProvided(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function setOption(string $name, mixed $value): static
    {
        $this->definition->getOption($name);
        $this->options[$name] = $value;

        return $this;
    }

    public function getOptionCount(string $name): int
    {
        return $this->counts[$name] ?? 0;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function setInteractive(bool $interactive): static
    {
        $this->interactive = $interactive;

        return $this;
    }

    protected function parseLong(string $token, array &$tokens): void
    {
        [$name, $value] = str_contains($token, '=') ? explode('=', $token, 2) : [$token, null];

        if (!$this->definition->hasOption($name)) {
            throw new InvalidInputException('The "--{name}" option does not exist.', 0, null, ['name' => $name]);
        }

        $this->assign($this->definition->getOption($name), $value, $tokens, '--' . $name);
    }

    protected function parseShort(string $token, array &$tokens): void
    {
        $length = strlen($token);

        for ($index = 0; $index < $length; $index++) {
            $shortcut = $token[$index];
            $option = $this->definition->findByShortcut($shortcut);

            if ($option === null) {
                throw new InvalidInputException('The "-{shortcut}" option does not exist.', 0, null, ['shortcut' => $shortcut]);
            }

            if ($option->acceptValue()) {
                $rest = substr($token, $index + 1);
                $this->assign($option, $rest !== '' ? ltrim($rest, '=') : null, $tokens, '-' . $shortcut);

                return;
            }

            $this->assign($option, null, $tokens, '-' . $shortcut);
        }
    }

    protected function assign(InputOption $option, ?string $value, array &$tokens, string $label): void
    {
        $name = $option->getName();

        if (!$option->acceptValue()) {
            if ($value !== null) {
                throw new InvalidInputException('The "{label}" option does not accept a value.', 0, null, ['label' => $label]);
            }

            $this->options[$name] = true;
            $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;

            return;
        }

        if ($value === null && $option->isValueRequired() && $tokens !== [] && (!str_starts_with($tokens[0], '-') || is_numeric($tokens[0]) || $tokens[0] === '-')) {
            $value = array_shift($tokens);
        }

        if ($value === null && $option->isValueRequired()) {
            throw new InvalidInputException('The "{label}" option requires a value.', 0, null, ['label' => $label]);
        }

        if ($value === null && !$option->isArray()) {
            $value = $option->getDefault() ?? true;
        }

        $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;

        if ($option->isArray()) {
            $current = array_key_exists($name, $this->options) ? (array) $this->options[$name] : [];

            if ($value !== null) {
                $current[] = $value;
            }

            $this->options[$name] = $current;

            return;
        }

        $this->options[$name] = $value;
    }

    protected function mapArguments(array $positional): void
    {
        $definitions = array_values($this->definition->getArguments());

        foreach ($definitions as $argument) {
            if ($positional === []) {
                break;
            }

            if ($argument->isArray()) {
                $this->arguments[$argument->getName()] = $positional;
                $positional = [];
                break;
            }

            $this->arguments[$argument->getName()] = array_shift($positional);
        }

        if ($positional !== []) {
            $expected = array_map(static fn (InputArgument $argument): string => $argument->getName(), $definitions);

            throw new InvalidInputException($expected === []
                ? 'No arguments expected, got "{got}".'
                : 'Too many arguments, expected arguments "{expected}", got "{got}".', 0, null, [
                'expected' => implode('" "', $expected),
                'got' => implode('" "', $positional),
            ]);
        }
    }
}