<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation;

use NeoPHP\Package\Translation\Formatter\MessageFormatter;

interface TranslationManagerInterface
{
    public const DEFAULT_DOMAIN = 'messages';

    public const ATTRIBUTE = '_locale';

    public function translate(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string;

    public function has(string $key, ?string $domain = null, ?string $locale = null): bool;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getDefaultLocale(): string;

    public function getLocales(): array;

    public function getFallbackLocales(string $locale): array;

    public function matchLocale(string $locale): ?string;

    public function getDefaultDomain(): string;

    public function getCatalogue(string $locale, ?string $domain = null): array;

    public function getDomains(?string $locale = null): array;

    public function addResource(string $file, ?string $locale = null, ?string $domain = null): static;

    public function addMessages(array $messages, string $locale, ?string $domain = null): static;

    public function getResources(?string $locale = null): array;

    public function loadFile(string $file): array;

    public function getPath(): string;

    public function getConfig(): array;

    public function getFormatter(): MessageFormatter;

    public function clearCache(): void;
}