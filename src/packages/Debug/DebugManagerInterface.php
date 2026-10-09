<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug;

use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Debug\Cloner\VarCloner;

interface DebugManagerInterface
{
    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): static;

    public function isCli(): bool;

    public function dump(mixed ...$values): void;

    public function dumpFrom(?string $location, array $values): void;

    public function dd(mixed ...$values): never;

    public function ddFrom(?string $location, array $values): never;

    public function toHtml(mixed $value, ?string $label = null, ?int $maxDepth = null): string;

    public function toText(mixed $value, ?string $label = null, bool $colors = false): string;

    public function hasPending(): bool;

    public function flush(bool $html = true): string;

    public function injectInto(Response $response): Response;

    public function getCloner(): VarCloner;
}