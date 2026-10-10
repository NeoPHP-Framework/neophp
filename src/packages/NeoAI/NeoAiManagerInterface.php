<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI;

use NeoPHP\Package\NeoAI\Agent\Assistant;
use NeoPHP\Package\NeoAI\Agent\PromptBuilder;
use NeoPHP\Package\NeoAI\Contract\ProviderInterface;
use NeoPHP\Package\NeoAI\Exception\ConfigurationException;
use NeoPHP\Package\NeoAI\Exception\SecurityException;
use NeoPHP\Package\NeoAI\Patch\PatchApplier;
use NeoPHP\Package\NeoAI\Scan\Scanner;
use NeoPHP\Package\NeoAI\Security\Redactor;
use NeoPHP\Package\NeoAI\Security\RequestGuard;
use NeoPHP\Package\NeoAI\Security\Sandbox;
use NeoPHP\Package\NeoAI\Storage\ConversationStorage;
use NeoPHP\Package\NeoAI\Tool\ToolRunner;

interface NeoAiManagerInterface
{
    /**
     * Returns the configuration of neo_ai.yaml, normalized with its defaults.
     *
     * @return array<string, mixed> The configuration
     */
    public function getConfig(): array;

    /**
     * Tells whether the assistant is enabled (in debug by default).
     *
     * @return bool True when NeoAI is enabled
     */
    public function isEnabled(): bool;

    /**
     * Tells whether the web chat of the toolbar is enabled: NeoAI enabled, debug and web.enabled.
     *
     * @return bool True when the web chat is enabled
     */
    public function isWebEnabled(): bool;

    /**
     * Returns the URL prefix of the web chat.
     *
     * @return string The path, with a leading slash (/_neo_ai by default)
     */
    public function getWebPath(): string;

    /**
     * Returns the root directory the assistant can read.
     *
     * @return string The root directory of the project
     */
    public function getRoot(): string;

    /**
     * Returns the storage directory of NeoAI, or one of its sub-directories.
     *
     * @param string $sub Sub-directory (conversations, backups, secret...)
     * @return string The path (var/ai by default)
     */
    public function getStoragePath(string $sub = ''): string;

    /**
     * Returns the names of the configured AI connections.
     *
     * @return list<string> The names of the connections
     */
    public function getConnectionNames(): array;

    /**
     * Returns the name of the default connection: default_connection when it exists, else the first one.
     *
     * @return string The name of the default connection
     */
    public function getDefaultConnection(): string;

    /**
     * Tells whether an AI connection is configured.
     *
     * @param string $name Name of the connection
     * @return bool True when the connection is configured
     */
    public function hasConnection(string $name): bool;

    /**
     * Returns the provider of an AI connection, built on first use.
     *
     * @param string|null $name Name of the connection, the default connection when null
     * @return ProviderInterface The provider
     * @throws ConfigurationException When the connection is not configured, its provider is unknown or invalid, or its options are invalid
     * @throws SecurityException When the provider is remote and allow_remote is false
     */
    public function connection(?string $name = null): ProviderInterface;

    /**
     * Describes an AI connection without throwing.
     *
     * @param string|null $name Name of the connection, the default connection when null
     * @return array{connection: string, provider: string, model: string, remote: bool|null, error: string|null} The connection, its provider, its model, whether it is remote, and the configuration error
     */
    public function describe(?string $name = null): array;

    /**
     * Returns the redactor masking the secrets and the API keys in the data sent to the providers.
     *
     * @return Redactor The redactor
     */
    public function redactor(): Redactor;

    /**
     * Returns the sandbox limiting the files the assistant can read.
     *
     * @return Sandbox The sandbox
     */
    public function sandbox(): Sandbox;

    /**
     * Returns a builder of the prompts sent to the providers.
     *
     * @return PromptBuilder The prompt builder
     */
    public function prompts(): PromptBuilder;

    /**
     * Returns the runner of the read-only tools of the assistant (list, read, search files, project info, profiles, patch proposals).
     *
     * @return ToolRunner The tool runner
     */
    public function tools(): ToolRunner;

    /**
     * Returns a chat assistant using an AI connection and the tools.
     *
     * @param string|null $connection Name of the connection, the default connection when null
     * @return Assistant The assistant
     * @throws ConfigurationException When the connection is not configured or its provider is invalid
     * @throws SecurityException When the provider is remote and allow_remote is false
     */
    public function assistant(?string $connection = null): Assistant;

    /**
     * Returns a scanner auditing the project with an AI connection.
     *
     * @param string|null $connection Name of the connection, the default connection when null
     * @param array<string, mixed> $options Options replacing the scan section of the configuration
     * @return Scanner The scanner
     * @throws ConfigurationException When the connection is not configured or its provider is invalid
     * @throws SecurityException When the provider is remote and allow_remote is false
     */
    public function scanner(?string $connection = null, array $options = []): Scanner;

    /**
     * Returns the applier of the patches proposed by the assistant, with backups.
     *
     * @return PatchApplier The patch applier
     */
    public function patches(): PatchApplier;

    /**
     * Returns the storage of the web chat conversations.
     *
     * @return ConversationStorage The conversation storage
     */
    public function conversations(): ConversationStorage;

    /**
     * Returns the guard of the web chat requests (allowed IPs, request token).
     *
     * @return RequestGuard The request guard
     */
    public function guard(): RequestGuard;

    /**
     * Returns the information on the project given to the assistant: PHP and framework versions, environment, features, directories, routes and configuration.
     *
     * @return array<string, mixed> The project information
     */
    public function projectInfo(): array;

    /**
     * Loads a profile of the WebProfiler for the assistant.
     *
     * @param string $token Token of the profile
     * @return array{summary: array<string, mixed>, data: array<string, mixed>}|null The summary and the data of the profile, or null when it does not exist or the WebProfiler is not available
     */
    public function loadProfile(string $token): ?array;
}