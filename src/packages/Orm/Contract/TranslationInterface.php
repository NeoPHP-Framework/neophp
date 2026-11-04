<?php

namespace NeoPHP\Package\Orm\Contract;

interface TranslationInterface
{
    public const TRANSLATABLE_FIELD = 'translatable';
    public const LOCALE_FIELD = 'locale';

    /**
     * The class of the translated entity (the class name without "Translation" by defaut).
     */
    public static function getTranslatableEntityClass(): string;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getTranslatable(): ?TranslatableInterface;

    public function setTranslatable(?TranslatableInterface $translatable): static;
}