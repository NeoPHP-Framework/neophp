<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation;

use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\Formatter\MessageFormatter;

interface TranslationManagerInterface
{
    public const DEFAULT_DOMAIN = 'messages';

    public const ATTRIBUTE = '_locale';

    /**
     * Translates a message, trying the locale, its parent locales and the fallback locales; a missing key is returned as written, with its parameters replaced.
     *
     * @param string $key Key of the message, or the English sentence itself
     * @param array<string, mixed> $parameters Values of the {name} and %name% placeholders, of the plural and select arguments
     * @param string|null $domain Domain of the message, the default domain when null
     * @param string|null $locale Locale of the message, the current locale when null
     * @return string The translated message
     * @throws TranslationException When a translation file cannot be loaded or the message has an invalid ICU syntax
     */
    public function translate(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string;

    /**
     * Tells whether a message is translated in a locale, its parent locales or the fallback locales.
     *
     * @param string $key Key of the message
     * @param string|null $domain Domain of the message, the default domain when null
     * @param string|null $locale Locale of the message, the current locale when null
     * @return bool True when the message is translated
     * @throws TranslationException When a translation file cannot be loaded
     */
    public function has(string $key, ?string $domain = null, ?string $locale = null): bool;

    /**
     * Returns the current locale.
     *
     * @return string The locale (en, fr_FR...)
     */
    public function getLocale(): string;

    /**
     * Changes the current locale to the enabled locale matching it (fr-FR gives fr when only fr is enabled).
     *
     * @param string $locale The locale or the language
     * @return static The translation manager
     * @throws TranslationException When no enabled locale matches
     */
    public function setLocale(string $locale): static;

    /**
     * Returns the locale used when nothing is detected.
     *
     * @return string The default locale
     */
    public function getDefaultLocale(): string;

    /**
     * Returns the enabled locales.
     *
     * @return list<string> The locales
     */
    public function getLocales(): array;

    /**
     * Returns the locales tried after a locale: its parent locales, then the fallback locales.
     *
     * @param string $locale The locale
     * @return list<string> The locales, in order (fr_CA gives fr, en)
     */
    public function getFallbackLocales(string $locale): array;

    /**
     * Returns the enabled locale matching a locale or a language.
     *
     * @param string $locale The locale or the language (fr-CH, fr, fr_FR...)
     * @return string|null The enabled locale, or null when none matches
     */
    public function matchLocale(string $locale): ?string;

    /**
     * Returns the domain used when none is given.
     *
     * @return string The default domain (messages)
     */
    public function getDefaultDomain(): string;

    /**
     * Returns the messages of a locale and a domain, without fallback.
     *
     * @param string $locale The locale
     * @param string|null $domain The domain, the default domain when null
     * @return array<string, string> The messages, by flattened key
     * @throws TranslationException When a translation file cannot be loaded
     */
    public function getCatalogue(string $locale, ?string $domain = null): array;

    /**
     * Returns the known domains.
     *
     * @param string|null $locale The locale, every locale when null
     * @return list<string> The domains, sorted
     * @throws TranslationException When a translation file cannot be loaded
     */
    public function getDomains(?string $locale = null): array;

    /**
     * Adds a translation file, with a lower priority than the files of translations/.
     *
     * @param string $file Path of the file, named {domain}.{locale}.{yaml|xlf} when the domain or the locale is not given
     * @param string|null $locale Locale of the file, guessed from its name when null
     * @param string|null $domain Domain of the file, guessed from its name when null
     * @return static The translation manager
     * @throws TranslationException When the file does not exist or its domain and locale cannot be guessed
     */
    public function addResource(string $file, ?string $locale = null, ?string $domain = null): static;

    /**
     * Adds messages at runtime, with the highest priority.
     *
     * @param array<string, mixed> $messages The messages, nested keys being flattened with dots
     * @param string $locale Locale of the messages
     * @param string|null $domain Domain of the messages, the default domain when null
     * @return static The translation manager
     */
    public function addMessages(array $messages, string $locale, ?string $domain = null): static;

    /**
     * Returns the translation files, sorted by priority.
     *
     * @param string|null $locale The locale, every locale when null
     * @return list<array<string, mixed>> The files: file, domain, locale, format and priority
     */
    public function getResources(?string $locale = null): array;

    /**
     * Loads the flat messages of a YAML or XLIFF file.
     *
     * @param string $file Path of the file
     * @return array<string, string> The messages, by flattened key
     * @throws TranslationException When the extension is not supported, the file cannot be read or parsed, or the dom extension is missing for XLIFF
     */
    public function loadFile(string $file): array;

    /**
     * Returns the directory of the translation files of the application.
     *
     * @return string The translations directory
     */
    public function getPath(): string;

    /**
     * Returns the configuration of translation.yaml with its defaults.
     *
     * @return array<string, mixed> The configuration
     */
    public function getConfig(): array;

    /**
     * Returns the ICU-lite message formatter.
     *
     * @return MessageFormatter The formatter
     */
    public function getFormatter(): MessageFormatter;

    /**
     * Removes the compiled catalogues from memory and from var/cache/translation/.
     *
     * @return void
     */
    public function clearCache(): void;
}