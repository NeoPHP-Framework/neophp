<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Contract\ProfilerElementInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch;
use Throwable;

interface WebProfilerManagerInterface
{
    public function isEnabled(): bool;

    public function enable(): static;

    public function disable(): static;

    public function isToolbarEnabled(): bool;

    public function getConfig(): array;

    public function getStorage(): ProfileStorageInterface;

    public function getStopwatch(): Stopwatch;

    public function getPath(): string;

    public function getToolbarPath(): string;

    public function setBasePath(string $basePath): static;

    public function getBasePath(): string;

    public function getPublicPath(): string;

    public function getProfileUrl(string $token, ?string $panel = null): string;

    public function getToolbarUrl(string $token): string;

    public function isAllowed(Request $request): bool;

    public function isExcluded(Request $request): bool;

    public function addElement(ProfilerElementInterface $element, ?int $priority = null): static;

    public function getElements(): array;

    public function getElement(string $name): ?ProfilerElementInterface;

    public function setException(?Throwable $exception): static;

    public function getException(): ?Throwable;

    public function collect(Request $request, Response $response, ?Throwable $exception = null): Profile;

    public function save(Profile $profile): void;

    public function load(string $token): ?Profile;

    public function find(int $limit = 50, array $filters = []): array;

    public function getToolbarItems(Profile $profile): array;

    public function getToolbarAssets(Profile $profile): array;

    public function getPanels(Profile $profile): array;
}