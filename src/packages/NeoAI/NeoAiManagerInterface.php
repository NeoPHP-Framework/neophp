<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI;

use NeoPHP\Package\NeoAI\Agent\Assistant;
use NeoPHP\Package\NeoAI\Agent\PromptBuilder;
use NeoPHP\Package\NeoAI\Contract\ProviderInterface;
use NeoPHP\Package\NeoAI\Patch\PatchApplier;
use NeoPHP\Package\NeoAI\Scan\Scanner;
use NeoPHP\Package\NeoAI\Security\Redactor;
use NeoPHP\Package\NeoAI\Security\RequestGuard;
use NeoPHP\Package\NeoAI\Security\Sandbox;
use NeoPHP\Package\NeoAI\Storage\ConversationStorage;
use NeoPHP\Package\NeoAI\Tool\ToolRunner;

interface NeoAiManagerInterface
{
    public function getConfig(): array;

    public function isEnabled(): bool;

    public function isWebEnabled(): bool;

    public function getWebPath(): string;

    public function getRoot(): string;

    public function getStoragePath(string $sub = ''): string;

    public function getConnectionNames(): array;

    public function getDefaultConnection(): string;

    public function hasConnection(string $name): bool;

    public function connection(?string $name = null): ProviderInterface;

    public function describe(?string $name = null): array;

    public function redactor(): Redactor;

    public function sandbox(): Sandbox;

    public function prompts(): PromptBuilder;

    public function tools(): ToolRunner;

    public function assistant(?string $connection = null): Assistant;

    public function scanner(?string $connection = null, array $options = []): Scanner;

    public function patches(): PatchApplier;

    public function conversations(): ConversationStorage;

    public function guard(): RequestGuard;

    public function projectInfo(): array;

    public function loadProfile(string $token): ?array;
}