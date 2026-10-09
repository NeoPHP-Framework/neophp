<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Exception\ConsoleException;

class InputOption
{
    public const VALUE_NONE = 1;

    public const VALUE_REQUIRED = 2;

    public const VALUE_OPTIONAL = 4;

    public const VALUE_IS_ARRAY = 8;

    protected ?string $shortcut;

    public function __construct(
        protected string $name,
        ?string $shortcut = null,
        protected int $mode = self::VALUE_NONE,
        protected string $description = '',
        protected mixed $default = null,
        protected ?string $question = null,
    ) {
        $this->name = ltrim($name, '-');
        $shortcut = $shortcut !== null ? ltrim($shortcut, '-') : null;
        $this->shortcut = $shortcut === '' ? null : $shortcut;

        if ($this->name === '' || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $this->name) !== 1) {
            throw new ConsoleException('The option name "{name}" is not valid.', 0, null, ['name' => $name]);
        }

        if ($this->shortcut !== null && preg_match('/^[A-Za-z]$/', $this->shortcut) !== 1) {
            throw new ConsoleException('The shortcut "{shortcut}" of the option "--{name}" must be a single letter.', 0, null, ['shortcut' => $this->shortcut, 'name' => $this->name]);
        }

        if (($mode & (self::VALUE_NONE | self::VALUE_REQUIRED | self::VALUE_OPTIONAL)) === 0) {
            $this->mode |= self::VALUE_NONE;
        }

        if ($this->isArray() && !$this->acceptValue()) {
            throw new ConsoleException('The array option "--{name}" must accept a value (VALUE_REQUIRED or VALUE_OPTIONAL).', 0, null, ['name' => $this->name]);
        }

        if (!$this->acceptValue() && $default !== null && $default !== false) {
            throw new ConsoleException('The flag option "--{name}" cannot have a default value.', 0, null, ['name' => $this->name]);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getShortcut(): ?string
    {
        return $this->shortcut;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function acceptValue(): bool
    {
        return ($this->mode & (self::VALUE_REQUIRED | self::VALUE_OPTIONAL)) !== 0;
    }

    public function isValueRequired(): bool
    {
        return ($this->mode & self::VALUE_REQUIRED) === self::VALUE_REQUIRED;
    }

    public function isArray(): bool
    {
        return ($this->mode & self::VALUE_IS_ARRAY) === self::VALUE_IS_ARRAY;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function getDefault(): mixed
    {
        if (!$this->acceptValue()) {
            return false;
        }

        return $this->isArray() ? ($this->default ?? []) : $this->default;
    }
}