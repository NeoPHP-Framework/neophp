<?php

declare(strict_types=1);

namespace NeoPHP\Package\Dotenv;

use NeoPHP\Package\Dotenv\Exception\DotenvException;

interface DotenvManagerInterface
{
    /**
     * Parses the content of a .env file, resolving ${NAME} references to the known variables.
     *
     * @param string $content Content of the file
     * @param string|null $path Path of the file, shown in the error messages
     * @return array<string, string> The values, by variable name
     * @throws DotenvException When a line is invalid, a quote is not closed or unexpected characters follow a value
     */
    public function parse(string $content, ?string $path = null): array;

    /**
     * Loads .env files into $_ENV and $_SERVER, without replacing the real environment variables.
     *
     * @param string ...$paths Paths of the files, loaded in order
     * @return void
     * @throws DotenvException When a file cannot be read or is invalid
     */
    public function load(string ...$paths): void;

    /**
     * Loads .env, .env.local (except in test), .env.<env> and .env.<env>.local of a directory, in this order.
     *
     * @param string $directory Directory of the .env files
     * @param string $envKey Variable holding the environment
     * @param string $defaultEnv Environment used when the variable is not defined
     * @return void
     * @throws DotenvException When a file cannot be read or is invalid
     */
    public function loadEnv(string $directory, string $envKey = 'APP_ENV', string $defaultEnv = 'dev'): void;

    /**
     * Writes variables into $_ENV and $_SERVER; a variable loaded from a .env file can always be replaced.
     *
     * @param array<string, string> $values The values, by variable name
     * @param bool $overrideExisting Replaces the real environment variables too
     * @return void
     */
    public function populate(array $values, bool $overrideExisting = false): void;
}