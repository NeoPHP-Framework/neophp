<?php

declare(strict_types=1);

namespace NeoPHP\Component\Config;

use JsonException;
use NeoPHP\Component\Config\Exception\ConfigException;
use NeoPHP\Package\Yaml\Exception\ParseException;

interface ConfigManagerInterface
{
    /**
     * Returns a value of the configuration.
     *
     * @param string $key Dot-separated key (framework.app.name), or an empty string for the whole tree
     * @param mixed $default Value returned when the key does not exist
     * @return mixed The value, or the default value
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Tells whether a key exists in the configuration, even with a null value.
     *
     * @param string $key Dot-separated key
     * @return bool True when the key exists
     */
    public function has(string $key): bool;

    /**
     * Writes a value in the configuration, without resolving its placeholders.
     *
     * @param string $key Dot-separated key, the missing levels are created
     * @param mixed $value The value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Returns the whole configuration tree, with the placeholders resolved.
     *
     * @return array<mixed> The configuration
     */
    public function all(): array;

    /**
     * Loads every YAML file of a directory, recursively, under a key built from its relative path (packages/orm.yaml gives packages.orm), then resolves the placeholders.
     *
     * @param string $directory Directory of the YAML files, ignored when it does not exist
     * @param array<string> $exclude Files or sub-directories to skip, relative to the directory
     * @return static The config manager
     * @throws ConfigException When a file does not contain a mapping, or a placeholder is unknown, circular, an undefined environment variable or not a scalar inside a string
     * @throws ParseException When a file is not valid YAML
     * @throws JsonException When a %env(json:NAME)% placeholder is not valid JSON
     */
    public function loadDirectory(string $directory, array $exclude = []): static;

    /**
     * Loads a YAML file and merges it into the configuration.
     *
     * @param string $file Path of the YAML file
     * @param string $key Dot-separated key where the content is merged, the root when empty
     * @param bool $resolve Resolves the placeholders of the file
     * @return static The config manager
     * @throws ConfigException When the file does not contain a mapping, or a placeholder is unknown, circular, an undefined environment variable or not a scalar inside a string
     * @throws ParseException When the file does not exist, is not readable or is not valid YAML
     * @throws JsonException When a %env(json:NAME)% placeholder is not valid JSON
     */
    public function loadFile(string $file, string $key = '', bool $resolve = true): static;

    /**
     * Resolves the placeholders of a value: %env(NAME)% with the bool, int, float, json, csv and string processors, %other.key% references, and %% for a literal %.
     *
     * @param mixed $value A value, an array is resolved recursively
     * @return mixed The resolved value
     * @throws ConfigException When a placeholder is unknown, circular, an undefined environment variable, uses an unknown processor or is not a scalar inside a string
     * @throws JsonException When a %env(json:NAME)% placeholder is not valid JSON
     */
    public function resolve(mixed $value): mixed;
}