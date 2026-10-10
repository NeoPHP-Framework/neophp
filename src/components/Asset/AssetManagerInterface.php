<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset;

use NeoPHP\Component\Asset\Compiler\CompilerInterface;
use NeoPHP\Component\Asset\Exception\AssetException;
use NeoPHP\Component\Asset\Manifest\Manifest;

interface AssetManagerInterface
{
    /**
     * Returns the public URL of the compiled asset, compiled first when it changed (debug) or when it is missing from the manifest.
     *
     * @param string $path Path relative to assets/, @name/... for the assets of a NeoPHP package, or an absolute URL returned unchanged
     * @return string The URL of the compiled file, with the base path of the application
     * @throws AssetException When the path is invalid, the asset does not exist, contains a circular reference or cannot be written, or the manifest is invalid or cannot be written
     */
    public function url(string $path): string;

    /**
     * Compiles one asset, even when it is already in the manifest, and saves the manifest.
     *
     * @param string $path Path relative to assets/, or @name/... for the assets of a NeoPHP package
     * @return string The URL of the compiled file, without the base path of the application
     * @throws AssetException When the path is invalid, the asset does not exist, contains a circular reference or cannot be written, or the manifest is invalid or cannot be written
     */
    public function compile(string $path): string;

    /**
     * Empties the build directory, compiles every asset of the project and of the NeoPHP packages, and saves the manifest.
     *
     * @param bool $minify Minifies the CSS and JavaScript files
     * @return array<string, string> The URL of every compiled file, by asset path
     * @throws AssetException When the build directory contains the assets directory, an asset cannot be compiled or written, or the manifest cannot be written
     */
    public function reload(bool $minify = false): array;

    /**
     * Removes the compiled files from the build directory and empties the manifest.
     *
     * @return void
     * @throws AssetException When the build directory contains the assets directory, or the manifest is invalid or cannot be written
     */
    public function clear(): void;

    /**
     * Registers a compiler, used for the extensions it supports.
     *
     * @param CompilerInterface $compiler The compiler
     * @return static The asset manager
     */
    public function addCompiler(CompilerInterface $compiler): static;

    /**
     * Compiles another file in place of an asset.
     *
     * @param string $path Path relative to assets/
     * @param string|null $file The file compiled instead of the asset, or null to remove the override
     * @return static The asset manager
     * @throws AssetException When the path is invalid
     */
    public function setSourceFile(string $path, ?string $file): static;

    /**
     * Returns the file actually compiled for an asset: its override, the file of assets/, then the file of the NeoPHP package.
     *
     * @param string $path Path relative to assets/, or @name/... for the assets of a NeoPHP package
     * @return string The path of the source file, which may not exist
     * @throws AssetException When the path is invalid
     */
    public function getSourceFile(string $path): string;

    /**
     * Makes a directory available as @name/..., overridden by assets/packages/<name>/ of the project.
     *
     * @param string $name Name of the namespace, in snake_case
     * @param string $directory Directory of the assets
     * @return static The asset manager
     * @throws AssetException When the name is not snake_case
     */
    public function addNamespace(string $name, string $directory): static;

    /**
     * Returns the registered namespaces.
     *
     * @return array<string, string> The directory of every namespace, by name
     */
    public function getNamespaces(): array;

    /**
     * Returns the directory of the assets.
     *
     * @return string The source directory
     */
    public function getSourcePath(): string;

    /**
     * Returns the directory of the compiled files and of manifest.json.
     *
     * @return string The build directory
     */
    public function getBuildPath(): string;

    /**
     * Returns the manifest mapping every asset to its compiled URL.
     *
     * @return Manifest The manifest
     */
    public function getManifest(): Manifest;
}