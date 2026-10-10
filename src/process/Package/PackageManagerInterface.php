<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package;

use NeoPHP\Component\Kernel\Exception\KernelException;
use NeoPHP\Process\Package\Exception\PackageException;

interface PackageManagerInterface
{
    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_OVERWRITTEN = 'overwritten';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_REMOVED = 'removed';

    /**
     * Returns the NeoPHP packages installed with Composer.
     *
     * @return array<string, array<string, mixed>> The definition of every package (name, alias, version, path, modules, directories...), by Composer name
     * @throws KernelException When the extra.neophp.name of a package is not snake_case
     */
    public function all(): array;

    /**
     * Returns an installed NeoPHP package.
     *
     * @param string $package Composer name (acme/neo-billing) or name (billing) of the package
     * @return array<string, mixed>|null The definition of the package, or null when it is not installed
     * @throws KernelException When the extra.neophp.name of a package is not snake_case
     */
    public function find(string $package): ?array;

    /**
     * Requires a NeoPHP package with Composer, copies its configuration into config/packages/<name>/, imports its routes in config/routes.yaml and clears the cache.
     *
     * @param string $package Composer name, with an optional version constraint (acme/neo-billing:^1.2)
     * @param bool $dev Adds the package to require-dev
     * @param bool $force Overwrites the configuration files changed in the project
     * @return array{package: array<string, mixed>, files: array<string, string>} The installed package and the status of every written file, by relative path
     * @throws PackageException When the name is invalid, the package is not of type neophp-package, Composer fails or a file cannot be written
     */
    public function install(string $package, bool $dev = false, bool $force = false): array;

    /**
     * Updates one or every NeoPHP package with Composer, copies their new configuration files and clears the cache.
     *
     * @param string|null $package Composer name or name of the package, every NeoPHP package when null or empty
     * @param bool $force Overwrites the configuration files changed in the project
     * @return array<string, array{package: array<string, mixed>, files: array<string, string>}> The updated package and its written files, by Composer name
     * @throws PackageException When the package is not installed, Composer fails or a file cannot be written
     */
    public function update(?string $package = null, bool $force = false): array;

    /**
     * Removes the entries of a package from config/config.php and config/routes.yaml, runs composer remove and clears the cache; the entries are restored when Composer fails.
     *
     * @param string $package Composer name or name of the package
     * @param bool $purge Also deletes config/packages/<name>/
     * @return array{package: array<string, mixed>, files: array<string, string>} The removed package and the status of every changed file, by relative path
     * @throws PackageException When the package is not installed, Composer fails or a file cannot be written
     */
    public function remove(string $package, bool $purge = false): array;

    /**
     * Copies the configuration files of an installed package into config/packages/<name>/; a file changed in the project and in the package is written next to it with the .dist extension.
     *
     * @param string $package Composer name or name of the package
     * @param bool $force Overwrites the files changed in the project
     * @return array<string, string> The status of every file (created, updated, changed, skipped or overwritten), by relative path
     * @throws PackageException When the package is not installed or a file cannot be written
     */
    public function publishConfig(string $package, bool $force = false): array;

    /**
     * Adds a Composer path repository with symlinks to composer.json of the project.
     *
     * @param string $path Directory of the package, relative to the project or absolute
     * @return bool False when the repository already exists
     * @throws PackageException When composer.json is missing, is not valid JSON or cannot be written
     */
    public function addPathRepository(string $path): bool;

    /**
     * Returns the configuration directory of a package in the project.
     *
     * @param string $package Composer name or name of the package
     * @return string The config/packages/<name> directory
     */
    public function getConfigDirectory(string $package): string;

    /**
     * Empties var/cache/, except the .gitkeep files, and resets OPcache.
     *
     * @return int The number of removed files
     */
    public function clearCache(): int;
}