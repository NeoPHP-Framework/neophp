<?php

declare(strict_types=1);

namespace NeoPHP\Process\Installer;

use NeoPHP\Process\Installer\Exception\InstallerException;

interface InstallerManagerInterface
{
    public const STATUS_CREATED = 'created';
    public const STATUS_OVERWRITTEN = 'overwritten';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_UPDATED = 'updated';

    /**
     * Creates the directories and the files of a new project from the skeleton, adds the missing variables to .env and the App\ autoload to composer.json.
     *
     * @param string $projectDir Root directory of the project
     * @param bool $force Overwrites the existing files
     * @return array<string, string> The status of every directory and file (created, overwritten, skipped or updated), by relative path
     * @throws InstallerException When the project directory is not writable, the skeleton directory does not exist, composer.json is not valid JSON, or a file or directory cannot be written
     */
    public function install(string $projectDir, bool $force = false): array;

    /**
     * Returns the directory of the project skeleton.
     *
     * @return string The skeleton directory
     */
    public function getSkeletonDir(): string;

    /**
     * Returns the stub files of the skeleton.
     *
     * @return array<string, string> The stub file, by relative path of the generated file
     * @throws InstallerException When the skeleton directory does not exist
     */
    public function getFiles(): array;

    /**
     * Returns the directories created in a new project.
     *
     * @return list<string> The directories, relative to the project
     */
    public function getDirectories(): array;
}