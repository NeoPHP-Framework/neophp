<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Exception\ConsoleException;

class InputDefinition
{
    protected array $arguments = [];

    protected array $options = [];

    protected array $shortcuts = [];

    protected array $globals = [];

    public function addArgument(InputArgument $argument): static
    {
        $name = $argument->getName();

        if (isset($this->arguments[$name])) {
            throw new ConsoleException('The argument "{name}" is already defined.', 0, null, ['name' => $name]);
        }

        $last = $this->arguments === [] ? null : end($this->arguments);

        if ($last instanceof InputArgument && $last->isArray()) {
            throw new ConsoleException('The argument "{name}" cannot be added after the array argument "{last}".', 0, null, ['name' => $name, 'last' => $last->getName()]);
        }

        if ($argument->isRequired() && $last instanceof InputArgument && !$last->isRequired()) {
            throw new ConsoleException('The required argument "{name}" cannot be added after the optional argument "{last}".', 0, null, ['name' => $name, 'last' => $last->getName()]);
        }

        $this->arguments[$name] = $argument;

        return $this;
    }

    public function addOption(InputOption $option, bool $global = false): static
    {
        $name = $option->getName();

        if (isset($this->options[$name])) {
            throw new ConsoleException('The option "--{name}" is already defined.', 0, null, ['name' => $name]);
        }

        $shortcut = $option->getShortcut();

        if ($shortcut !== null && isset($this->shortcuts[$shortcut])) {
            throw new ConsoleException('The shortcut "-{shortcut}" of "--{name}" is already used by "--{other}".', 0, null, ['shortcut' => $shortcut, 'name' => $name, 'other' => $this->shortcuts[$shortcut]]);
        }

        $this->options[$name] = $option;

        if ($shortcut !== null) {
            $this->shortcuts[$shortcut] = $name;
        }

        if ($global) {
            $this->globals[$name] = true;
        }

        return $this;
    }

    public function hasArgument(string $name): bool
    {
        return isset($this->arguments[$name]);
    }

    public function getArgument(string $name): InputArgument
    {
        return $this->arguments[$name] ?? throw new ConsoleException('The argument "{name}" does not exist.', 0, null, ['name' => $name]);
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function getOption(string $name): InputOption
    {
        return $this->options[$name] ?? throw new ConsoleException('The option "--{name}" does not exist.', 0, null, ['name' => $name]);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function isGlobal(string $name): bool
    {
        return isset($this->globals[$name]);
    }

    public function findByShortcut(string $shortcut): ?InputOption
    {
        return isset($this->shortcuts[$shortcut]) ? $this->options[$this->shortcuts[$shortcut]] : null;
    }

    public function getSynopsis(): string
    {
        $parts = $this->options === [] ? [] : ['[options]'];

        if ($this->arguments !== []) {
            $parts[] = '[--]';
        }

        foreach ($this->arguments as $argument) {
            $element = '<' . $argument->getName() . '>' . ($argument->isArray() ? '...' : '');
            $parts[] = $argument->isRequired() ? $element : '[' . $element . ']';
        }

        return implode(' ', $parts);
    }
}