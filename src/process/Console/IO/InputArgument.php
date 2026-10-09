<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Exception\ConsoleException;

class InputArgument
{
    public const REQUIRED = 1;

    public const OPTIONAL = 2;

    public const IS_ARRAY = 4;

    public function __construct(
        protected string $name,
        protected int $mode = self::OPTIONAL,
        protected string $description = '',
        protected mixed $default = null,
        protected ?string $question = null,
    ) {
        if ($name === '' || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new ConsoleException('The argument name "{name}" is not valid.', 0, null, ['name' => $name]);
        }

        if (($mode & (self::REQUIRED | self::OPTIONAL)) === 0) {
            $this->mode |= self::OPTIONAL;
        }

        if ($this->isRequired() && $default !== null) {
            throw new ConsoleException('The required argument "{name}" cannot have a default value.', 0, null, ['name' => $name]);
        }

        if ($this->isArray() && $default !== null && !is_array($default)) {
            throw new ConsoleException('The default value of the array argument "{name}" must be an array.', 0, null, ['name' => $name]);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isRequired(): bool
    {
        return ($this->mode & self::REQUIRED) === self::REQUIRED;
    }

    public function isArray(): bool
    {
        return ($this->mode & self::IS_ARRAY) === self::IS_ARRAY;
    }

    public function getDefault(): mixed
    {
        return $this->isArray() ? ($this->default ?? []) : $this->default;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }
}