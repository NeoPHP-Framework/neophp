<?php

namespace NeoPHP\Package\Orm\Contract;

interface TranslatableInterface
{
    public const TRANSLATIONS_FIELD = 'translations';

    /**
     * The class of the translation entity (the class name + "Translation" by default).
     */
    public static function getTranslationEntityClass(): string;

    public function getTranslations(): CollectionInterface;

    /**
     * Adds a translation and sets its translatable side; refuses a second translation for the same locale.
     */
    public function addTranslation(TranslationInterface $translation): static;

    public function removeTranslation(TranslationInterface $translation): static;

    /**
     * The translation of this exact locale, or null.
     */
    public function getTranslation(string $locale): ?TranslationInterface;

    /**
     * The translation of the locale, created and added when it does not exists.
     */
    public function getOrCreateTranslation(string $locale): TranslationInterface;

    /**
     * The translation of the locale (the current locale when null), then of its fallback locales, then the first one.
     */
    public function translate(?string $locale = null, bool $fallback = true): ?TranslationInterface;
}